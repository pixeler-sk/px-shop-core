<?php
/**
 * Waitlist: confirm the address (HTML).
 *
 * Override in a theme: woocommerce/emails/px-waitlist-confirm.php
 *
 * @package PxShopCore
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	printf(
		/* translators: %s: product name. */
		esc_html__( 'Somebody (we hope you) asked us to send an e-mail as soon as %s is available again.', 'px-shop-core' ),
		'<strong>' . esc_html( $product ? $product->get_name() : '' ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	);
	?>
</p>

<p><?php esc_html_e( 'Confirm the address and we will do exactly that - nothing else, no newsletter.', 'px-shop-core' ); ?></p>

<?php if ( $confirm_url ) : ?>
	<p style="margin:24px 0;">
		<a href="<?php echo esc_url( $confirm_url ); ?>" style="display:inline-block;padding:12px 22px;background:#333;color:#fff;text-decoration:none;border-radius:4px;">
			<?php esc_html_e( 'Yes, let me know', 'px-shop-core' ); ?>
		</a>
	</p>
	<p style="font-size:12px;color:#777;">
		<?php esc_html_e( 'If the button does not work, open this address:', 'px-shop-core' ); ?><br />
		<a href="<?php echo esc_url( $confirm_url ); ?>"><?php echo esc_html( $confirm_url ); ?></a>
	</p>
<?php endif; ?>

<p style="font-size:12px;color:#777;">
	<?php esc_html_e( 'If it was not you, just ignore this message - without the click above nothing happens.', 'px-shop-core' ); ?>
</p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
