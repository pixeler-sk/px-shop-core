<?php
/**
 * Size guides.
 *
 * A size guide is a small post type holding a table (and optional free
 * content) shown on the product page — as a modal behind a link
 * (get_html()) or inline where the theme wants it (get_content_html()).
 * Products either point at a guide themselves or inherit one from their
 * product category.
 *
 * Storage:
 *   post   px_size_guide            the guide itself
 *   meta   _px_size_guide_table     array of rows, each row an array of cells
 *   meta   _px_size_guide_hide_table 'yes' hides the table (content only)
 *   meta   _px_size_guide           on a product: guide ID or 'off'
 *   term   _px_size_guide           on a product_cat: guide ID
 *
 * Opt-in: without a single guide the class registers nothing on the front end.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Size_Guide {

	const POST_TYPE  = 'px_size_guide';
	const META_TABLE = '_px_size_guide_table';
	const META_HIDE  = '_px_size_guide_hide_table';
	const META_LINK  = '_px_size_guide';

	/** Value of META_LINK that hides the guide on a product. */
	const OFF = 'off';

	/** Products already rendered in this request, so a theme calling render() cannot double up. */
	protected static $rendered = array();

	/** Whether the inline assets were printed. */
	protected static $assets_done = false;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );

		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( __CLASS__, 'register_metaboxes' ) );
			add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_guide' ) );
			add_action( 'save_post_product', array( __CLASS__, 'save_product' ) );
			add_action( 'product_cat_add_form_fields', array( __CLASS__, 'term_add_field' ) );
			add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'term_edit_field' ) );
			add_action( 'created_product_cat', array( __CLASS__, 'save_term' ) );
			add_action( 'edited_product_cat', array( __CLASS__, 'save_term' ) );
		}

		// Themes that fire the stock WooCommerce summary hook get the button
		// for free. A theme with a place of its own (px-shop-theme puts the
		// guide in a product tab) unhooks this and calls get_content_html().
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render' ), 38 );
	}

	/* ----------------------------- Post type ----------------------------- */

	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Size guides', 'px-shop-core' ),
					'singular_name'      => __( 'Size guide', 'px-shop-core' ),
					'add_new_item'       => __( 'Add size guide', 'px-shop-core' ),
					'edit_item'          => __( 'Edit size guide', 'px-shop-core' ),
					'search_items'       => __( 'Search size guides', 'px-shop-core' ),
					'not_found'          => __( 'No size guides yet.', 'px-shop-core' ),
					'not_found_in_trash' => __( 'No size guides in the trash.', 'px-shop-core' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=product',
				'supports'        => array( 'title', 'editor' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * All published guides, newest first.
	 *
	 * @return WP_Post[]
	 */
	public static function get_guides() {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/* ----------------------------- Resolution ---------------------------- */

	/**
	 * ID of the guide that applies to a product (0 when none).
	 *
	 * Own assignment wins; 'off' hides the guide even when the category has
	 * one; otherwise the first product category with a guide decides.
	 *
	 * @param int $product_id Product ID.
	 * @return int
	 */
	public static function get_id_for_product( $product_id ) {
		$own = get_post_meta( $product_id, self::META_LINK, true );

		if ( '' === $own ) {
			$own = self::legacy_product_value( $product_id );
		}

		if ( self::OFF === $own ) {
			return 0;
		}

		if ( $own > 0 ) {
			return (int) $own;
		}

		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return 0;
		}

		foreach ( $terms as $term_id ) {
			$guide_id = self::get_id_for_term( $term_id );
			if ( $guide_id ) {
				return $guide_id;
			}
		}

		return 0;
	}

	/**
	 * ID of the guide assigned to a product category (0 when none).
	 *
	 * @param int $term_id Term ID.
	 * @return int
	 */
	public static function get_id_for_term( $term_id ) {
		$guide_id = get_term_meta( $term_id, self::META_LINK, true );

		if ( '' === $guide_id ) {
			$guide_id = self::legacy_term_value( $term_id );
		}

		return (int) $guide_id;
	}

	/**
	 * Title, content and table of a guide, or null when it cannot be shown.
	 *
	 * @param int $guide_id Guide post ID.
	 * @return array|null { title, content, table, hide_table }
	 */
	public static function get_guide( $guide_id ) {
		$post = get_post( $guide_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}

		if ( self::POST_TYPE === $post->post_type ) {
			$table = get_post_meta( $post->ID, self::META_TABLE, true );
			$hide  = 'yes' === get_post_meta( $post->ID, self::META_HIDE, true );
		} else {
			$legacy = self::legacy_guide( $post );
			if ( null === $legacy ) {
				return null;
			}
			$table = $legacy['table'];
			$hide  = $legacy['hide_table'];
		}

		$table   = self::normalize_table( $table );
		$content = trim( $post->post_content );

		if ( ( $hide || ! $table ) && '' === $content ) {
			return null; // Nothing to show.
		}

		return array(
			'id'         => $post->ID,
			'title'      => get_the_title( $post ),
			'content'    => $content,
			'table'      => $table,
			'hide_table' => $hide,
		);
	}

	/**
	 * Rows as a clean list of lists of strings.
	 *
	 * @param mixed $table Stored table.
	 * @return array
	 */
	protected static function normalize_table( $table ) {
		if ( ! is_array( $table ) ) {
			return array();
		}

		$rows = array();
		foreach ( $table as $row ) {
			$cells = array();
			foreach ( (array) $row as $cell ) {
				$cells[] = is_scalar( $cell ) ? trim( (string) $cell ) : '';
			}
			if ( array_filter( $cells, 'strlen' ) ) {
				$rows[] = $cells;
			}
		}

		return $rows;
	}

	/* ------------------------------ Front end ---------------------------- */

	/**
	 * Button and modal for a product. Safe to call more than once.
	 *
	 * @param int|WC_Product|null $product Product, ID, or null for the global one.
	 */
	public static function render( $product = null ) {
		echo self::get_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Guide that applies to a product, or null when there is nothing to show.
	 *
	 * Lets a theme ask before it registers a tab or a panel, without the
	 * render guard below marking the product as done.
	 *
	 * @param int|WC_Product|null $product Product, ID, or null for the global one.
	 * @return array|null
	 */
	public static function get_guide_for_product( $product = null ) {
		$product_id = self::resolve_product_id( $product );
		if ( ! $product_id ) {
			return null;
		}

		return self::get_guide( self::get_id_for_product( $product_id ) );
	}

	/**
	 * Whether a product has a guide worth showing.
	 *
	 * @param int|WC_Product|null $product Product, ID, or null for the global one.
	 * @return bool
	 */
	public static function has_guide( $product = null ) {
		return null !== self::get_guide_for_product( $product );
	}

	/**
	 * Button and modal markup ('' when the product has no guide or it was
	 * already rendered in this request).
	 *
	 * @param int|WC_Product|null $product Product, ID, or null for the global one.
	 * @return string
	 */
	public static function get_html( $product = null ) {
		$guide = self::claim( $product );
		if ( ! $guide ) {
			return '';
		}

		$modal_id = 'px-size-guide-' . $guide['id'];
		$label    = apply_filters( 'px_size_guide_button_text', __( 'Size guide', 'px-shop-core' ), $guide );

		ob_start();
		echo self::assets(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div class="px-size-guide">
			<button type="button" class="px-size-guide__open" data-px-size-guide="<?php echo esc_attr( $modal_id ); ?>">
				<span class="px-size-guide__icon" aria-hidden="true"></span>
				<span class="px-size-guide__label"><?php echo esc_html( $label ); ?></span>
			</button>

			<dialog id="<?php echo esc_attr( $modal_id ); ?>" class="px-size-guide__modal" aria-label="<?php echo esc_attr( $guide['title'] ); ?>">
				<div class="px-size-guide__box">
					<button type="button" class="px-size-guide__close" aria-label="<?php esc_attr_e( 'Close', 'px-shop-core' ); ?>">&times;</button>
					<h2 class="px-size-guide__title"><?php echo esc_html( $guide['title'] ); ?></h2>
					<?php echo self::body_html( $guide ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</dialog>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * The guide itself — content and table, no button and no modal.
	 *
	 * For themes that give the guide a place of its own (a product tab, an
	 * accordion panel) instead of a link to a dialog. Styling belongs to the
	 * theme: unlike get_html() this prints no inline assets.
	 *
	 * @param int|WC_Product|null $product Product, ID, or null for the global one.
	 * @return string
	 */
	public static function get_content_html( $product = null ) {
		$guide = self::claim( $product );
		if ( ! $guide ) {
			return '';
		}

		return '<div class="px-size-guide-inline">' . self::body_html( $guide ) . '</div>';
	}

	/**
	 * The guide of a product, reserved for one output per request.
	 *
	 * Returns null the second time it is asked for the same product, so a
	 * theme calling render() next to the summary hook cannot double up.
	 *
	 * @param int|WC_Product|null $product Product, ID, or null for the global one.
	 * @return array|null
	 */
	protected static function claim( $product ) {
		$product_id = self::resolve_product_id( $product );
		if ( ! $product_id || isset( self::$rendered[ $product_id ] ) ) {
			return null;
		}

		$guide = self::get_guide( self::get_id_for_product( $product_id ) );
		if ( ! $guide ) {
			return null;
		}

		self::$rendered[ $product_id ] = true;

		return $guide;
	}

	/**
	 * Content and table of a guide (shared by the modal and the inline output).
	 *
	 * @param array $guide Guide as returned by get_guide().
	 * @return string
	 */
	protected static function body_html( $guide ) {
		ob_start();

		if ( '' !== $guide['content'] ) {
			echo '<div class="px-size-guide__content">' . wp_kses_post( wpautop( $guide['content'] ) ) . '</div>';
		}

		if ( ! $guide['hide_table'] && $guide['table'] ) {
			?>
			<div class="px-size-guide__scroll">
				<table class="px-size-guide__table">
					<?php foreach ( $guide['table'] as $index => $row ) : ?>
						<tr>
							<?php foreach ( $row as $cell ) : ?>
								<?php if ( 0 === $index ) : ?>
									<th scope="col"><?php echo esc_html( $cell ); ?></th>
								<?php else : ?>
									<td><?php echo esc_html( $cell ); ?></td>
								<?php endif; ?>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</table>
			</div>
			<?php
		}

		return ob_get_clean();
	}

	/**
	 * @param int|WC_Product|null $given Product, ID, or null.
	 * @return int
	 */
	protected static function resolve_product_id( $given ) {
		if ( $given instanceof WC_Product ) {
			return $given->get_id();
		}
		if ( is_numeric( $given ) ) {
			return (int) $given;
		}

		global $product;
		return $product instanceof WC_Product ? $product->get_id() : (int) get_the_ID();
	}

	/**
	 * Inline styles and script, printed once per request.
	 *
	 * @return string
	 */
	protected static function assets() {
		if ( self::$assets_done ) {
			return '';
		}
		self::$assets_done = true;

		$css = '
.px-size-guide__open{display:inline-flex;align-items:center;gap:.4em;background:none;border:0;padding:0;font:inherit;color:inherit;cursor:pointer;text-decoration:underline;text-underline-offset:.2em}
.px-size-guide__icon{width:1em;height:1em;flex:none;border:1px solid currentColor;border-radius:2px;position:relative}
.px-size-guide__icon::before{content:"";position:absolute;left:.15em;right:.15em;top:50%;border-top:1px solid currentColor}
.px-size-guide__modal{width:min(46rem,92vw);max-height:86vh;padding:0;border:0;border-radius:.5rem;background:#fff;color:inherit}
.px-size-guide__modal::backdrop{background:rgba(0,0,0,.45)}
.px-size-guide__box{position:relative;padding:1.75rem;overflow:auto;max-height:86vh}
.px-size-guide__close{position:absolute;top:.5rem;right:.75rem;background:none;border:0;font-size:1.75rem;line-height:1;cursor:pointer;color:inherit}
.px-size-guide__title{margin:0 2rem .75rem 0;font-size:1.25rem}
.px-size-guide__content{margin-bottom:1rem}
.px-size-guide__scroll{overflow-x:auto}
.px-size-guide__table{width:100%;border-collapse:collapse}
.px-size-guide__table th,.px-size-guide__table td{padding:.45rem .7rem;border:1px solid rgba(0,0,0,.12);text-align:left;white-space:nowrap}
.px-size-guide__table th{background:rgba(0,0,0,.04);font-weight:600}
.px-size-guide__table tr:nth-child(even) td{background:rgba(0,0,0,.02)}
';

		$js = '
document.addEventListener("click",function(e){
var o=e.target.closest("[data-px-size-guide]");
if(o){e.preventDefault();var d=document.getElementById(o.getAttribute("data-px-size-guide"));if(d&&d.showModal){d.showModal();}return;}
var c=e.target.closest(".px-size-guide__close");
if(c){var m=c.closest("dialog");if(m){m.close();}return;}
if(e.target.classList&&e.target.classList.contains("px-size-guide__modal")){e.target.close();}
});
';

		return '<style id="px-size-guide-css">' . $css . '</style><script id="px-size-guide-js">' . $js . '</script>';
	}

	/* ------------------------------- Admin ------------------------------- */

	public static function register_metaboxes() {
		add_meta_box(
			'px-size-guide-table',
			__( 'Size table', 'px-shop-core' ),
			array( __CLASS__, 'guide_metabox' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'px-size-guide-product',
			__( 'Size guide', 'px-shop-core' ),
			array( __CLASS__, 'product_metabox' ),
			'product',
			'side'
		);
	}

	/**
	 * Table editor: one row per line, cells separated by a pipe or a tab
	 * (so a table pasted from a spreadsheet lands correctly).
	 *
	 * @param WP_Post $post Guide post.
	 */
	public static function guide_metabox( $post ) {
		$table = self::normalize_table( get_post_meta( $post->ID, self::META_TABLE, true ) );
		$hide  = 'yes' === get_post_meta( $post->ID, self::META_HIDE, true );

		$lines = array();
		foreach ( $table as $row ) {
			$lines[] = implode( ' | ', $row );
		}

		wp_nonce_field( 'px_size_guide_save', 'px_size_guide_nonce' );
		?>
		<p class="description">
			<?php esc_html_e( 'One row per line, cells separated by | (a table pasted from a spreadsheet works too). The first row is the header.', 'px-shop-core' ); ?>
		</p>
		<textarea name="px_size_guide_table" rows="12" style="width:100%;font-family:monospace;" spellcheck="false"><?php echo esc_textarea( implode( "\n", $lines ) ); ?></textarea>

		<p>
			<label>
				<input type="checkbox" name="px_size_guide_hide_table" value="yes" <?php checked( $hide ); ?> />
				<?php esc_html_e( 'Hide the table and show only the content above', 'px-shop-core' ); ?>
			</label>
		</p>

		<?php
		$terms = self::get_terms_for_guide( $post->ID );
		if ( $terms ) {
			?>
			<p>
				<strong><?php esc_html_e( 'Assigned categories:', 'px-shop-core' ); ?></strong>
				<?php
				$links = array();
				foreach ( $terms as $term ) {
					$links[] = '<a href="' . esc_url( get_edit_term_link( $term->term_id, 'product_cat' ) ) . '">' . esc_html( $term->name ) . '</a>';
				}
				echo wp_kses_post( implode( ', ', $links ) );
				?>
			</p>
			<?php
		} else {
			?>
			<p class="description">
				<?php esc_html_e( 'Not assigned to any category yet - a guide is picked on the product, or on the product category screen.', 'px-shop-core' ); ?>
			</p>
			<?php
		}
	}

	/**
	 * Product categories pointing at a guide.
	 *
	 * @param int $guide_id Guide ID.
	 * @return WP_Term[]
	 */
	public static function get_terms_for_guide( $guide_id ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_LINK,
						'value' => (string) $guide_id,
					),
				),
			)
		);

		return is_wp_error( $terms ) ? array() : $terms;
	}

	public static function save_guide( $post_id ) {
		if ( ! isset( $_POST['px_size_guide_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['px_size_guide_nonce'] ), 'px_size_guide_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw   = isset( $_POST['px_size_guide_table'] ) ? wp_unslash( $_POST['px_size_guide_table'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$table = self::parse_table( $raw );

		if ( $table ) {
			update_post_meta( $post_id, self::META_TABLE, $table );
		} else {
			delete_post_meta( $post_id, self::META_TABLE );
		}

		if ( isset( $_POST['px_size_guide_hide_table'] ) ) {
			update_post_meta( $post_id, self::META_HIDE, 'yes' );
		} else {
			delete_post_meta( $post_id, self::META_HIDE );
		}
	}

	/**
	 * Textarea to rows.
	 *
	 * @param string $raw Textarea contents.
	 * @return array
	 */
	public static function parse_table( $raw ) {
		$rows = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = array_map(
				function ( $cell ) {
					return sanitize_text_field( trim( $cell ) );
				},
				preg_split( '/\t|\|/', $line )
			);
			$rows[] = array_values( $cells );
		}

		return self::normalize_table( $rows );
	}

	/**
	 * Guide picker on the product screen.
	 *
	 * @param WP_Post $post Product post.
	 */
	public static function product_metabox( $post ) {
		$current = get_post_meta( $post->ID, self::META_LINK, true );
		$guides  = self::get_guides();

		wp_nonce_field( 'px_size_guide_product_save', 'px_size_guide_product_nonce' );

		if ( ! $guides ) {
			?>
			<p class="description"><?php esc_html_e( 'No size guides yet.', 'px-shop-core' ); ?></p>
			<?php
			return;
		}
		?>
		<select name="px_size_guide" style="width:100%;">
			<option value=""><?php esc_html_e( '— inherit from category —', 'px-shop-core' ); ?></option>
			<?php foreach ( $guides as $guide ) : ?>
				<option value="<?php echo esc_attr( $guide->ID ); ?>" <?php selected( (string) $guide->ID, (string) $current ); ?>>
					<?php echo esc_html( get_the_title( $guide ) ); ?>
				</option>
			<?php endforeach; ?>
			<option value="<?php echo esc_attr( self::OFF ); ?>" <?php selected( self::OFF, $current ); ?>>
				<?php esc_html_e( '— do not show —', 'px-shop-core' ); ?>
			</option>
		</select>
		<?php
		self::legacy_product_hint( $post->ID, $current );
	}

	public static function save_product( $post_id ) {
		if ( ! isset( $_POST['px_size_guide_product_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['px_size_guide_product_nonce'] ), 'px_size_guide_product_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		self::store_link( 'post', $post_id, isset( $_POST['px_size_guide'] ) ? sanitize_text_field( wp_unslash( $_POST['px_size_guide'] ) ) : '' );
	}

	public static function term_add_field() {
		$guides = self::get_guides();
		if ( ! $guides ) {
			return;
		}
		?>
		<div class="form-field">
			<label for="px_size_guide"><?php esc_html_e( 'Size guide', 'px-shop-core' ); ?></label>
			<?php self::term_select( 0, $guides ); ?>
			<p><?php esc_html_e( 'Used by products in this category that do not pick a guide themselves.', 'px-shop-core' ); ?></p>
		</div>
		<?php
	}

	/**
	 * @param WP_Term $term Term being edited.
	 */
	public static function term_edit_field( $term ) {
		$guides = self::get_guides();
		if ( ! $guides ) {
			return;
		}
		?>
		<tr class="form-field">
			<th scope="row"><label for="px_size_guide"><?php esc_html_e( 'Size guide', 'px-shop-core' ); ?></label></th>
			<td>
				<?php self::term_select( $term->term_id, $guides ); ?>
				<p class="description"><?php esc_html_e( 'Used by products in this category that do not pick a guide themselves.', 'px-shop-core' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param int       $term_id Term ID (0 on the add form).
	 * @param WP_Post[] $guides  Available guides.
	 */
	protected static function term_select( $term_id, $guides ) {
		$current = $term_id ? get_term_meta( $term_id, self::META_LINK, true ) : '';

		wp_nonce_field( 'px_size_guide_term_save', 'px_size_guide_term_nonce' );
		?>
		<select name="px_size_guide" id="px_size_guide">
			<option value=""><?php esc_html_e( '— none —', 'px-shop-core' ); ?></option>
			<?php foreach ( $guides as $guide ) : ?>
				<option value="<?php echo esc_attr( $guide->ID ); ?>" <?php selected( (string) $guide->ID, (string) $current ); ?>>
					<?php echo esc_html( get_the_title( $guide ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public static function save_term( $term_id ) {
		if ( ! isset( $_POST['px_size_guide_term_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['px_size_guide_term_nonce'] ), 'px_size_guide_term_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}

		self::store_link( 'term', $term_id, isset( $_POST['px_size_guide'] ) ? sanitize_text_field( wp_unslash( $_POST['px_size_guide'] ) ) : '' );
	}

	/**
	 * Write META_LINK, or delete it when the choice is "inherit".
	 *
	 * An absent meta is what lets the legacy fallback below keep working, so
	 * "inherit" must delete rather than store an empty string.
	 *
	 * @param string $type    'post' or 'term'.
	 * @param int    $id      Object ID.
	 * @param string $value   Submitted value.
	 */
	protected static function store_link( $type, $id, $value ) {
		$value = ( self::OFF === $value ) ? self::OFF : (string) absint( $value );

		if ( '' === $value || '0' === $value ) {
			'post' === $type ? delete_post_meta( $id, self::META_LINK ) : delete_term_meta( $id, self::META_LINK );
			return;
		}

		'post' === $type ? update_post_meta( $id, self::META_LINK, $value ) : update_term_meta( $id, self::META_LINK, $value );
	}

	/* --------------------------------------------------------------------- *
	 * Woodmart bridge - temporary.
	 *
	 * Everything that knows about the Woodmart theme lives in this block. It
	 * lets a shop switch themes before the data is converted: a product with
	 * no _px_ meta still finds its old guide. Delete the block (and the
	 * woodmart_* meta) once the migration command has run everywhere.
	 * --------------------------------------------------------------------- */

	const LEGACY_POST_TYPE = 'woodmart_size_guide';
	const LEGACY_PRODUCT   = 'woodmart_sguide_select';
	const LEGACY_TERM      = 'woodmart_chosen_sguide';
	const LEGACY_TABLE     = 'woodmart_sguide';
	const LEGACY_HIDE      = 'woodmart_sguide_hide_table';

	/**
	 * Woodmart's product assignment translated to our values.
	 *
	 * @param int $product_id Product ID.
	 * @return string '' (inherit), 'off', or a guide ID.
	 */
	protected static function legacy_product_value( $product_id ) {
		$value = get_post_meta( $product_id, self::LEGACY_PRODUCT, true );

		if ( 'disable' === $value ) {
			return self::OFF;
		}
		if ( '' === $value || 'none' === $value ) {
			return '';
		}

		return (string) absint( $value );
	}

	/**
	 * @param int $term_id Term ID.
	 * @return string
	 */
	protected static function legacy_term_value( $term_id ) {
		return (string) absint( get_term_meta( $term_id, self::LEGACY_TERM, true ) );
	}

	/**
	 * Table of a Woodmart size guide post.
	 *
	 * @param WP_Post $post Guide post.
	 * @return array|null
	 */
	protected static function legacy_guide( $post ) {
		if ( self::LEGACY_POST_TYPE !== $post->post_type ) {
			return null;
		}

		return array(
			'table'      => get_post_meta( $post->ID, self::LEGACY_TABLE, true ),
			'hide_table' => 'hide' === get_post_meta( $post->ID, self::LEGACY_HIDE, true ),
		);
	}

	/**
	 * Note under the product picker while the product still runs on the old meta.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $current    Current _px_ value.
	 */
	protected static function legacy_product_hint( $product_id, $current ) {
		if ( '' !== $current ) {
			return;
		}

		$legacy = self::legacy_product_value( $product_id );
		if ( '' === $legacy ) {
			return;
		}

		$label = self::OFF === $legacy ? __( 'do not show', 'px-shop-core' ) : get_the_title( (int) $legacy );
		?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: name of the size guide inherited from the old theme. */
				esc_html__( 'Still using the guide from the old theme: %s', 'px-shop-core' ),
				esc_html( $label )
			);
			?>
		</p>
		<?php
	}
}
