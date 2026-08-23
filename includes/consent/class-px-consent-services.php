<?php
/**
 * Service register - the single source of truth of the consent module.
 *
 * Everything the visitor sees or the browser executes is generated from the
 * declarations below: the categories in the modal, the tables on the cookie
 * policy page, the scripts that are printed blocked, the cookies that are
 * deleted when consent is withdrawn. Nothing is written twice, so a service
 * cannot be described one way in the banner and another way on the page.
 *
 * A service declares:
 *   name     - what the visitor sees (brand name, not translated)
 *   provider - controller/processor incl. address (art. 13 GDPR)
 *   category - functional | preferences | statistics | marketing
 *   purpose  - one or two sentences, in the visitor's language
 *   privacy  - the provider's privacy policy URL
 *   mode     - how the script gets on the page:
 *              'consent_mode' the module prints it unblocked because the
 *                             service honours Google Consent Mode v2 and sets
 *                             nothing before `consent update` says so,
 *              'blocked'      the module prints it as type="text/plain" and
 *                             the browser only runs it after consent,
 *              'embed'        no script of its own; iframes are replaced by a
 *                             placeholder (YouTube, Maps),
 *              'declared'     the module prints nothing - the service is on
 *                             the page from elsewhere (a plugin, the theme)
 *                             and is declared here so the policy page is
 *                             complete and the blocking contract can be used.
 *   fields   - settings this service needs (ids, keys), keyed by field id
 *   cookies  - name / expiry / desc rows for the modal and the policy page
 *   clear    - cookie names (with * wildcards) deleted on withdrawal
 *
 * A site plugin adds its own service through the `px_consent_services`
 * filter and gets the modal row, the policy row and the blocking for free.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Consent_Services {

	/** Prefix of the "is this service on" option. */
	const OPTION_PREFIX = 'px_consent_service_';

	/**
	 * Built catalog, kept for the length of the request.
	 *
	 * The register is asked for by the settings screen, the banner, the
	 * policy page and the script printer; rebuilding it four times would
	 * run every translation call again for nothing.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Consent categories, in the order they are shown.
	 *
	 * `functional` is locked on: those cookies are either strictly necessary
	 * for a service the visitor asked for, or set on their explicit request
	 * (wishlist), which ePrivacy art. 5(3) exempts from consent.
	 *
	 * @return array
	 */
	public static function categories() {
		$categories = array(
			'functional'  => array(
				'name'   => __( 'Necessary', 'px-shop-core' ),
				'desc'   => __( 'Needed for the site to work - the cart, logging in, remembering the choice you are making right now. They cannot be switched off.', 'px-shop-core' ),
				'locked' => true,
			),
			'preferences' => array(
				'name'   => __( 'Preferences', 'px-shop-core' ),
				'desc'   => __( 'Remember your settings, such as the language or the way products are listed, so you do not have to choose again.', 'px-shop-core' ),
				'locked' => false,
			),
			'statistics'  => array(
				'name'   => __( 'Statistics', 'px-shop-core' ),
				'desc'   => __( 'Tell us how the site is used - which pages are visited and where visitors get lost. The data is used to improve the shop.', 'px-shop-core' ),
				'locked' => false,
			),
			'marketing'   => array(
				'name'   => __( 'Marketing', 'px-shop-core' ),
				'desc'   => __( 'Used to measure the results of advertising and to show you offers that are relevant to you, here and on other sites.', 'px-shop-core' ),
				'locked' => false,
			),
		);

		/**
		 * Filters the consent categories.
		 *
		 * @param array $categories Categories keyed by id.
		 */
		return apply_filters( 'px_consent_categories', $categories );
	}

	/**
	 * Ids of the categories the visitor can decide about.
	 *
	 * @return string[]
	 */
	public static function optional_categories() {
		$ids = array();

		foreach ( self::categories() as $id => $category ) {
			if ( empty( $category['locked'] ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * The whole register, normalised.
	 *
	 * @return array Services keyed by id.
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		/**
		 * Filters the service register.
		 *
		 * @param array $services Services keyed by id (see the file header).
		 */
		$services = apply_filters( 'px_consent_services', self::catalog() );

		$normalised = array();

		foreach ( (array) $services as $id => $service ) {
			if ( empty( $service['name'] ) || empty( $service['category'] ) ) {
				continue;
			}

			$normalised[ $id ] = wp_parse_args(
				$service,
				array(
					'provider' => '',
					'purpose'  => '',
					'privacy'  => '',
					'mode'     => 'declared',
					'fields'   => array(),
					'cookies'  => array(),
					'clear'    => array(),
					'snippet'  => null,
					'note'     => '',
					'always'   => false,
					'default'  => 'no',
				)
			);
		}

		self::$cache = $normalised;

		return self::$cache;
	}

	/**
	 * One service.
	 *
	 * @param string $id Service id.
	 * @return array Empty when unknown.
	 */
	public static function get( $id ) {
		$services = self::all();

		return isset( $services[ $id ] ) ? $services[ $id ] : array();
	}

	/**
	 * Services grouped by category, categories in their declared order.
	 *
	 * @param bool $only_enabled Only services switched on in the settings.
	 * @return array Category id => array of service id => service.
	 */
	public static function by_category( $only_enabled = false ) {
		$grouped = array();

		foreach ( array_keys( self::categories() ) as $category ) {
			$grouped[ $category ] = array();
		}

		foreach ( self::all() as $id => $service ) {
			if ( $only_enabled && ! self::is_enabled( $id ) ) {
				continue;
			}

			if ( ! isset( $grouped[ $service['category'] ] ) ) {
				$grouped[ $service['category'] ] = array();
			}

			$grouped[ $service['category'] ][ $id ] = $service;
		}

		return array_filter( $grouped );
	}

	/**
	 * Services switched on in the settings and configured.
	 *
	 * A service whose required id is empty is treated as off: printing
	 * `gtag('config', '')` or listing an analytics tool that cannot run
	 * would be worse than not offering it at all.
	 *
	 * @return array Services keyed by id.
	 */
	public static function enabled() {
		$enabled = array();

		foreach ( self::all() as $id => $service ) {
			if ( self::is_enabled( $id ) ) {
				$enabled[ $id ] = $service;
			}
		}

		return $enabled;
	}

	/**
	 * Is this service switched on and usable?
	 *
	 * @param string $id Service id.
	 * @return bool
	 */
	public static function is_enabled( $id ) {
		$service = self::get( $id );

		if ( ! $service ) {
			return false;
		}

		// Cookies the site sets itself (WordPress, WooCommerce, this module)
		// are not optional and have no switch - they are always declared.
		if ( ! empty( $service['always'] ) ) {
			return true;
		}

		if ( 'yes' !== get_option( self::OPTION_PREFIX . $id, $service['default'] ) ) {
			return false;
		}

		foreach ( $service['fields'] as $field_id => $field ) {
			if ( ! empty( $field['required'] ) && '' === self::field( $id, $field_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Services that are on but miss a required value.
	 *
	 * @return string[] Service names.
	 */
	public static function misconfigured() {
		$names = array();

		foreach ( self::all() as $id => $service ) {
			if ( ! empty( $service['always'] ) || 'yes' !== get_option( self::OPTION_PREFIX . $id, $service['default'] ) ) {
				continue;
			}

			foreach ( $service['fields'] as $field_id => $field ) {
				if ( ! empty( $field['required'] ) && '' === self::field( $id, $field_id ) ) {
					$names[] = $service['name'];

					continue 2;
				}
			}
		}

		return $names;
	}

	/**
	 * Option name of a service field.
	 *
	 * @param string $id       Service id.
	 * @param string $field_id Field id.
	 * @return string
	 */
	public static function field_option( $id, $field_id ) {
		return 'px_consent_' . $id . '_' . $field_id;
	}

	/**
	 * Value of a service field.
	 *
	 * A value that does not match the field's pattern comes back empty -
	 * a typo in a measurement id should stop the tag from being printed,
	 * not travel into the page and break gtag.
	 *
	 * @param string $id       Service id.
	 * @param string $field_id Field id.
	 * @return string
	 */
	public static function field( $id, $field_id ) {
		$service = self::get( $id );

		if ( empty( $service['fields'][ $field_id ] ) ) {
			return '';
		}

		$value   = trim( (string) get_option( self::field_option( $id, $field_id ), '' ) );
		$pattern = isset( $service['fields'][ $field_id ]['pattern'] ) ? $service['fields'][ $field_id ]['pattern'] : '';

		if ( '' === $value || ( $pattern && ! preg_match( $pattern, $value ) ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Cookie name patterns to delete when a service loses consent.
	 *
	 * Only first-party cookies can be deleted from the browser; third-party
	 * ones (doubleclick.net, youtube.com) are out of reach, which is why the
	 * page is reloaded on withdrawal - the service then never runs again.
	 *
	 * @return array Service id => array of cookie name patterns.
	 */
	public static function clearable() {
		$map = array();

		foreach ( self::all() as $id => $service ) {
			if ( ! empty( $service['clear'] ) ) {
				$map[ $id ] = array_values( (array) $service['clear'] );
			}
		}

		return $map;
	}

	/* ------------------------------ Catalog ------------------------------ */

	/**
	 * Cookies the shop itself sets.
	 *
	 * Only the ones that can actually appear: a site with the wishlist module
	 * off never sets px_wishlist, and a cookie notice that lists cookies the
	 * site does not use is as wrong as one that hides them.
	 *
	 * @return array
	 */
	private static function px_shop_cookies() {
		$cookies = array(
			array(
				'name'   => PX_Consent::COOKIE,
				'expiry' => sprintf(
					/* translators: %d: number of days. */
					_n( '%d day', '%d days', PX_Consent::lifetime_days(), 'px-shop-core' ),
					PX_Consent::lifetime_days()
				),
				'desc'   => __( 'Your cookie choice, so you are not asked on every page.', 'px-shop-core' ),
			),
		);

		// Set by the theme when the visitor picks how many products a page
		// shows - stored on their explicit request, so no consent is needed.
		$cookies[] = array(
			'name'   => 'pxt_per_page',
			'expiry' => __( '30 days', 'px-shop-core' ),
			'desc'   => __( 'How many products you chose to see on one page.', 'px-shop-core' ),
		);

		if ( class_exists( 'PX_Wishlist' ) ) {
			$cookies[] = array(
				'name'   => 'px_wishlist',
				'expiry' => __( '1 month', 'px-shop-core' ),
				'desc'   => __( 'Products added to favourites by a visitor who is not logged in.', 'px-shop-core' ),
			);
		}

		if ( class_exists( 'PX_Compare' ) ) {
			$cookies[] = array(
				'name'   => 'px_compare',
				'expiry' => __( '1 month', 'px-shop-core' ),
				'desc'   => __( 'Products added to the comparison.', 'px-shop-core' ),
			);
		}

		return $cookies;
	}

	/**
	 * The built-in catalog: what Slovak shops actually run.
	 *
	 * @return array
	 */
	private static function catalog() {
		$google = 'Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Ireland';

		return array(

			/* ---------------------------- Necessary --------------------------- */

			'wordpress'   => array(
				'name'     => 'WordPress',
				'provider' => get_bloginfo( 'name' ),
				'category' => 'functional',
				'purpose'  => __( 'Keeps you logged in and remembers settings of the administration. Without them the site cannot tell one visitor from another.', 'px-shop-core' ),
				'mode'     => 'declared',
				'always'   => true,
				'cookies'  => array(
					array(
						'name'   => 'wordpress_logged_in_*',
						'expiry' => __( 'Session, or 14 days when "remember me" is used', 'px-shop-core' ),
						'desc'   => __( 'Identifies a logged-in user.', 'px-shop-core' ),
					),
					array(
						'name'   => 'wp-settings-*',
						'expiry' => __( '1 year', 'px-shop-core' ),
						'desc'   => __( 'Settings of the administration screens.', 'px-shop-core' ),
					),
				),
			),
			'woocommerce' => array(
				'name'     => 'WooCommerce',
				'provider' => get_bloginfo( 'name' ),
				'category' => 'functional',
				'purpose'  => __( 'Runs the cart and the checkout - remembers what you put in the cart and keeps the order together until it is placed.', 'px-shop-core' ),
				'mode'     => 'declared',
				'always'   => true,
				'cookies'  => array(
					array(
						'name'   => 'woocommerce_cart_hash',
						'expiry' => __( 'Session', 'px-shop-core' ),
						'desc'   => __( 'Tells the site the cart has changed.', 'px-shop-core' ),
					),
					array(
						'name'   => 'woocommerce_items_in_cart',
						'expiry' => __( 'Session', 'px-shop-core' ),
						'desc'   => __( 'Says whether the cart holds anything.', 'px-shop-core' ),
					),
					array(
						'name'   => 'wp_woocommerce_session_*',
						'expiry' => __( '2 days', 'px-shop-core' ),
						'desc'   => __( 'Links the browser to its cart on the server.', 'px-shop-core' ),
					),
				),
			),
			'px_shop'     => array(
				'name'     => 'PX Shop',
				'provider' => get_bloginfo( 'name' ),
				'category' => 'functional',
				'purpose'  => __( 'Remembers your cookie choice, the way you set the product listing and the products you added to favourites or to the comparison - each of them stored only after you clicked.', 'px-shop-core' ),
				'mode'     => 'declared',
				'always'   => true,
				'cookies'  => self::px_shop_cookies(),
			),
			'recaptcha'   => array(
				'name'     => 'Google reCAPTCHA',
				'provider' => $google,
				'category' => 'functional',
				'purpose'  => __( 'Protects forms from automated abuse. Without it the contact and registration forms would be filled by robots.', 'px-shop-core' ),
				'privacy'  => 'https://policies.google.com/privacy',
				'mode'     => 'declared',
				// Not "always": a shop without reCAPTCHA would be declaring a
				// cookie nobody sets. Switched on in the settings, it needs no
				// switch for the visitor - it is necessary for the forms.
				'note'     => __( 'Switch on only when the site really runs reCAPTCHA. Classified as necessary: it only runs on forms you submit yourself and is not used for advertising. A site that would rather ask for consent moves it with the px_consent_services filter.', 'px-shop-core' ),
				'cookies'  => array(
					array(
						'name'   => '_GRECAPTCHA',
						'expiry' => __( '6 months', 'px-shop-core' ),
						'desc'   => __( 'Distinguishes a person from a robot.', 'px-shop-core' ),
					),
				),
			),

			/* ---------------------------- Statistics -------------------------- */

			'ga4'         => array(
				'name'     => 'Google Analytics 4',
				'provider' => $google,
				'category' => 'statistics',
				'purpose'  => __( 'Measures traffic: which pages are visited, how visitors got here and where they leave. The results are used to improve the shop.', 'px-shop-core' ),
				'privacy'  => 'https://policies.google.com/privacy',
				'mode'     => 'consent_mode',
				'fields'   => array(
					'id' => array(
						'title'       => __( 'Measurement ID', 'px-shop-core' ),
						'placeholder' => 'G-XXXXXXXXXX',
						'pattern'     => '/^G-[A-Z0-9]+$/i',
						'required'    => true,
					),
				),
				'cookies'  => array(
					array(
						'name'   => '_ga',
						'expiry' => __( '2 years', 'px-shop-core' ),
						'desc'   => __( 'Tells one visitor from another.', 'px-shop-core' ),
					),
					array(
						'name'   => '_ga_*',
						'expiry' => __( '2 years', 'px-shop-core' ),
						'desc'   => __( 'Keeps the state of the visit.', 'px-shop-core' ),
					),
				),
				'clear'    => array( '_ga', '_ga_*', '_gid', '_gat*', '_gac_*' ),
			),
			'gtm'         => array(
				'name'     => 'Google Tag Manager',
				'provider' => $google,
				'category' => 'statistics',
				'purpose'  => __( 'Loads the measurement tags of the shop. On its own it stores nothing; the tags inside it follow the choice you make here.', 'px-shop-core' ),
				'privacy'  => 'https://policies.google.com/privacy',
				'mode'     => 'consent_mode',
				'fields'   => array(
					'id' => array(
						'title'       => __( 'Container ID', 'px-shop-core' ),
						'placeholder' => 'GTM-XXXXXXX',
						'pattern'     => '/^GTM-[A-Z0-9]+$/i',
						'required'    => true,
					),
				),
				'note'     => __( 'The container is loaded with Consent Mode already set to denied, so tags inside it stay quiet until consent. Do not also switch on Google Analytics 4 here when the container already carries the GA4 tag - it would be measured twice.', 'px-shop-core' ),
			),
			'clarity'     => array(
				'name'     => 'Microsoft Clarity',
				'provider' => 'Microsoft Ireland Operations Limited, One Microsoft Place, South County Business Park, Leopardstown, Dublin 18, Ireland',
				'category' => 'statistics',
				'purpose'  => __( 'Records how the site is used - movement of the mouse, scrolling and clicks - so we can see where the shop is confusing.', 'px-shop-core' ),
				'privacy'  => 'https://privacy.microsoft.com/privacystatement',
				'mode'     => 'blocked',
				'fields'   => array(
					'id' => array(
						'title'       => __( 'Project ID', 'px-shop-core' ),
						'placeholder' => 'abcdefghij',
						'pattern'     => '/^[a-z0-9]+$/i',
						'required'    => true,
					),
				),
				'snippet'  => array( 'PX_Consent_Scripts', 'clarity_snippet' ),
				'cookies'  => array(
					array(
						'name'   => '_clck',
						'expiry' => __( '1 year', 'px-shop-core' ),
						'desc'   => __( 'Keeps the visitor id for the recording.', 'px-shop-core' ),
					),
					array(
						'name'   => '_clsk',
						'expiry' => __( '1 day', 'px-shop-core' ),
						'desc'   => __( 'Joins the page views of one visit.', 'px-shop-core' ),
					),
				),
				'clear'    => array( '_clck', '_clsk' ),
			),
			'smartsupp'   => array(
				'name'     => 'Smartsupp',
				'provider' => 'Smartsupp.com, s.r.o., Šumavská 524/31, 602 00 Brno, Czech Republic',
				'category' => 'statistics',
				'purpose'  => __( 'Live chat with the shop. It remembers the conversation between page views and records the visit, so the operator knows what you were looking at.', 'px-shop-core' ),
				'privacy'  => 'https://www.smartsupp.com/help/privacy/',
				'mode'     => 'blocked',
				'fields'   => array(
					'key' => array(
						'title'       => __( 'Chat key', 'px-shop-core' ),
						'placeholder' => '0123456789abcdef0123456789abcdef',
						'pattern'     => '/^[a-z0-9]+$/i',
						'required'    => true,
					),
				),
				'snippet'  => array( 'PX_Consent_Scripts', 'smartsupp_snippet' ),
				'note'     => __( 'Filed under statistics because Smartsupp also records the visit. A shop that only uses the chat itself can move it to preferences with the px_consent_services filter.', 'px-shop-core' ),
				'cookies'  => array(
					array(
						'name'   => 'ssupp.vid',
						'expiry' => __( '1 year', 'px-shop-core' ),
						'desc'   => __( 'Visitor id of the chat.', 'px-shop-core' ),
					),
					array(
						'name'   => 'ssupp.visits',
						'expiry' => __( '1 year', 'px-shop-core' ),
						'desc'   => __( 'Number of visits, shown to the operator.', 'px-shop-core' ),
					),
				),
				'clear'    => array( 'ssupp.*' ),
			),
			'heureka'     => array(
				'name'     => 'Heureka - Overené zákazníkmi',
				'provider' => 'Heureka Group a.s., Karolinská 706/3, 186 00 Praha 8, Czech Republic',
				'category' => 'statistics',
				'purpose'  => __( 'After an order it lets Heureka send you a questionnaire about the purchase and measures how many visits from Heureka end in an order.', 'px-shop-core' ),
				'privacy'  => 'https://www.heureka.sk/direct/i-portal/ochrana-osobnych-udajov/',
				'mode'     => 'declared',
				'note'     => __( 'The measuring code is inserted by the Heureka integration of the shop, not by this module. For it to wait for consent, give the script the attributes type="text/plain" and data-px-consent="statistics" data-px-service="heureka".', 'px-shop-core' ),
				'cookies'  => array(
					array(
						'name'   => '_hjid, hrk*',
						'expiry' => __( '1 year', 'px-shop-core' ),
						'desc'   => __( 'Joins the visit from Heureka to the order.', 'px-shop-core' ),
					),
				),
			),

			/* ---------------------------- Marketing --------------------------- */

			'google_ads'  => array(
				'name'     => 'Google Ads',
				'provider' => $google,
				'category' => 'marketing',
				'purpose'  => __( 'Measures which advertisement led to an order and allows us to show you our offer again on other sites.', 'px-shop-core' ),
				'privacy'  => 'https://policies.google.com/privacy',
				'mode'     => 'consent_mode',
				'fields'   => array(
					'id' => array(
						'title'       => __( 'Conversion ID', 'px-shop-core' ),
						'placeholder' => 'AW-XXXXXXXXX',
						'pattern'     => '/^AW-[A-Z0-9]+$/i',
						'required'    => true,
					),
				),
				'cookies'  => array(
					array(
						'name'   => '_gcl_au',
						'expiry' => __( '3 months', 'px-shop-core' ),
						'desc'   => __( 'Remembers the click on the advertisement that brought you here.', 'px-shop-core' ),
					),
					array(
						'name'   => 'IDE, test_cookie (doubleclick.net)',
						'expiry' => __( '13 months', 'px-shop-core' ),
						'desc'   => __( 'Third-party cookies of the advertising system, set on Google domains.', 'px-shop-core' ),
					),
				),
				'clear'    => array( '_gcl_au', '_gcl_aw', '_gcl_dc' ),
			),
			'meta_pixel'  => array(
				'name'     => 'Meta Pixel (Facebook, Instagram)',
				'provider' => 'Meta Platforms Ireland Limited, Merrion Road, Dublin 4, Ireland',
				'category' => 'marketing',
				'purpose'  => __( 'Measures the results of advertising on Facebook and Instagram and allows us to address you there with the products you looked at.', 'px-shop-core' ),
				'privacy'  => 'https://www.facebook.com/privacy/policy/',
				'mode'     => 'blocked',
				'fields'   => array(
					'id' => array(
						'title'       => __( 'Pixel ID', 'px-shop-core' ),
						'placeholder' => '123456789012345',
						'pattern'     => '/^[0-9]{5,20}$/',
						'required'    => true,
					),
				),
				'snippet'  => array( 'PX_Consent_Scripts', 'meta_pixel_snippet' ),
				'cookies'  => array(
					array(
						'name'   => '_fbp',
						'expiry' => __( '3 months', 'px-shop-core' ),
						'desc'   => __( 'Identifies the browser for advertising measurement.', 'px-shop-core' ),
					),
					array(
						'name'   => 'fr (facebook.com)',
						'expiry' => __( '3 months', 'px-shop-core' ),
						'desc'   => __( 'Third-party cookie of the advertising system on Meta domains.', 'px-shop-core' ),
					),
				),
				'clear'    => array( '_fbp', '_fbc' ),
			),
			'youtube'     => array(
				'name'     => 'YouTube',
				'provider' => $google,
				'category' => 'marketing',
				'purpose'  => __( 'Plays videos inserted into the pages. YouTube learns which video you played and can link it to your Google account.', 'px-shop-core' ),
				'privacy'  => 'https://policies.google.com/privacy',
				'mode'     => 'embed',
				// Default on: a video dropped into a page must not load before
				// consent, and nobody remembers to switch blocking on first.
				'default'  => 'yes',
				'cookies'  => array(
					array(
						'name'   => 'VISITOR_INFO1_LIVE',
						'expiry' => __( '6 months', 'px-shop-core' ),
						'desc'   => __( 'Player settings and measurement of playback.', 'px-shop-core' ),
					),
					array(
						'name'   => 'YSC',
						'expiry' => __( 'Session', 'px-shop-core' ),
						'desc'   => __( 'Counts the views of a video.', 'px-shop-core' ),
					),
				),
			),
			'google_maps' => array(
				'name'     => 'Google Maps',
				'provider' => $google,
				'category' => 'marketing',
				'purpose'  => __( 'Shows the interactive map with our address. Google learns that the map was loaded from this site.', 'px-shop-core' ),
				'privacy'  => 'https://policies.google.com/privacy',
				'mode'     => 'embed',
				'default'  => 'yes',
				'cookies'  => array(
					array(
						'name'   => 'NID, CONSENT (google.com)',
						'expiry' => __( '6 months', 'px-shop-core' ),
						'desc'   => __( 'Third-party cookies of Google needed to draw the map.', 'px-shop-core' ),
					),
				),
			),
		);
	}
}
