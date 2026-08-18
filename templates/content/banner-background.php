<?php
/**
 * Banner - image as the background, text over it (layout "background").
 *
 * The image is a real <img> (srcset, alt, lazy control) covering the
 * section, not a CSS background: a promo banner is content, and the
 * browser should be able to pick the right size for it.
 *
 * Override: yourtheme/px-shop-core/content/banner-background.php
 *
 * @var array $banner Banner data (see PX_Content::get_banner()).
 * @var array $args   Rendering arguments (see PX_Content::render()).
 *
 * @package PxShopCore
 */

defined( 'ABSPATH' ) || exit;

$px_overlay = in_array( 'overlay', $args['supports'], true ) ? (int) $banner['overlay'] : 0;
?>
<section class="<?php echo esc_attr( $args['classes'] ); ?>" data-px-banner="<?php echo (int) $banner['id']; ?>" style="--px-banner-overlay:<?php echo esc_attr( (string) ( $px_overlay / 100 ) ); ?>">

	<?php if ( $banner['image_id'] ) : ?>
		<?php
		echo wp_get_attachment_image( $banner['image_id'], $args['image_size'], false, array(
			'class'    => 'px-banner__bg',
			'loading'  => $args['eager'] ? 'eager' : 'lazy',
			'decoding' => 'async',
			'alt'      => '',
		) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
	<?php endif; ?>

	<div class="px-banner__inner">
		<?php px_content_template( 'content/parts/banner-text.php', array( 'banner' => $banner, 'args' => $args ) ); ?>
	</div>

</section>
