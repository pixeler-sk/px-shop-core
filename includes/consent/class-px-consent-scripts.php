<?php
/**
 * What the module prints into the page: Consent Mode v2 defaults and the
 * scripts of the enabled services.
 *
 * Two ways of keeping a service quiet before consent, one per service:
 *
 *   - Google (GA4, Ads, Tag Manager) runs under Consent Mode v2. The tag is
 *     printed normally, but `consent default` in <head> says everything is
 *     denied, so it loads without storing anything and without identifiers.
 *     That is the whole point of Consent Mode - blocking the tag outright
 *     would also throw away conversion modelling, which is the reason the
 *     signals exist. The visitor's choice arrives as `consent update`.
 *   - Everything else is printed as `type="text/plain"` with the category in
 *     `data-px-consent`. The browser does not run those; consent.js turns
 *     them into real scripts once the category is granted.
 *
 * Nothing here reads the consent cookie: the output is the same for every
 * visitor, so a full-page cache cannot serve one visitor's choice to another.
 * The decision is made in the browser.
 *
 * The signal map and the defaults are the ones proven in the Complianz
 * bridge (class-px-consent-mode.php); the source of the event is our own
 * banner instead of Complianz. The bridge is left untouched - sites still on
 * Complianz keep using it, and the two modules exclude each other.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Consent_Scripts {

	/**
	 * Consent categories mapped to Google signals.
	 *
	 * `functionality_storage` and `security_storage` are always granted -
	 * they do not fall under consent.
	 */
	const SIGNALS = array(
		'preferences' => array( 'personalization_storage' ),
		'statistics'  => array( 'analytics_storage' ),
		'marketing'   => array( 'ad_storage', 'ad_user_data', 'ad_personalization' ),
	);

	public static function init() {
		// Priority 1: the defaults must be in the page before any tag of any
		// other plugin, otherwise that tag starts out with no consent state.
		add_action( 'wp_head', array( __CLASS__, 'google' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'blocked_services' ), 5 );
	}

	/* ------------------------------- Google ------------------------------ */

	/**
	 * Is the Google side of the module wanted at all?
	 *
	 * Kept as its own switch so a shop whose tags come from somewhere else
	 * (a GTM container inserted by another plugin) still gets the defaults
	 * and the updates.
	 *
	 * @return bool
	 */
	public static function google_mode_on() {
		return 'yes' === get_option( PX_Consent::OPT_GOOGLE_MODE, 'yes' );
	}

	/**
	 * Consent Mode defaults, the update handler and the enabled Google tags.
	 *
	 * @return void
	 */
	public static function google() {
		// A site that filters the layer away has no way of sending an update,
		// so the defaults would park every visitor on "denied" for good.
		if ( ! PX_Consent::enabled_here() || ! self::google_mode_on() ) {
			return;
		}

		$ga4 = PX_Consent_Services::is_enabled( 'ga4' ) ? PX_Consent_Services::field( 'ga4', 'id' ) : '';
		$ads = PX_Consent_Services::is_enabled( 'google_ads' ) ? PX_Consent_Services::field( 'google_ads', 'id' ) : '';
		$gtm = PX_Consent_Services::is_enabled( 'gtm' ) ? PX_Consent_Services::field( 'gtm', 'id' ) : '';

		/**
		 * How long (ms) gtag waits for `consent update` before the first ping.
		 * The update of a returning visitor is sent from this very script, a
		 * line below the default, so the window only has to cover the tag
		 * libraries starting up.
		 *
		 * @param int $ms Milliseconds.
		 */
		$wait_for_update = (int) apply_filters( 'px_consent_wait_for_update', 500 );

		/**
		 * No cookies and no identifiers in requests once ads are refused.
		 *
		 * @param bool $redact Whether to redact ads data.
		 */
		$ads_data_redaction = apply_filters( 'px_consent_ads_data_redaction', true ) ? 'true' : 'false';

		/**
		 * Off by default - it carries the click id in the URL, which is
		 * personal data.
		 *
		 * @param bool $passthrough Whether to pass the click id through.
		 */
		$url_passthrough = apply_filters( 'px_consent_url_passthrough', false ) ? 'true' : 'false';

		$map = wp_json_encode( self::SIGNALS );

		?>
<!-- Consent Mode v2 (px-shop-core) -->
<script>
window.dataLayer = window.dataLayer || [];

// Not a global `gtag` unless this module really loads the library: other
// plugins test `typeof gtag` to decide whether the tag is already on the
// page, and a stub with nothing behind it makes them skip loading it.
function pxConsentGtag(){dataLayer.push(arguments);}

var pxConsentSignals = <?php echo $map; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode. ?>;
var pxConsentVersion = '<?php echo esc_js( PX_Consent::policy_version() ); ?>';
var pxConsentCookie = '<?php echo esc_js( PX_Consent::COOKIE ); ?>';
		<?php if ( $ga4 || $ads ) : ?>

if (typeof window.gtag !== 'function') {
	window.gtag = pxConsentGtag;
}
		<?php endif; ?>

pxConsentGtag('set', 'ads_data_redaction', <?php echo $ads_data_redaction; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- true/false literal. ?>);
pxConsentGtag('set', 'url_passthrough', <?php echo $url_passthrough; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- true/false literal. ?>);

pxConsentGtag('consent', 'default', {
	'security_storage': 'granted',
	'functionality_storage': 'granted',
	'personalization_storage': 'denied',
	'analytics_storage': 'denied',
	'ad_storage': 'denied',
	'ad_user_data': 'denied',
	'ad_personalization': 'denied',
	'wait_for_update': <?php echo (int) $wait_for_update; ?>
});

function pxConsentSend(granted) {
	var consent = {
		'security_storage': 'granted',
		'functionality_storage': 'granted'
	};

	for (var category in pxConsentSignals) {
		if (!pxConsentSignals.hasOwnProperty(category)) {
			continue;
		}

		var state = granted.indexOf(category) !== -1 ? 'granted' : 'denied';

		for (var i = 0; i < pxConsentSignals[category].length; i++) {
			consent[pxConsentSignals[category][i]] = state;
		}
	}

	pxConsentGtag('consent', 'update', consent);
}

// A returning visitor is updated here, in <head>, not from the footer script:
// waiting for DOMContentLoaded costs the first page_view, which is the one
// that matters. The cookie is read as strictly as consent.js reads it -
// anything that is not a boolean, or carries another policy version, is not
// an answer. Several cookies of the same name (one on the bare domain, one on
// www) are all read and the strictest wins.
(function () {
	var parts = (document.cookie || '').split(';');
	var granted = null;

	for (var i = 0; i < parts.length; i++) {
		var pair = parts[i].trim();

		if (pair.indexOf(pxConsentCookie + '=') !== 0) {
			continue;
		}

		var data;

		try {
			data = JSON.parse(decodeURIComponent(pair.slice(pxConsentCookie.length + 1)));
		} catch (e) {
			continue;
		}

		if (!data || typeof data !== 'object' || Array.isArray(data) || String(data.v) !== pxConsentVersion) {
			continue;
		}

		if (!data.c || typeof data.c !== 'object' || Array.isArray(data.c)) {
			continue;
		}

		var here = {};

		for (var category in pxConsentSignals) {
			if (pxConsentSignals.hasOwnProperty(category)) {
				here[category] = data.c[category] === true;
			}
		}

		if (granted === null) {
			granted = here;
		} else {
			for (var key in here) {
				if (here.hasOwnProperty(key)) {
					granted[key] = granted[key] && here[key];
				}
			}
		}
	}

	if (granted === null) {
		return;
	}

	var list = [];

	for (var name in granted) {
		if (granted.hasOwnProperty(name) && granted[name]) {
			list.push(name);
		}
	}

	// Only the signals; the dataLayer event is pushed once, by the handler
	// below, so a container trigger does not fire twice on one page view.
	pxConsentSend(list);
})();

// One handler for the first consent, for a returning visitor and for a
// withdrawal - consent.js fires px:consent in all three cases.
document.addEventListener('px:consent', function (e) {
	var detail = e.detail || {};
	var granted = detail.categories || [];

	pxConsentSend(granted);

	// Triggers inside a GTM container listen for this instead of digging
	// through the cookie themselves. Composed field by field on purpose: the
	// consent id stays in the cookie and must never reach a container.
	dataLayer.push({
		event: 'px_consent_update',
		px_consent: {
			version: detail.version,
			categories: granted,
			services: detail.services || {}
		}
	});
});
		<?php if ( $ga4 || $ads ) : ?>

pxConsentGtag('js', new Date());
			<?php if ( $ga4 ) : ?>
pxConsentGtag('config', '<?php echo esc_js( $ga4 ); ?>');
			<?php endif; ?>
			<?php if ( $ads ) : ?>
pxConsentGtag('config', '<?php echo esc_js( $ads ); ?>');
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( $gtm ) : ?>

(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});
var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;
j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})
(window,document,'script','dataLayer','<?php echo esc_js( $gtm ); ?>');
		<?php endif; ?>
