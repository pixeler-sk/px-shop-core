<?php
/**
 * Wishlist.
 *
 * Product IDs are stored in user meta for logged-in customers and in a
 * cookie for guests (merged into the account on login). The theme renders
 * the buttons/page; this class provides storage, REST endpoints and the
 * [px_wishlist] shortcode with a neutral, filterable product grid.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Wishlist {

	const COOKIE   = 'px_wishlist';
	const META_KEY = '_px_wishlist';
	const MAX      = 100;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wp_login', array( __CLASS__, 'merge_cookie_on_login' ), 10, 2 );
		add_shortcode( 'px_wishlist', array( __CLASS__, 'shortcode' ) );
	}

	/* ------------------------------ Storage ------------------------------ */

	/**
	 * @return int[] Product IDs, newest last.
	 */
	public static function get_ids() {
		if ( is_user_logged_in() ) {
			$ids = get_user_meta( get_current_user_id(), self::META_KEY, true );
		} else {
			$ids = isset( $_COOKIE[ self::COOKIE ] ) ? explode( ',', wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : array();
		}

		return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
	}

	protected static function save_ids( array $ids ) {
		$ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), - self::MAX );

		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), self::META_KEY, $ids );
		} else {
			setcookie( self::COOKIE, implode( ',', $ids ), array(
				'expires'  => time() + MONTH_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => is_ssl(),
				'httponly' => false, // theme JS may read the count
				'samesite' => 'Lax',
			) );
			$_COOKIE[ self::COOKIE ] = implode( ',', $ids );
		}
	}

	public static function has( $product_id ) {
		return in_array( absint( $product_id ), self::get_ids(), true );
	}

	public static function count() {
		return count( self::get_ids() );
	}

	/**
	 * @return bool True when the product ended up IN the wishlist.
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

	public static function merge_cookie_on_login( $user_login, $user ) {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}

		$cookie_ids = array_filter( array_map( 'absint', explode( ',', wp_unslash( $_COOKIE[ self::COOKIE ] ) ) ) );
		$meta_ids   = array_filter( array_map( 'absint', (array) get_user_meta( $user->ID, self::META_KEY, true ) ) );

		update_user_meta( $user->ID, self::META_KEY, array_slice( array_values( array_unique( array_merge( $meta_ids, $cookie_ids ) ) ), - self::MAX ) );

		setcookie( self::COOKIE, '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/' );
	}

	/* ------------------------------- REST -------------------------------- */

	public static function register_routes() {
		register_rest_route( 'px-shop-core/v1', '/wishlist', array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => function () {
				return array( 'ids' => self::get_ids(), 'count' => self::count() );
			},
		) );

		register_rest_route( 'px-shop-core/v1', '/wishlist/toggle', array(
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

	public static function shortcode() {
		$ids = self::get_ids();

		ob_start();

		if ( empty( $ids ) ) {
			echo '<p class="px-wishlist-empty">' . esc_html__( 'Your wishlist is empty.', 'px-shop-core' ) . '</p>';
			$shop = wc_get_page_permalink( 'shop' );
			if ( $shop ) {
				echo '<p><a class="px-wishlist-empty__link" href="' . esc_url( $shop ) . '">' . esc_html__( 'Browse products', 'px-shop-core' ) . '</a></p>';
			}

			return ob_get_clean();
		}

		$products = wc_get_products( array(
			'include' => $ids,
			'limit'   => -1,
			'status'  => 'publish',
		) );

		echo '<ul class="' . esc_attr( apply_filters( 'px_products_grid_classes', 'px-products-grid' ) ) . '">';
		global $post;
		foreach ( $products as $wishlist_product ) {
			$post = get_post( $wishlist_product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $post );
			wc_get_template_part( 'content', 'product' );
		}
		wp_reset_postdata();
		echo '</ul>';

		return ob_get_clean();
	}

	/* ------------------------------ Helpers ------------------------------ */

	public static function url() {
		$page_id = (int) get_option( 'px_wishlist_page_id' );

		return apply_filters( 'px_wishlist_url', $page_id ? get_permalink( $page_id ) : '' );
	}
}

function px_wishlist_ids() {
	return PX_Wishlist::get_ids();
}

function px_wishlist_has( $product_id ) {
	return PX_Wishlist::has( $product_id );
}

function px_wishlist_count() {
	return PX_Wishlist::count();
}

function px_wishlist_url() {
	return PX_Wishlist::url();
}
