<?php
/**
 * Waitlist: subscription confirmed (plain text).
 *
 * @package PxShopCore
 */

defined( 'ABSPATH' ) || exit;

echo "= " . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

printf(
	/* translators: %s: product name. */
	esc_html__( 'Thank you - we will e-mail you as soon as %s is back in stock.', 'px-shop-core' ),
	esc_html( $product ? $product->get_name() : '' )
);
echo "\n\n";

if ( $product ) {
	echo esc_url_raw( $product->get_permalink() ) . "\n\n";
}

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

if ( $unsubscribe_url ) {
	esc_html_e( 'Stop e-mailing me about availability:', 'px-shop-core' );
	echo "\n" . esc_url_raw( $unsubscribe_url ) . "\n\n";
}

echo "\n----------------------------------------\n\n";
echo esc_html( wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
