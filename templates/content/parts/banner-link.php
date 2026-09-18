<?php
/**
 * Link of the whole banner (stretched link) - fallback for a banner
 * without a heading.
 *
 * Normally the link sits on the heading itself (parts/banner-text.php):
 * `<h2><a class="px-banner__link">Heading</a></h2>`, and the theme spreads
 * the link's ::after over the whole banner. That is the stretched-link
 * pattern: no <a> around the banner, so the buttons inside keep working as
 * their own links (nested links are invalid HTML), and the link has the
 * heading as its name, read once, not twice.
 *
 * A banner without a heading has nothing to put the link on; then this
 * empty link carries the name in aria-label and the theme stretches it the
 * same way.
 *
 * Override: yourtheme/px-shop-core/content/parts/banner-link.php
 *
 * @var array $banner Banner data (see PX_Content::get_banner()).
 * @var array $args   Rendering arguments (see PX_Content::render()).
 *
 * @package PxShopCore
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $banner['link'] ) || '' !== (string) $banner['heading'] ) {
	return;
}

// The item has no title - name the link after what the banner does say
// (eyebrow, then intro); "Show more" is the last resort.
$px_label = '';

foreach ( array( 'eyebrow', 'perex' ) as $px_key ) {
	$px_label = trim( wp_strip_all_tags( (string) ( $banner[ $px_key ] ?? '' ) ) );

	if ( '' !== $px_label ) {
		break;
	}
}

if ( '' === $px_label ) {
	$px_label = __( 'Show more', 'px-shop-core' );
}
?>
<a class="px-banner__link px-banner__link--bare" href="<?php echo esc_url( $banner['link'] ); ?>" aria-label="<?php echo esc_attr( wp_html_excerpt( $px_label, 120, '…' ) ); ?>"></a>
