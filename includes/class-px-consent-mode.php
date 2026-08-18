<?php
/**
 * Google Consent Mode v2 on top of the free Complianz build.
 *
 * Complianz ships Consent Mode v2 in the paid version only - in the free
 * build the `consent-mode` field is hardcoded to 'disabled' => true. Nothing
 * in the runtime checks a licence though, so the signals can be sent from
 * here: Complianz stays the CMP (banner, categories, cookie notice, legal
 * documents) and this module only translates its decisions into Google's
 * language.
 *
 * How it works:
 *   1. Complianz is told not to output its own GA snippet
 *      (`configuration_by_complianz` => 'no'), so gtag is not emitted twice.
 *      Done through a filter on the option rather than a write to the
 *      database - the Complianz wizard flips the stored value back and GA
 *      would then load twice, once blocked and once ours.
 *   2. gtag is printed into <head> with `consent default` = everything
 *      denied, immediately followed by the library itself. GA therefore
 *      loads before consent but sets no cookies - that is the whole point
 *      of Consent Mode v2.
 *   3. `consent update` is sent on the Complianz events
 *      `cmplz_fire_categories` and `cmplz_revoke`. Both exist in the free
 *      build (cookiebanner/js/complianz.js) and `cmplz_fire_categories`
 *      also fires on page load with the categories already stored, so a
 *      returning visitor is covered too.
 *
 * Why `data-category="functional"`: the Complianz blocker keeps
 * `www.googletagmanager.com/gtag/js` in `cmplz_known_script_tags` and would
 * otherwise rewrite both scripts to `type="text/plain"`. Scripts carrying
 * that attribute are skipped (class-cookie-blocker.php) - the premium
 * version solves it the same way.
 *
 * The measurement id is read from Complianz (`cmplz_options['ua_code']`) so
 * there is a single source of truth that stays editable in wp-admin. A
 * filter can override it, should Complianz ever be dropped.
 *
 * The module is off by default: a site that already emits Consent Mode from
 * its own site plugin would otherwise start emitting gtag twice after an
 * update of this plugin.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Consent_Mode {

	/**
	 * Complianz consent categories mapped to Google signals.
	 *
	 * `functionality_storage` and `security_storage` are always granted -
	 * they do not fall under consent.
	 */
	const SIGNALS = array(
		'preferences' => array( 'personalization_storage' ),
		'statistics'  => array( 'analytics_storage' ),
		'marketing'   => array( 'ad_storage', 'ad_user_data', 'ad_personalization' ),
	);

	/**
	 * Hooks. Without Complianz there is no consent signal to translate, so
	 * the module stays silent rather than parking the visitor on "denied".
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! self::has_complianz() ) {
			return;
		}

		add_filter( 'option_cmplz_options', array( __CLASS__, 'release_complianz_statistics' ) );
		add_action( 'wp_head', array( __CLASS__, 'render' ), 1 );
	}

	/**
	 * Is Complianz active?
	 *
	 * @return bool
	 */
	public static function has_complianz() {
		return function_exists( 'cmplz_get_option' );
	}

	/**
	 * Measurement id from Complianz, or from the filter.
	 *
	 * An empty string means there is nothing to print; the caller handles it.
	 *
	 * @return string
	 */
	public static function measurement_id() {
		$options = get_option( 'cmplz_options', array() );
		$id      = is_array( $options ) && ! empty( $options['ua_code'] ) ? (string) $options['ua_code'] : '';

		/**
		 * Filters the measurement id.
		 *
		 * @param string $id Id read from Complianz.
		 */
		$id = trim( (string) apply_filters( 'px_consent_mode_measurement_id', $id ) );

		// G- (GA4), AW- (Ads), GT- (Google Tag). UA- is dead, but accept it
		// rather than fail on a value still sitting in the option.
		return preg_match( '/^(G|AW|GT|UA)-[A-Z0-9-]+$/i', $id ) ? $id : '';
	}

	/**
	 * Complianz must not emit its own GA snippet - this module emits it.
	 *
	 * `compile_statistics` is deliberately left at `google-analytics`,
	 * otherwise Complianz would stop listing the GA cookies in the cookie
	 * notice and in the legal documents.
	 *
	 * @param mixed $options Value of the `cmplz_options` option.
	 * @return mixed
	 */
	public static function release_complianz_statistics( $options ) {
		if ( ! is_array( $options ) ) {
			return $options;
		}

		$options['configuration_by_complianz'] = 'no';

		return $options;
	}

	/**
	 * Prints the consent defaults, the update handlers and gtag itself.
	 *
	 * @return void
	 */
	public static function render() {
		$id = self::measurement_id();

		if ( '' === $id || is_admin() ) {
			return;
		}

		/**
		 * How long (ms) gtag waits for `consent update` before sending the
		 * first ping. Without it a returning visitor who already consented
		 * would lose the first page_view - the default runs in <head> while
		 * the update arrives only after complianz.js has loaded.
		 *
		 * @param int $ms Milliseconds.
		 */
		$wait_for_update = (int) apply_filters( 'px_consent_mode_wait_for_update', 500 );

		/**
		 * No cookies and no identifiers in requests once ads are refused.
		 *
		 * @param bool $redact Whether to redact ads data.
		 */
		$ads_data_redaction = apply_filters( 'px_consent_mode_ads_data_redaction', true ) ? 'true' : 'false';

		/**
		 * Off by default - it carries the click id in the URL, which is
		 * personal data.
		 *
		 * @param bool $passthrough Whether to pass the click id through.
		 */
		$url_passthrough = apply_filters( 'px_consent_mode_url_passthrough', false ) ? 'true' : 'false';

		$map = wp_json_encode( self::SIGNALS );

		?>
<!-- Google Consent Mode v2 (px-shop-core) -->
<script data-category="functional">
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}

