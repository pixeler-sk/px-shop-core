<?php
/**
 * Waitlist: back in stock (HTML).
 *
 * Override in a theme: woocommerce/emails/px-waitlist-in-stock.php
 *
 * @package PxShopCore
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	printf(
		/* translators: %s: product name. */
		esc_html__( 'Good news - %s is available again.', 'px-shop-core' ),
		'<strong>' . esc_html( $product ? $product->get_name() : '' ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	);
	?>
</p>

<?php if ( $product ) : ?>
	<p style="margin:24px 0;">
		<a href="<?php echo esc_url( $product->get_permalink() ); ?>" style="display:inline-block;padding:12px 22px;background:#333;color:#fff;text-decoration:none;border-radius:4px;">
			<?php esc_html_e( 'View product', 'px-shop-core' ); ?>
		</a>
	</p>
	<p style="font-size:12px;color:#777;">
		<?php esc_html_e( 'Stock is limited - this message went to everybody who was waiting.', 'px-shop-core' ); ?>
	</p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
