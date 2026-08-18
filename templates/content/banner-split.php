<?php
/**
 * Banner - text on one side, image on the other (layouts "media-right" and
 * "media-left"). The side is decided by the layout class on the wrapper,
 * so both layouts share this markup.
 *
 * Override: yourtheme/px-shop-core/content/banner-split.php
 *
 * @var array $banner Banner data (see PX_Content::get_banner()).
 * @var array $args   Rendering arguments (see PX_Content::render()).
 *
 * @package PxShopCore
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="<?php echo esc_attr( $args['classes'] ); ?>" data-px-banner="<?php echo (int) $banner['id']; ?>">
	<div class="px-banner__inner">

		<?php px_content_template( 'content/parts/banner-text.php', array( 'banner' => $banner, 'args' => $args ) ); ?>

		<?php if ( $banner['image_id'] ) : ?>
			<div class="px-banner__media">
				<?php
				echo wp_get_attachment_image( $banner['image_id'], $args['image_size'], false, array(
					'class'    => 'px-banner__image',
					'loading'  => $args['eager'] ? 'eager' : 'lazy',
					'decoding' => 'async',
					'alt'      => '',
				) ); // phpcs:ignore WordPress.Security.EscapeOutput
				?>
			</div>
		<?php endif; ?>

	</div>
</section>