var pxCmSignals = <?php echo $map; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode. ?>;

gtag('set', 'ads_data_redaction', <?php echo $ads_data_redaction; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- true/false literal. ?>);
gtag('set', 'url_passthrough', <?php echo $url_passthrough; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- true/false literal. ?>);

gtag('consent', 'default', {
	'security_storage': 'granted',
	'functionality_storage': 'granted',
	'personalization_storage': 'denied',
	'analytics_storage': 'denied',
	'ad_storage': 'denied',
	'ad_user_data': 'denied',
	'ad_personalization': 'denied',
	'wait_for_update': <?php echo (int) $wait_for_update; ?>
});

function pxConsentModeUpdate(categories) {
	var consent = {
		'security_storage': 'granted',
		'functionality_storage': 'granted'
	};

	categories = Array.isArray(categories) ? categories : [];

	for (var category in pxCmSignals) {
		if (!pxCmSignals.hasOwnProperty(category)) {
			continue;
		}

		var state = categories.indexOf(category) !== -1 ? 'granted' : 'denied';

		for (var i = 0; i < pxCmSignals[category].length; i++) {
			consent[pxCmSignals[category][i]] = state;
		}
	}

	gtag('consent', 'update', consent);
}

// Complianz fires this on page load as well, with the categories already
// stored - it therefore covers both the first consent and a returning visitor.
document.addEventListener('cmplz_fire_categories', function(e){
	pxConsentModeUpdate(e.detail && e.detail.categories);
});

// Consent revoked. cmplz_fire_categories runs here too, but the order is not
// guaranteed, so this is set down hard.
document.addEventListener('cmplz_revoke', function(){
	pxConsentModeUpdate([]);
});

gtag('js', new Date());
gtag('config', '<?php echo esc_js( $id ); ?>');
</script>
<script async data-category="functional" src="https://www.googletagmanager.com/gtag/js?id=<?php echo rawurlencode( $id ); ?>"></script>
<!-- /Google Consent Mode v2 (px-shop-core) -->
		<?php
	}
}
