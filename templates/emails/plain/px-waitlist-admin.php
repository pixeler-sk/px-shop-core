<?php
/**
 * Waitlist: new subscriber, admin notification (plain text).
 *
 * @package PxShopCore
 */

defined( 'ABSPATH' ) || exit;

echo "= " . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

printf(
	/* translators: 1: customer e-mail, 2: product name. */
	esc_html__( '%1$s is waiting for %2$s.', 'px-shop-core' ),
	esc_html( $subscriber_email ),
	esc_html( $product ? $product->get_name() : '' )
);
echo "\n\n";

if ( $product ) {
	esc_html_e( 'SKU:', 'px-shop-core' );
	echo ' ' . esc_html( $product->get_sku() ? $product->get_sku() : '-' ) . "\n";

	esc_html_e( 'Waiting in total:', 'px-shop-core' );
	echo ' ' . esc_html( PX_Waitlist::count( $product->get_id() ) ) . "\n";

	echo esc_url_raw( admin_url( 'post.php?post=' . $product->get_id() . '&action=edit' ) ) . "\n\n";
}

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo "\n----------------------------------------\n\n";
echo esc_html( wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
