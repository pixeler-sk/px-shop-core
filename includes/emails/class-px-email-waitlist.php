<?php
/**
 * Shared base for the waitlist e-mails.
 *
 * Everything they have in common: where templates live, which product and
 * address the message is about, and the confirm/unsubscribe links.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Email' ) ) {
	return;
}

abstract class PX_Email_Waitlist extends WC_Email {

	/** @var WC_Product|null */
	public $product = null;

	/** @var string */
	public $subscriber_email = '';

	/** @var array */
	public $entry = array();

	public function __construct() {
		$this->template_base = PX_SHOP_CORE_DIR . 'templates/';

		$this->placeholders = array_merge(
			array(
				'{product_name}'    => '',
				'{customer_email}'  => '',
			),
			(array) $this->placeholders
		);

		parent::__construct();
	}

	/**
	 * Remember what this message is about.
	 *
	 * @param string          $email   Subscriber address.
	 * @param WC_Product|null $product Product.
	 * @param array           $entry   Waitlist entry.
	 */
	protected function setup( $email, $product, $entry ) {
		$this->setup_locale();

		$this->subscriber_email = $email;
		$this->product          = $product instanceof WC_Product ? $product : null;
		$this->entry            = is_array( $entry ) ? $entry : array();

		$this->placeholders['{product_name}']   = $this->product ? $this->product->get_name() : '';
		$this->placeholders['{customer_email}'] = $email;
	}

	/**
	 * Send, unless the shop turned this message off.
	 *
	 * @param string $recipient Address.
	 */
	protected function dispatch( $recipient ) {
		if ( $this->is_enabled() && $recipient ) {
			$this->send( $recipient, $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	/**
	 * @return string '' when the entry has no token.
	 */
	public function get_confirm_url() {
		if ( empty( $this->entry['confirm'] ) || ! $this->product ) {
			return '';
		}

		return PX_Waitlist::get_link( 'confirm', $this->entry['confirm'], $this->product->get_id() );
	}

	/**
	 * @return string '' when the entry has no token.
	 */
	public function get_unsubscribe_url() {
		if ( empty( $this->entry['unsubscribe'] ) || ! $this->product ) {
			return '';
		}

		return PX_Waitlist::get_link( 'unsubscribe', $this->entry['unsubscribe'], $this->product->get_id() );
	}

	/**
	 * @param bool $plain_text Plain text variant.
	 * @return array
	 */
	protected function template_args( $plain_text ) {
		return array(
			'email_heading'      => $this->get_heading(),
			'product'            => $this->product,
			'subscriber_email'   => $this->subscriber_email,
			'confirm_url'        => $this->get_confirm_url(),
			'unsubscribe_url'    => $this->get_unsubscribe_url(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => ! $this->customer_email,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}
}
