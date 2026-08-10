<?php
/**
 * Back-in-stock waitlist.
 *
 * Guests subscribe with an e-mail on an out-of-stock product (REST endpoint;
 * the theme renders the form). Subscribers are stored in product meta and
 * notified once - through the WooCommerce mailer template - when the product
 * comes back in stock.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Waitlist {

	const META_KEY = '_px_waitlist_emails';
	const MAX      = 500;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'maybe_notify' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'maybe_notify' ), 10, 3 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_metabox' ) );
	}

	/* ------------------------------ Storage ------------------------------ */

	/**
	 * @return array email => subscribed timestamp
	 */
	public static function get_subscribers( $product_id ) {
		$subscribers = get_post_meta( $product_id, self::META_KEY, true );

		return is_array( $subscribers ) ? $subscribers : array();
	}

	/**
	 * @return true|WP_Error
	 */
	public static function subscribe( $product_id, $email ) {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'px_invalid_email', __( 'Please enter a valid e-mail address.', 'px-shop-core' ), array( 'status' => 400 ) );
		}

		$subscribers = self::get_subscribers( $product_id );

		if ( isset( $subscribers[ $email ] ) ) {
			return true; // Already subscribed - report success, no duplicates.
		}
		if ( count( $subscribers ) >= self::MAX ) {
			return new WP_Error( 'px_waitlist_full', __( 'The waitlist for this product is full.', 'px-shop-core' ), array( 'status' => 409 ) );
		}

		$subscribers[ $email ] = time();
		update_post_meta( $product_id, self::META_KEY, $subscribers );

		return true;
	}

	/* ------------------------------- REST -------------------------------- */

	public static function register_routes() {
		register_rest_route( 'px-shop-core/v1', '/waitlist', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => '__return_true',
			'args'                => array(
				'product_id' => array( 'required' => true, 'type' => 'integer' ),
				'email'      => array( 'required' => true, 'type' => 'string' ),
			),
			'callback'            => array( __CLASS__, 'rest_subscribe' ),
		) );
	}

	public static function rest_subscribe( WP_REST_Request $request ) {
		$product = wc_get_product( (int) $request['product_id'] );

		if ( ! $product || 'publish' !== $product->get_status() ) {
			return new WP_Error( 'px_invalid_product', __( 'Invalid product.', 'px-shop-core' ), array( 'status' => 404 ) );
		}
		if ( $product->is_in_stock() ) {
			return new WP_Error( 'px_in_stock', __( 'This product is already in stock.', 'px-shop-core' ), array( 'status' => 400 ) );
		}

		$result = self::subscribe( $product->get_id(), (string) $request['email'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'ok'      => true,
			'message' => __( 'Thank you! We will e-mail you as soon as the product is back in stock.', 'px-shop-core' ),
		);
	}

	/* ---------------------------- Notification ---------------------------- */

	/**
	 * Runs on every stock status change; acts only on -> instock.
	 */
	public static function maybe_notify( $product_id, $stock_status, $product = null ) {
		if ( 'instock' !== $stock_status ) {
			return;
		}

		$subscribers = self::get_subscribers( $product_id );
		if ( empty( $subscribers ) ) {
			return;
		}

		$product = $product instanceof WC_Product ? $product : wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$mailer = WC()->mailer();

		/* translators: %s: product name. */
		$subject = sprintf( __( '%s is back in stock', 'px-shop-core' ), $product->get_name() );

		$body = sprintf(
			'<p>%s</p><p><a href="%s">%s</a></p>',
			/* translators: %s: product name. */
			sprintf( esc_html__( 'Good news - %s is available again.', 'px-shop-core' ), esc_html( $product->get_name() ) ),
			esc_url( $product->get_permalink() ),
			esc_html__( 'View product', 'px-shop-core' )
		);
		$body = $mailer->wrap_message( $subject, $body );

		foreach ( array_keys( $subscribers ) as $email ) {
			$mailer->send( $email, $subject, $body );
		}

		delete_post_meta( $product_id, self::META_KEY );
	}

	/* ------------------------------- Admin -------------------------------- */

	public static function register_metabox() {
		global $post;

		if ( ! $post || 'product' !== $post->post_type || empty( self::get_subscribers( $post->ID ) ) ) {
			return;
		}

		add_meta_box(
			'px-waitlist',
			__( 'Waitlist', 'px-shop-core' ),
			array( __CLASS__, 'render_metabox' ),
			'product',
			'side'
		);
	}

	public static function render_metabox( $post ) {
		$subscribers = self::get_subscribers( $post->ID );

		echo '<p>' . esc_html( sprintf(
			/* translators: %d: number of subscribers. */
			__( 'Customers waiting for this product: %d', 'px-shop-core' ),
			count( $subscribers )
		) ) . '</p><ul style="margin:0;">';

		foreach ( $subscribers as $email => $time ) {
			echo '<li><code>' . esc_html( $email ) . '</code> <span style="color:#787c82;">' . esc_html( date_i18n( get_option( 'date_format' ), $time ) ) . '</span></li>';
		}

		echo '</ul>';
	}
}
