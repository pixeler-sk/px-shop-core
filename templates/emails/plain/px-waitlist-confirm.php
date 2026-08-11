<?php
/**
 * Waitlist: confirm the address (plain text).
 *
 * @package PxShopCore
 */

defined( 'ABSPATH' ) || exit;

echo "= " . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

printf(
	/* translators: %s: product name. */
	esc_html__( 'Somebody (we hope you) asked us to send an e-mail as soon as %s is available again.', 'px-shop-core' ),
	esc_html( $product ? $product->get_name() : '' )
);
echo "\n\n";

esc_html_e( 'Confirm the address and we will do exactly that - nothing else, no newsletter.', 'px-shop-core' );
echo "\n\n";

if ( $confirm_url ) {
	echo esc_url_raw( $confirm_url ) . "\n\n";
}

esc_html_e( 'If it was not you, just ignore this message - without the link above nothing happens.', 'px-shop-core' );
echo "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo "\n----------------------------------------\n\n";
echo esc_html( wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
