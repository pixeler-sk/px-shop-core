<?php
/**
 * Waitlist: back in stock (plain text).
 *
 * @package PxShopCore
 */

defined( 'ABSPATH' ) || exit;

echo "= " . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

printf(
	/* translators: %s: product name. */
	esc_html__( 'Good news - %s is available again.', 'px-shop-core' ),
	esc_html( $product ? $product->get_name() : '' )
);
echo "\n\n";

if ( $product ) {
	echo esc_url_raw( $product->get_permalink() ) . "\n\n";
	esc_html_e( 'Stock is limited - this message went to everybody who was waiting.', 'px-shop-core' );
	echo "\n\n";
}

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo "\n----------------------------------------\n\n";
echo esc_html( wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
