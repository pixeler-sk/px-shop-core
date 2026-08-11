<?php
/**
 * Admin notification about a new (confirmed) subscriber.
 *
 * Fires on confirmation, not on the first click - an unconfirmed address is
 * not a customer waiting, it is a form submission.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PX_Email_Waitlist' ) ) {
	return;
}

class PX_Email_Waitlist_Admin extends PX_Email_Waitlist {

	public function __construct() {
		$this->id             = 'px_waitlist_admin';
		$this->customer_email = false;
		$this->title          = __( 'Waitlist: new subscriber (admin)', 'px-shop-core' );
		$this->description    = __( 'Tells the shop that somebody is waiting for a product that is out of stock.', 'px-shop-core' );

		$this->template_html  = 'emails/px-waitlist-admin.php';
		$this->template_plain = 'emails/plain/px-waitlist-admin.php';

		add_action( 'px_waitlist_confirmed', array( $this, 'trigger' ), 10, 3 );

		parent::__construct();

		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	public function get_default_subject() {
		return __( 'Waitlist: somebody is waiting for {product_name}', 'px-shop-core' );
	}

	public function get_default_heading() {
		return __( 'New waitlist subscriber', 'px-shop-core' );
	}

	/**
	 * @param string     $email   Subscriber address.
	 * @param WC_Product $product Product.
	 * @param array      $entry   Waitlist entry.
	 */
	public function trigger( $email, $product, $entry ) {
		$this->setup( $email, $product, $entry );
		$this->dispatch( $this->get_recipient() );
	}

	public function init_form_fields() {
		parent::init_form_fields();

		$fields = array();
		foreach ( $this->form_fields as $key => $field ) {
			$fields[ $key ] = $field;

			if ( 'enabled' === $key ) {
				$fields['recipient'] = array(
					'title'       => __( 'Recipient(s)', 'px-shop-core' ),
					'type'        => 'text',
					/* translators: %s: admin e-mail address. */
					'description' => sprintf( __( 'Comma separated. Defaults to %s.', 'px-shop-core' ), '<code>' . esc_attr( get_option( 'admin_email' ) ) . '</code>' ),
					'placeholder' => '',
					'default'     => '',
					'desc_tip'    => true,
				);
			}
		}

		$this->form_fields = $fields;
	}
}
