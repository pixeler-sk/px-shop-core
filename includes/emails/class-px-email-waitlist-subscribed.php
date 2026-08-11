<?php
/**
 * "You are on the list" - sent once the address is confirmed.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PX_Email_Waitlist' ) ) {
	return;
}

class PX_Email_Waitlist_Subscribed extends PX_Email_Waitlist {

	public function __construct() {
		$this->id             = 'px_waitlist_subscribed';
		$this->customer_email = true;
		$this->title          = __( 'Waitlist: subscription confirmed', 'px-shop-core' );
		$this->description    = __( 'Sent after the customer confirms the address. Carries the unsubscribe link.', 'px-shop-core' );

		$this->template_html  = 'emails/px-waitlist-subscribed.php';
		$this->template_plain = 'emails/plain/px-waitlist-subscribed.php';

		add_action( 'px_waitlist_confirmed', array( $this, 'trigger' ), 10, 3 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'We will let you know about {product_name}', 'px-shop-core' );
	}

	public function get_default_heading() {
		return __( 'You are on the list', 'px-shop-core' );
	}

	/**
	 * @param string     $email   Subscriber address.
	 * @param WC_Product $product Product.
	 * @param array      $entry   Waitlist entry.
	 */
	public function trigger( $email, $product, $entry ) {
		$this->setup( $email, $product, $entry );
		$this->dispatch( $email );
	}
}
