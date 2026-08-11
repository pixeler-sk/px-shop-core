<?php
/**
 * Waitlist: new subscriber, admin notification (HTML).
 *
 * Override in a theme: woocommerce/emails/px-waitlist-admin.php
 *
 * @package PxShopCore
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	printf(
		/* translators: 1: customer e-mail, 2: product name. */
		esc_html__( '%1$s is waiting for %2$s.', 'px-shop-core' ),
		'<strong>' . esc_html( $subscriber_email ) . '</strong>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		'<strong>' . esc_html( $product ? $product->get_name() : '' ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	);
	?>
</p>

<?php if ( $product ) : ?>
	<ul>
		<li><?php esc_html_e( 'SKU:', 'px-shop-core' ); ?> <?php echo esc_html( $product->get_sku() ? $product->get_sku() : '—' ); ?></li>
		<li><?php esc_html_e( 'Waiting in total:', 'px-shop-core' ); ?> <?php echo esc_html( PX_Waitlist::count( $product->get_id() ) ); ?></li>
		<li><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $product->get_id() . '&action=edit' ) ); ?>"><?php esc_html_e( 'Edit product', 'px-shop-core' ); ?></a></li>
	</ul>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
