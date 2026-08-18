<?php
/**
 * Banner text column - eyebrow, heading, intro, free text, buttons.
 *
 * Shared by every layout, so a project usually overrides a whole layout
 * rather than this part. Override: yourtheme/px-shop-core/content/parts/banner-text.php
 *
 * @var array $banner Banner data (see PX_Content::get_banner()).
 * @var array $args   Rendering arguments (see PX_Content::render()).
 *
 * @package PxShopCore
 */

defined( 'ABSPATH' ) || exit;

$px_eyebrow = in_array( 'eyebrow', $args['supports'], true ) ? $banner['eyebrow'] : '';
$px_buttons = in_array( 'buttons', $args['supports'], true );
$px_tag     = $args['heading_tag'];

$px_btn1 = $px_buttons && $banner['button_label'] && $banner['button_url'];
$px_btn2 = $px_buttons && $banner['button2_label'] && $banner['button2_url'];

/**
 * Filters the classes of a banner button.
 *
 * A theme that already has a button component maps them onto it
 * ('pc-btn pc-btn--primary') instead of styling px-banner__btn again.
 *
 * @param string $class  Class attribute.
 * @param string $role   primary|secondary.
 * @param array  $banner Banner data.
 */
$px_btn1_class = apply_filters( 'px_content_button_class', 'px-banner__btn px-banner__btn--primary', 'primary', $banner );
$px_btn2_class = apply_filters( 'px_content_button_class', 'px-banner__btn px-banner__btn--secondary', 'secondary', $banner );
?>
<div class="px-banner__text">

	<?php if ( '' !== $px_eyebrow ) : ?>
		<p class="px-banner__eyebrow"><?php echo esc_html( $px_eyebrow ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== $banner['heading'] ) : ?>
		<<?php echo esc_attr( $px_tag ); ?> class="px-banner__title"><?php echo esc_html( $banner['heading'] ); ?></<?php echo esc_attr( $px_tag ); ?>>
	<?php endif; ?>

	<?php if ( '' !== trim( (string) $banner['perex'] ) ) : ?>
		<p class="px-banner__perex"><?php echo esc_html( $banner['perex'] ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== trim( (string) $args['text_html'] ) ) : ?>
		<div class="px-banner__content"><?php echo wp_kses_post( $args['text_html'] ); ?></div>
	<?php endif; ?>

	<?php if ( $px_btn1 || $px_btn2 ) : ?>
		<div class="px-banner__actions">

			<?php if ( $px_btn1 ) : ?>
				<a class="<?php echo esc_attr( $px_btn1_class ); ?>" href="<?php echo esc_url( $banner['button_url'] ); ?>">
					<?php echo esc_html( $banner['button_label'] ); ?>
				</a>
			<?php endif; ?>

			<?php if ( $px_btn2 ) : ?>
				<a class="<?php echo esc_attr( $px_btn2_class ); ?>" href="<?php echo esc_url( $banner['button2_url'] ); ?>">
					<?php echo esc_html( $banner['button2_label'] ); ?>
				</a>
			<?php endif; ?>

		</div>
	<?php endif; ?>

</div>
