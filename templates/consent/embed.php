<?php
/**
 * Placeholder of an embed that would otherwise call a third party before
 * consent (YouTube, Google Maps).
 *
 * The original iframe sits in the <template> element: the browser parses it
 * but fetches nothing, so nothing leaves the visitor's device until the
 * button is pressed. The button allows this one service, not the whole
 * category.
 *
 * The link below the button keeps the content reachable without JavaScript.
 *
 * Override: yourtheme/px-shop-core/consent/embed.php
 *
 * @var array $args {
 *     @type string $service    Service id (youtube, google_maps...).
 *     @type string $name       Service name.
 *     @type string $purpose    What the service does with the visit.
 *     @type string $url        Address of the embedded content.
 *     @type string $embed_html The original iframe.
 * }
 *
 * @package PxShopCore
 */

defined( 'ABSPATH' ) || exit;

$service    = isset( $args['service'] ) ? (string) $args['service'] : '';
$name       = isset( $args['name'] ) ? (string) $args['name'] : '';
$purpose    = isset( $args['purpose'] ) ? (string) $args['purpose'] : '';
$url        = isset( $args['url'] ) ? (string) $args['url'] : '';
$embed_html = isset( $args['embed_html'] ) ? (string) $args['embed_html'] : '';
?>
<div class="px-consent-embed" data-px-consent-embed="<?php echo esc_attr( $service ); ?>">
	<div class="px-consent-embed__inner">

		<p class="px-consent-embed__title">
			<?php
			printf(
				/* translators: %s: service name, e.g. YouTube. */
				esc_html__( 'Content from %s is blocked', 'px-shop-core' ),
				esc_html( $name )
			);
			?>
		</p>

		<?php if ( $purpose ) : ?>
			<p class="px-consent-embed__text"><?php echo esc_html( $purpose ); ?></p>
		<?php endif; ?>

		<p class="px-consent-embed__actions">
			<button type="button" class="px-consent-embed__btn" data-px-consent-allow="<?php echo esc_attr( $service ); ?>">
				<?php
				printf(
					/* translators: %s: service name, e.g. YouTube. */
					esc_html__( 'Allow %s and show the content', 'px-shop-core' ),
					esc_html( $name )
				);
				?>
			</button>
		</p>

		<p class="px-consent-embed__fallback">
			<a class="px-consent-embed__link" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener nofollow">
				<?php esc_html_e( 'Open the content in a new window', 'px-shop-core' ); ?>
			</a>
			<button type="button" class="px-consent-embed__settings" data-px-consent-settings>
				<?php esc_html_e( 'Cookie settings', 'px-shop-core' ); ?>
			</button>
		</p>

	</div>

	<template class="px-consent-embed__template"><?php echo $embed_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the iframe as the editor wrote it, already filtered by WordPress. ?></template>
</div>
