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
	}

	/**
	 * Field definitions: meta_key => [ label, type ].
	 */
	public static function fields() {
		return array(
			'_px_gpsr_manufacturer'   => array( __( 'Manufacturer', 'px-shop-core' ), 'textarea' ),
			'_px_gpsr_eu_responsible' => array( __( 'EU responsible person', 'px-shop-core' ), 'textarea' ),
			'_px_gpsr_origin'         => array( __( 'Country of origin', 'px-shop-core' ), 'country' ),
			'_px_gpsr_warnings'       => array( __( 'Safety warnings', 'px-shop-core' ), 'textarea' ),
			'_px_gpsr_docs_url'       => array( __( 'Safety documentation', 'px-shop-core' ), 'url' ),
		);
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
				foreach ( self::fields() as $meta_key => $field ) {
					list( $label, $type ) = $field;
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

	public static function save( $product ) {
		foreach ( self::fields() as $meta_key => $field ) {
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

		$data = array();
		foreach ( self::fields() as $meta_key => $field ) {
			$value = $product->get_meta( $meta_key );
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
