<?php
/**
 * "It is back" - the message the whole waitlist exists for.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PX_Email_Waitlist' ) ) {
	return;
}

class PX_Email_Waitlist_In_Stock extends PX_Email_Waitlist {

	public function __construct() {
		$this->id             = 'px_waitlist_in_stock';
		$this->customer_email = true;
		$this->title          = __( 'Waitlist: product is back in stock', 'px-shop-core' );
		$this->description    = __( 'Sent to confirmed subscribers when the product they are waiting for is available again.', 'px-shop-core' );

		$this->template_html  = 'emails/px-waitlist-in-stock.php';
		$this->template_plain = 'emails/plain/px-waitlist-in-stock.php';

		add_action( 'px_waitlist_back_in_stock', array( $this, 'trigger' ), 10, 3 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( '{product_name} is back in stock', 'px-shop-core' );
	}

	public function get_default_heading() {
		return __( 'Back in stock', 'px-shop-core' );
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
