<?php
/**
 * Banner - text and buttons only (layout "plain"), and the fallback for
 * any layout whose own template is missing.
 *
 * Override: yourtheme/px-shop-core/content/banner.php
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
	</div>
</section>
