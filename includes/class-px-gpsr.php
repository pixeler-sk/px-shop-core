<?php
/**
 * GPSR (General Product Safety Regulation) product data.
 *
 * Adds a "Product safety (GPSR)" tab to the product data metabox in admin
 * and a "Safety & compliance" tab on the single product page whenever at
 * least one field is filled. Markup is neutral (px-* classes); styling
 * belongs to the theme.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_GPSR {

	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'admin_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'admin_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
		add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'frontend_tab' ), 25 );

		// Manufacturer data belongs to the brand, not to every single product.
		add_action( 'init', array( __CLASS__, 'register_brand_meta' ) );
		add_action( 'product_brand_add_form_fields', array( __CLASS__, 'brand_add_fields' ) );
		add_action( 'product_brand_edit_form_fields', array( __CLASS__, 'brand_edit_fields' ) );
		add_action( 'created_product_brand', array( __CLASS__, 'save_brand' ) );
		add_action( 'edited_product_brand', array( __CLASS__, 'save_brand' ) );
	}

	/**
	 * Field definitions: meta_key => [ label, type ].
	 */
	public static function fields() {
		// Filterable, so a shop that keeps one of these elsewhere can drop it
		// instead of asking editors to fill the same thing twice.
		return (array) apply_filters( 'px_gpsr_fields', array(
			'_px_gpsr_manufacturer'   => array( __( 'Manufacturer', 'px-shop-core' ), 'textarea' ),
			'_px_gpsr_eu_responsible' => array( __( 'EU responsible person', 'px-shop-core' ), 'textarea' ),
			'_px_gpsr_origin'         => array( __( 'Country of origin', 'px-shop-core' ), 'country' ),
			'_px_gpsr_warnings'       => array( __( 'Safety warnings', 'px-shop-core' ), 'textarea' ),
			'_px_gpsr_docs_url'       => array( __( 'Safety documentation', 'px-shop-core' ), 'url' ),
		) );
	}

	/**
	 * Fields that describe a legal entity, not the product itself - the same
	 * name, address and contact repeats across every product of the brand.
	 *
	 * Each value has exactly one home: these live on the product_brand term,
	 * the rest on the product. There is deliberately no product-level override
	 * - a product with a different manufacturer gets a different brand, so
	 * nobody has to work out which of two places is the one that counts.
	 *
	 * @return string[] Product meta keys.
	 */
	public static function entity_fields() {
		return (array) apply_filters( 'px_gpsr_entity_fields', array(
			'_px_gpsr_manufacturer',
			'_px_gpsr_eu_responsible',
		) );
	}

	/**
	 * Term meta key for a product meta key - term meta is not hidden, so the
	 * leading underscore goes away (px_gpsr_manufacturer).
	 */
	public static function brand_meta_key( $meta_key ) {
		return ltrim( (string) $meta_key, '_' );
	}

	public static function admin_tab( $tabs ) {
		$tabs['px_gpsr'] = array(
			'label'    => __( 'Product safety (GPSR)', 'px-shop-core' ),
			'target'   => 'px_gpsr_product_data',
			'class'    => array(),
			'priority' => 65,
		);
		return $tabs;
	}

	public static function admin_panel() {
		global $post;
		?>
		<div id="px_gpsr_product_data" class="panel woocommerce_options_panel">
			<div class="options_group">
				<?php
				$entity = self::entity_fields();

				foreach ( self::fields() as $meta_key => $field ) {
					list( $label, $type ) = $field;

					// Manufacturer and EU responsible person belong to the brand
					// and are never edited here - only shown, so the editor sees
					// what the product will display.
					if ( in_array( $meta_key, $entity, true ) ) {
						self::admin_read_only_field( $label, self::brand_source( $post->ID, $meta_key ) );
						continue;
					}

					$args = array(
						'id'    => $meta_key,
						'label' => $label,
						'value' => get_post_meta( $post->ID, $meta_key, true ),
					);

					if ( 'textarea' === $type ) {
						woocommerce_wp_textarea_input( $args );
					} elseif ( 'country' === $type ) {
						$args['options'] = array( '' => '&mdash;' ) + WC()->countries->get_countries();
						woocommerce_wp_select( $args );
					} else {
						$args['type'] = 'url' === $type ? 'url' : 'text';
						woocommerce_wp_text_input( $args );
					}
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Read-only row showing what the product takes from its brand, and where
	 * that is edited.
	 *
	 * @param string     $label  Field label.
	 * @param array|null $source [ 'value', 'note', 'edit_url' ] or null when
	 *                           the brand has nothing filled yet.
	 */
	protected static function admin_read_only_field( $label, $source ) {
		?>
		<p class="form-field px-gpsr-inherited">
			<label><?php echo esc_html( $label ); ?></label>
			<span class="px-gpsr-inherited__value">
				<?php if ( $source ) : ?>
					<?php echo nl2br( esc_html( $source['value'] ) ); ?>
					<span class="description">
						<?php echo esc_html( $source['note'] ); ?>
						<?php if ( ! empty( $source['edit_url'] ) ) : ?>
							<a href="<?php echo esc_url( $source['edit_url'] ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Edit source', 'px-shop-core' ); ?>
							</a>
						<?php endif; ?>
					</span>
				<?php else : ?>
					<span class="description">
						<?php esc_html_e( 'Filled in on the product brand (Products → Brands) - it is then used by every product of that brand.', 'px-shop-core' ); ?>
					</span>
				<?php endif; ?>
			</span>
		</p>
		<?php
	}

	public static function save( $product ) {
		$entity = self::entity_fields();

		foreach ( self::fields() as $meta_key => $field ) {
			// Brand fields have no input here - nothing to save, and nothing
			// may sneak in through a crafted request either.
			if ( in_array( $meta_key, $entity, true ) ) {
				continue;
			}

			if ( ! isset( $_POST[ $meta_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				continue;
			}
			$raw = wp_unslash( $_POST[ $meta_key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( 'url' === $field[1] ) {
				$value = esc_url_raw( $raw );
			} elseif ( 'textarea' === $field[1] ) {
				$value = sanitize_textarea_field( $raw );
			} else {
				$value = sanitize_text_field( $raw );
			}

			if ( '' === $value ) {
				$product->delete_meta_data( $meta_key );
			} else {
				$product->update_meta_data( $meta_key, $value );
			}
		}
	}

	public static function get_data( $product ) {
		if ( ! $product ) {
			return array();
		}

		$entity = self::entity_fields();

		$data = array();
		foreach ( self::fields() as $meta_key => $field ) {
			// Brand fields come from the brand term, everything else from the
			// product. One home per value - product meta of a brand field is
			// not consulted even when some import left something there.
			if ( in_array( $meta_key, $entity, true ) ) {
				$source = self::brand_source( $product->get_id(), $meta_key );
				$value  = $source ? $source['value'] : '';
			} else {
				$value = $product->get_meta( $meta_key );
			}

			if ( '' === trim( (string) $value ) ) {
				continue;
			}

			// Country of origin is stored as an ISO code - display the
			// localized country name. Legacy free-text values pass through.
			if ( 'country' === $field[1] ) {
				$countries = WC()->countries->get_countries();
				$value     = isset( $countries[ $value ] ) ? $countries[ $value ] : $value;
			}

			$data[ $meta_key ] = array(
				'label' => $field[0],
				'type'  => $field[1],
				'value' => $value,
			);
		}
		return $data;
	}

	/**
	 * Brand term the product inherits a field from, with the value. First term
	 * that has the field filled wins - a product with several brands is an edge
	 * case, and showing one manufacturer beats showing none.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $meta_key   Product meta key.
	 * @return array|null [ 'value', 'note', 'edit_url' ] or null.
	 */
	protected static function brand_source( $product_id, $meta_key ) {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			return null;
		}

		$terms = get_the_terms( $product_id, 'product_brand' );

		if ( ! $terms || is_wp_error( $terms ) ) {
			return null;
		}

		foreach ( $terms as $term ) {
			$value = get_term_meta( $term->term_id, self::brand_meta_key( $meta_key ), true );

			if ( '' !== trim( (string) $value ) ) {
				return array(
					'value'    => (string) $value,
					/* translators: %s: brand name. */
					'note'     => sprintf( __( 'Taken from brand %s.', 'px-shop-core' ), $term->name ),
					'edit_url' => get_edit_term_link( $term->term_id, 'product_brand' ),
				);
			}
		}

		return null;
	}

	/* --------------------------- Brand term fields --------------------------- */

	/**
	 * Term meta is public (REST, WP-CLI) - the value is sanitized on write
	 * whichever way it arrives.
	 */
	public static function register_brand_meta() {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			return;
		}

		$fields = self::fields();

		foreach ( self::entity_fields() as $meta_key ) {
			if ( ! isset( $fields[ $meta_key ] ) ) {
				continue;
			}

			register_term_meta( 'product_brand', self::brand_meta_key( $meta_key ), array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'description'       => $fields[ $meta_key ][0],
				'sanitize_callback' => 'sanitize_textarea_field',
				'auth_callback'     => function () {
					return current_user_can( 'manage_product_terms' );
				},
			) );
		}
	}

	public static function brand_add_fields() {
		wp_nonce_field( 'px_gpsr_brand', 'px_gpsr_brand_nonce' );

		$fields = self::fields();

		foreach ( self::entity_fields() as $meta_key ) {
			if ( ! isset( $fields[ $meta_key ] ) ) {
				continue;
			}
			?>
			<div class="form-field">
				<label for="<?php echo esc_attr( self::brand_meta_key( $meta_key ) ); ?>">
					<?php echo esc_html( $fields[ $meta_key ][0] ); ?>
				</label>
				<textarea name="<?php echo esc_attr( self::brand_meta_key( $meta_key ) ); ?>"
					id="<?php echo esc_attr( self::brand_meta_key( $meta_key ) ); ?>" rows="4"></textarea>
			</div>
			<?php
		}

		self::brand_fields_hint();
	}

	public static function brand_edit_fields( $term ) {
		wp_nonce_field( 'px_gpsr_brand', 'px_gpsr_brand_nonce' );

		$fields = self::fields();

		foreach ( self::entity_fields() as $meta_key ) {
			if ( ! isset( $fields[ $meta_key ] ) ) {
				continue;
			}

			$key   = self::brand_meta_key( $meta_key );
			$value = $term instanceof WP_Term ? (string) get_term_meta( $term->term_id, $key, true ) : '';
			?>
			<tr class="form-field">
				<th scope="row">
					<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $fields[ $meta_key ][0] ); ?></label>
				</th>
				<td>
					<textarea name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>" rows="4" cols="50"><?php echo esc_textarea( $value ); ?></textarea>
				</td>
			</tr>
			<?php
		}
		?>
		<tr>
			<th scope="row"></th>
			<td><?php self::brand_fields_hint(); ?></td>
		</tr>
		<?php
	}

	protected static function brand_fields_hint() {
		?>
		<p class="description">
			<?php esc_html_e( 'Filled in once here and shown on every product of this brand. A product overrides it only by filling the same field in its own Product safety (GPSR) tab.', 'px-shop-core' ); ?>
		</p>
		<?php
	}

	public static function save_brand( $term_id ) {
		// Without the fields in the form (quick edit, import, other code) the
		// values must not be wiped - hence the nonce presence check, not value.
		if ( ! isset( $_POST['px_gpsr_brand_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['px_gpsr_brand_nonce'] ) ), 'px_gpsr_brand' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}

		$fields = self::fields();

		foreach ( self::entity_fields() as $meta_key ) {
			if ( ! isset( $fields[ $meta_key ] ) ) {
				continue;
			}

			$key = self::brand_meta_key( $meta_key );

			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			$value = sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) );

			if ( '' === $value ) {
				delete_term_meta( $term_id, $key );
				continue;
			}

			update_term_meta( $term_id, $key, $value );
		}
	}

	public static function frontend_tab( $tabs ) {
		global $product;

		if ( empty( self::get_data( $product ) ) ) {
			return $tabs;
		}

		$tabs['px_gpsr'] = array(
			'title'    => __( 'Safety & compliance', 'px-shop-core' ),
			'priority' => 28,
			'callback' => array( __CLASS__, 'render_tab' ),
		);
		return $tabs;
	}

	public static function render_tab() {
		global $product;
		?>
		<div class="px-gpsr">
			<?php foreach ( self::get_data( $product ) as $row ) : ?>
				<div class="px-gpsr__row">
					<h3 class="px-gpsr__label"><?php echo esc_html( $row['label'] ); ?></h3>
					<?php if ( 'url' === $row['type'] ) : ?>
						<a class="px-gpsr__link" href="<?php echo esc_url( $row['value'] ); ?>" target="_blank" rel="noopener">
							<?php echo esc_html( $row['value'] ); ?>
						</a>
					<?php else : ?>
						<div class="px-gpsr__value"><?php echo wp_kses_post( wpautop( $row['value'] ) ); ?></div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
