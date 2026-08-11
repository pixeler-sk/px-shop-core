<?php
/**
 * "Confirm your address" - the double opt-in request.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PX_Email_Waitlist' ) ) {
	return;
}

class PX_Email_Waitlist_Confirm extends PX_Email_Waitlist {

	public function __construct() {
		$this->id             = 'px_waitlist_confirm';
		$this->customer_email = true;
		$this->title          = __( 'Waitlist: confirm the address', 'px-shop-core' );
		$this->description    = __( 'Sent right after someone asks to be told when a product is back in stock. Until they click the link in it, they are not on the list.', 'px-shop-core' );

		$this->template_html  = 'emails/px-waitlist-confirm.php';
		$this->template_plain = 'emails/plain/px-waitlist-confirm.php';

		add_action( 'px_waitlist_subscribed', array( $this, 'trigger' ), 10, 3 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Confirm you want to hear about {product_name}', 'px-shop-core' );
	}

	public function get_default_heading() {
		return __( 'One click and you are on the list', 'px-shop-core' );
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