</script>
		<?php if ( $ga4 || $ads ) : ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo rawurlencode( $ga4 ? $ga4 : $ads ); ?>"></script>
		<?php endif; ?>
<!-- /Consent Mode v2 (px-shop-core) -->
		<?php
		// The GTM <noscript> iframe is left out on purpose: it would load the
		// container for a visitor who cannot answer the banner at all.
	}

	/* --------------------------- Blocked services ------------------------ */

	/**
	 * Scripts of the enabled services, inert until consent.
	 *
	 * @return void
	 */
	public static function blocked_services() {
		if ( ! PX_Consent::enabled_here() ) {
			return;
		}

		foreach ( PX_Consent_Services::enabled() as $id => $service ) {
			if ( 'blocked' !== $service['mode'] || ! is_callable( $service['snippet'] ) ) {
				continue;
			}

			$js = (string) call_user_func( $service['snippet'], $id, $service );

			/**
			 * Filters the script of a service before it is printed.
			 *
			 * Returning an empty string drops it - the way to replace a
			 * built-in snippet with the shop's own.
			 *
			 * @param string $js      JavaScript, without the script tag.
			 * @param string $id      Service id.
			 * @param array  $service Service declaration.
			 */
			$js = (string) apply_filters( 'px_consent_service_snippet', $js, $id, $service );

			if ( '' === trim( $js ) ) {
				continue;
			}

			printf(
				"<script type=\"text/plain\" data-px-consent=\"%s\" data-px-service=\"%s\">\n%s\n</script>\n",
				esc_attr( $service['category'] ),
				esc_attr( $id ),
				$js // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JavaScript built from validated ids; escaping it would break the script.
			);
		}
	}

	/* ------------------------------ Snippets ----------------------------- */

	/**
	 * Meta Pixel base code.
	 *
	 * @param string $id Service id.
	 * @return string
	 */
	public static function meta_pixel_snippet( $id ) {
		$pixel = PX_Consent_Services::field( $id, 'id' );

		if ( '' === $pixel ) {
			return '';
		}

		return "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?"
			. "n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;"
			. "n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;"
			. "t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,"
			. "document,'script','https://connect.facebook.net/en_US/fbevents.js');\n"
			. "fbq('consent', 'grant');\n"
			. "fbq('init', '" . esc_js( $pixel ) . "');\n"
			. "fbq('track', 'PageView');";
	}

	/**
	 * Microsoft Clarity.
	 *
	 * @param string $id Service id.
	 * @return string
	 */
	public static function clarity_snippet( $id ) {
		$project = PX_Consent_Services::field( $id, 'id' );

		if ( '' === $project ) {
			return '';
		}

		return "(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};"
			. "t=l.createElement(r);t.async=1;t.src='https://www.clarity.ms/tag/'+i;"
			. "y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})"
			. "(window,document,'clarity','script','" . esc_js( $project ) . "');";
	}

	/**
	 * Smartsupp live chat.
	 *
	 * @param string $id Service id.
	 * @return string
	 */
	public static function smartsupp_snippet( $id ) {
		$key = PX_Consent_Services::field( $id, 'key' );

		if ( '' === $key ) {
			return '';
		}

		return "var _smartsupp = _smartsupp || {};\n_smartsupp.key = '" . esc_js( $key ) . "';\n"
			. "window.smartsupp||(function(d){var s,c,o=smartsupp=function(){o._.push(arguments)};o._=[];"
			. "s=d.getElementsByTagName('script')[0];c=d.createElement('script');c.type='text/javascript';"
			. "c.charset='utf-8';c.async=true;c.src='https://www.smartsuppchat.com/loader.js?';"
			. "s.parentNode.insertBefore(c,s);})(document);";
	}
}
