<?php
/**
 * Product compare.
 *
 * Cookie-based selection (max 4 products) with REST endpoints and the
 * [px_compare] shortcode rendering a comparison table built from core
 * product data + visible global attributes (pa_*). Neutral px-* markup;
 * styling belongs to the theme.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Compare {

	const COOKIE = 'px_compare';
	const MAX    = 4;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_shortcode( 'px_compare', array( __CLASS__, 'shortcode' ) );
	}

	/* ------------------------------ Storage ------------------------------ */

	/**
	 * @return int[] Product IDs, oldest first.
	 */
	public static function get_ids() {
		$ids = isset( $_COOKIE[ self::COOKIE ] ) ? explode( ',', wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : array();

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	protected static function save_ids( array $ids ) {
		// Keep the newest MAX products - the oldest drops out.
		$ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), - self::MAX );

		setcookie( self::COOKIE, implode( ',', $ids ), array(
			'expires'  => time() + WEEK_IN_SECONDS,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'secure'   => is_ssl(),
			'httponly' => false,
			'samesite' => 'Lax',
		) );
		$_COOKIE[ self::COOKIE ] = implode( ',', $ids );
	}

	public static function has( $product_id ) {
		return in_array( absint( $product_id ), self::get_ids(), true );
	}

	public static function count() {
		return count( self::get_ids() );
	}

	/**
	 * @return bool True when the product ended up IN the compare list.
	 */
	public static function toggle( $product_id ) {
		$product_id = absint( $product_id );
		$ids        = self::get_ids();
		$index      = array_search( $product_id, $ids, true );

		if ( false === $index ) {
			$ids[] = $product_id;
			$added = true;
		} else {
			unset( $ids[ $index ] );
			$added = false;
		}

		self::save_ids( $ids );

		return $added;
	}

	/* ------------------------------- REST -------------------------------- */

	public static function register_routes() {
		register_rest_route( 'px-shop-core/v1', '/compare', array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => function () {
				return array( 'ids' => self::get_ids(), 'count' => self::count() );
			},
		) );

		register_rest_route( 'px-shop-core/v1', '/compare/toggle', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => '__return_true',
			'args'                => array(
				'product_id' => array( 'required' => true, 'type' => 'integer' ),
			),
			'callback'            => array( __CLASS__, 'rest_toggle' ),
		) );
	}

	public static function rest_toggle( WP_REST_Request $request ) {
		$product = wc_get_product( (int) $request['product_id'] );

		if ( ! $product || 'publish' !== $product->get_status() ) {
			return new WP_Error( 'px_invalid_product', __( 'Invalid product.', 'px-shop-core' ), array( 'status' => 404 ) );
		}

		$added = self::toggle( $product->get_id() );

		return array( 'in' => $added, 'count' => self::count() );
	}

	/* ----------------------------- Shortcode ----------------------------- */

	/**
	 * Cookie-driven table rendered in PHP - see PX_Wishlist::shortcode()
	 * for why a page cache must not get its hands on it.
	 */
	public static function shortcode() {
		px_shop_core_no_page_cache();

		$ids = self::get_ids();

		if ( empty( $ids ) ) {
			$html = '<p class="px-compare-empty">' . esc_html__( 'No products to compare yet.', 'px-shop-core' ) . '</p>';
			$shop = wc_get_page_permalink( 'shop' );
			if ( $shop ) {
				$html .= '<p><a class="px-compare-empty__link" href="' . esc_url( $shop ) . '">' . esc_html__( 'Browse products', 'px-shop-core' ) . '</a></p>';
			}

			return $html;
		}

		$products = array_filter( array_map( 'wc_get_product', $ids ) );

		ob_start();
		?>
		<div class="px-compare">
			<table class="px-compare__table">
				<tbody>

				<tr class="px-compare__row px-compare__row--media">
					<th scope="row"></th>
					<?php foreach ( $products as $product ) : ?>
						<td>
							<a href="<?php echo esc_url( $product->get_permalink() ); ?>" class="px-compare__media">
								<?php echo $product->get_image( 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</a>
							<button type="button" class="px-compare__remove" data-pc-compare="<?php echo esc_attr( $product->get_id() ); ?>" data-pc-compare-reload="1">
								<?php esc_html_e( 'Remove', 'px-shop-core' ); ?>
							</button>
						</td>
					<?php endforeach; ?>
				</tr>

				<tr class="px-compare__row px-compare__row--name">
					<th scope="row"><?php esc_html_e( 'Product', 'px-shop-core' ); ?></th>
					<?php foreach ( $products as $product ) : ?>
						<td><a href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></td>
					<?php endforeach; ?>
				</tr>

				<tr class="px-compare__row px-compare__row--price">
					<th scope="row"><?php esc_html_e( 'Price', 'px-shop-core' ); ?></th>
					<?php foreach ( $products as $product ) : ?>
						<td><?php echo $product->get_price_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<?php endforeach; ?>
				</tr>

				<tr class="px-compare__row px-compare__row--stock">
					<th scope="row"><?php esc_html_e( 'Availability', 'px-shop-core' ); ?></th>
					<?php foreach ( $products as $product ) : ?>
						<td><?php echo $product->is_in_stock() ? esc_html__( 'In stock', 'px-shop-core' ) : esc_html__( 'Out of stock', 'px-shop-core' ); ?></td>
					<?php endforeach; ?>
				</tr>

				<?php foreach ( self::attribute_rows( $products ) as $taxonomy => $row ) : ?>
					<tr class="px-compare__row">
						<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
						<?php foreach ( $products as $product ) : ?>
							<td><?php echo esc_html( isset( $row['values'][ $product->get_id() ] ) ? $row['values'][ $product->get_id() ] : '—' ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>

				<tr class="px-compare__row px-compare__row--cta">
					<th scope="row"></th>
					<?php
					global $post;
					foreach ( $products as $product ) :
						$post = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
						setup_postdata( $post );
						?>
						<td><?php woocommerce_template_loop_add_to_cart(); ?></td>
					<?php endforeach; wp_reset_postdata(); ?>
				</tr>

				</tbody>
			</table>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Union of visible pa_* attributes across the compared products.
	 *
	 * @param WC_Product[] $products Products.
	 * @return array taxonomy => [ label, values: product_id => "term, term" ]
	 */
	protected static function attribute_rows( array $products ) {
		$rows = array();

		foreach ( $products as $product ) {
			foreach ( $product->get_attributes() as $attribute ) {
				if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->is_taxonomy() ) {
					continue;
				}

				$taxonomy = $attribute->get_name();

				if ( ! isset( $rows[ $taxonomy ] ) ) {
					$rows[ $taxonomy ] = array(
						'label'  => wc_attribute_label( $taxonomy ),
						'values' => array(),
					);
				}

				$terms = get_the_terms( $product->get_id(), $taxonomy );
				if ( $terms && ! is_wp_error( $terms ) ) {
					$rows[ $taxonomy ]['values'][ $product->get_id() ] = implode( ', ', wp_list_pluck( $terms, 'name' ) );
				}
			}
		}

		return apply_filters( 'px_compare_attribute_rows', $rows, $products );
	}

	/* ------------------------------ Settings ----------------------------- */

	/**
	 * Fields for the module's section (WooCommerce -> Settings -> PX Shop).
	 *
	 * @return array
	 */
	public static function settings_fields() {
		return array(
			array(
				'title' => __( 'Compare', 'px-shop-core' ),
				'type'  => 'title',
				'desc'  => __( 'Page where the comparison table is rendered. Themes link to it from the compare counter; with no page set the link is simply not rendered.', 'px-shop-core' ),
				'id'    => 'px_compare_options',
			),
			array(
				'title' => __( 'Compare page', 'px-shop-core' ),
				'id'    => 'px_compare_page_id',
				'type'  => 'single_select_page',
				'class' => 'wc-enhanced-select-nostd',
				'css'   => 'min-width:300px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'px_compare_options',
			),
		);
	}

	/* ------------------------------ Helpers ------------------------------ */

	public static function url() {
		$page_id = (int) get_option( 'px_compare_page_id' );

		return apply_filters( 'px_compare_url', $page_id ? get_permalink( $page_id ) : '' );
	}
}

function px_compare_ids() {
	return PX_Compare::get_ids();
}

function px_compare_has( $product_id ) {
	return PX_Compare::has( $product_id );
}

function px_compare_count() {
	return PX_Compare::count();
}

function px_compare_url() {
	return PX_Compare::url();
}
