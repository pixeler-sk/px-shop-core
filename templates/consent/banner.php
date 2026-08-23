<?php
/**
 * Consent banner - the first layer, plus the settings modal below it.
 *
 * Three buttons of the same kind and the same weight: accepting must not be
 * easier than refusing (EDPB 03/2022). Nothing here blocks the page, there
 * is no overlay over the content and no close cross that could be mistaken
 * for an answer - the banner simply waits.
 *
 * Hidden by default; consent.js shows it only when there is no valid answer
 * stored, which keeps the page cacheable.
 *
 * Override: yourtheme/px-shop-core/consent/banner.php
 *
 * @var array $args {
 *     @type array  $categories Consent categories.
 *     @type array  $grouped    Enabled services grouped by category.
 *     @type string $policy_url Cookie policy page, '' when none is set.
 * }
 *
 * @package PxShopCore
 */

defined( 'ABSPATH' ) || exit;

$categories = isset( $args['categories'] ) ? (array) $args['categories'] : array();
$grouped    = isset( $args['grouped'] ) ? (array) $args['grouped'] : array();
$policy_url = isset( $args['policy_url'] ) ? (string) $args['policy_url'] : '';

/**
 * Filters the heading of the banner.
 *
 * @param string $title Heading.
 */
$px_consent_title = (string) apply_filters( 'px_consent_banner_title', __( 'We care about your privacy', 'px-shop-core' ) );

/**
 * Filters the text of the first layer.
 *
 * @param string $text Text.
 */
$px_consent_text = (string) apply_filters(
	'px_consent_banner_text',
	__( 'We use cookies to run the shop, to measure how it is used and to make our offers relevant. The necessary ones are always on, everything else is up to you.', 'px-shop-core' )
);
?>
<?php // Not a dialog: it blocks nothing, traps nothing and can be walked past - a landmark with a name is what it really is. ?>
<div class="px-consent" id="px-consent" data-px-consent-banner hidden tabindex="-1" role="region" aria-label="<?php esc_attr_e( 'Cookie consent', 'px-shop-core' ); ?>" aria-describedby="px-consent-text">
	<div class="px-consent__inner">

		<div class="px-consent__body">
			<h2 class="px-consent__title" id="px-consent-title"><?php echo esc_html( $px_consent_title ); ?></h2>
			<p class="px-consent__text" id="px-consent-text">
				<?php echo esc_html( $px_consent_text ); ?>
				<?php if ( $policy_url ) : ?>
					<a class="px-consent__link" href="<?php echo esc_url( $policy_url ); ?>"><?php esc_html_e( 'Cookie policy', 'px-shop-core' ); ?></a>
				<?php endif; ?>
			</p>
		</div>

		<div class="px-consent__actions">
			<button type="button" class="px-consent__btn px-consent__btn--accept" data-px-consent-accept>
				<?php esc_html_e( 'Accept all', 'px-shop-core' ); ?>
			</button>
			<button type="button" class="px-consent__btn px-consent__btn--reject" data-px-consent-reject>
				<?php esc_html_e( 'Reject all', 'px-shop-core' ); ?>
			</button>
			<button type="button" class="px-consent__btn px-consent__btn--settings" data-px-consent-settings>
				<?php esc_html_e( 'Settings', 'px-shop-core' ); ?>
			</button>
		</div>

	</div>
</div>
<?php
echo PX_Consent::get_template( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
	'consent/modal.php',
	array(
		'categories' => $categories,
		'grouped'    => $grouped,
		'policy_url' => $policy_url,
	)
);
