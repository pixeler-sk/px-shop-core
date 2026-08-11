<?php
/**
 * Waitlist: subscription confirmed (HTML).
 *
 * Override in a theme: woocommerce/emails/px-waitlist-subscribed.php
 *
 * @package PxShopCore
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	printf(
		/* translators: %s: product name. */
		esc_html__( 'Thank you - we will e-mail you as soon as %s is back in stock.', 'px-shop-core' ),
		'<strong>' . esc_html( $product ? $product->get_name() : '' ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	);
	?>
</p>

<?php if ( $product ) : ?>
	<p><a href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php esc_html_e( 'View product', 'px-shop-core' ); ?></a></p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
?>

<?php if ( $unsubscribe_url ) : ?>
	<p style="font-size:12px;color:#777;">
		<a href="<?php echo esc_url( $unsubscribe_url ); ?>"><?php esc_html_e( 'Stop e-mailing me about availability', 'px-shop-core' ); ?></a>
	</p>
<?php endif; ?>

<?php
do_action( 'woocommerce_email_footer', $email );
