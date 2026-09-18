<?php
/**
 * Banner background video - a placeholder the theme script fills in.
 *
 * No iframe is printed. A YouTube / Vimeo frame in the HTML would be a
 * request to a third party before consent, the consent embed blocker would
 * turn it into a "show video" placeholder, and it would compete with the
 * poster image for bandwidth during page load. The theme script (handle
 * `px-banner-video`) builds the player after load, only where it may play
 * (not on phones unless allowed, not with reduced motion, not before
 * consent) and reveals it only once it really plays - until then, and for
 * good where it cannot, the banner image is the poster.
 *
 * A library file is a real <video> with preload="none": nothing is
 * downloaded until the script calls play(). It has no poster attribute -
 * the banner <img> under it already is the poster, with srcset, and a
 * second copy of the image would only cost a request.
 *
 * Override: yourtheme/px-shop-core/content/parts/banner-video.php
 *
 * @var array $banner Banner data (see PX_Content::get_banner()).
 * @var array $args   Rendering arguments (see PX_Content::render()).
 *
 * @package PxShopCore
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $banner['video'] ) || empty( $banner['video']['type'] ) ) {
	return;
}

$px_video   = $banner['video'];
$px_consent = isset( $px_video['consent'] ) ? (array) $px_video['consent'] : array();

$px_attrs = array(
	'class'                  => 'px-banner__video',
	'data-px-banner-video'   => $px_video['type'],
	'data-px-video-mobile'   => ! empty( $px_video['mobile'] ) ? '1' : '0',
	'aria-hidden'            => 'true',
	// inert: nothing inside (frame, video) is focusable or read, even when
	// a player ignores tabindex.
	'inert'                  => '',
);

if ( 'file' !== $px_video['type'] ) {
	$px_attrs['data-px-video-id'] = $px_video['id'];

	if ( ! empty( $px_video['hash'] ) ) {
		$px_attrs['data-px-video-hash'] = $px_video['hash'];
	}

	// The frame is hidden from assistive technology, but a frame without
	// a title is an audit error on its own.
	$px_attrs['data-px-video-title'] = '' !== (string) $banner['heading']
		/* translators: %s: banner heading */
		? sprintf( __( 'Background video: %s', 'px-shop-core' ), wp_strip_all_tags( $banner['heading'] ) )
		: __( 'Background video', 'px-shop-core' );
}

// Who answers in the browser: `px` = window.pxConsent, `cookieyes` =
// getCkyConsent(). No attributes = start without waiting.
if ( ! empty( $px_consent['category'] ) ) {
	$px_attrs['data-px-consent-cmp']      = ! empty( $px_consent['cmp'] ) ? $px_consent['cmp'] : 'px';
	$px_attrs['data-px-consent-category'] = $px_consent['category'];
	$px_attrs['data-px-consent-service']  = isset( $px_consent['service'] ) ? $px_consent['service'] : '';
}
?>
<div<?php foreach ( $px_attrs as $px_name => $px_value ) { echo '' === $px_value ? ' ' . esc_attr( $px_name ) : sprintf( ' %s="%s"', esc_attr( $px_name ), esc_attr( (string) $px_value ) ); } ?>>
	<?php if ( 'file' === $px_video['type'] ) : ?>
		<video class="px-banner__video-media" muted loop playsinline preload="none" disablepictureinpicture disableremoteplayback tabindex="-1">
			<source src="<?php echo esc_url( $px_video['src'] ); ?>"<?php echo $px_video['mime'] ? ' type="' . esc_attr( $px_video['mime'] ) . '"' : ''; ?>>
		</video>
	<?php endif; ?>
</div>
