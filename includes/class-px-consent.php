<?php
/**
 * Cookie consent (CMP) - banner, storage, blocking, Consent Mode v2.
 *
 * The shop's own consent layer, so a legal requirement does not depend on a
 * third-party plugin, its licence and its markup. What it does:
 *
 *   - asks before anything that is not strictly necessary is stored
 *     (ePrivacy art. 5(3)): no tag of a consent-bound service runs first,
 *   - offers "Accept all", "Reject all" and "Settings" with the same weight
 *     in the first layer, and a per-category / per-service second layer
 *     (EDPB 03/2022 on dark patterns; no pre-ticked boxes, C-673/17),
 *   - stores the answer in a first-party cookie with the policy version, so
 *     bumping the version asks everyone again, and expires it after 182 days,
 *   - lets the answer be withdrawn in one click, from the footer link or the
 *     cookie policy page,
 *   - translates the answer into Google Consent Mode v2 signals and into
 *     `fbq('consent', ...)`.
 *
 * Deliberately outside this module: an IAB TCF string. Without TCF the shop
 * cannot serve AdSense or Ad Manager (Google has required a certified CMP
 * since 2024); GA4 and Ads conversion measurement are unaffected, which is
 * what our shops run.
 *
 * The consent cookie is never read in PHP. The output of a page is the same
 * for everyone and the decision is made in the browser, so a full-page cache
 * cannot serve one visitor's consent to another.
 *
 * Off by default, and it takes the whole layer with it: a module that is off
 * registers nothing, and the site is free to run an external CMP instead -
 * the theme tests class_exists( 'PX_Consent' ) exactly like it tests for the
 * wishlist.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once PX_SHOP_CORE_DIR . 'includes/consent/class-px-consent-services.php';
require_once PX_SHOP_CORE_DIR . 'includes/consent/class-px-consent-scripts.php';
require_once PX_SHOP_CORE_DIR . 'includes/consent/class-px-consent-embeds.php';
require_once PX_SHOP_CORE_DIR . 'includes/consent/class-px-consent-page.php';

class PX_Consent {

	/** First-party cookie holding the whole answer as JSON. */
	const COOKIE = 'px_consent';

	const OPT_VERSION     = 'px_consent_version';
	const OPT_PAGE        = 'px_consent_page_id';
	const OPT_GOOGLE_MODE = 'px_consent_google_mode';

	/** CNIL and the Slovak authority both land around six months. */
	const DEFAULT_DAYS = 182;

	/**
	 * Known consent plugins: folder in wp-content/plugins => name.
	 *
	 * Two banners on one page is worse than none - the visitor answers one
	 * and the other keeps asking, and neither knows what the other stored.
	 */
	const EXTERNAL_CMP = array(
		'complianz-gdpr'            => 'Complianz',
		'complianz-gdpr-premium'    => 'Complianz',
		'cookie-law-info'           => 'CookieYes',
		'cookieyes'                 => 'CookieYes',
		'cookiebot'                 => 'Cookiebot',
		'borlabs-cookie'            => 'Borlabs Cookie',
		'gdpr-cookie-compliance'    => 'Moove GDPR Cookie Compliance',
	);

	/**
	 * Did the module really take over the consent layer?
	 *
	 * @var bool
	 */
	private static $running = false;

	public static function init() {
		$external = self::external_cmp();

		// Stand down completely: the external CMP owns the banner, the
		// blocking and the consent signals, and this module would fight it.
		// The Complianz bridge is left alone in this case, so a site mid-way
		// through the migration keeps its Consent Mode.
		if ( '' !== $external ) {
			add_action( 'admin_notices', array( __CLASS__, 'external_cmp_notice' ) );

			return;
		}

		self::$running = true;

		// The bridge translates Complianz decisions for Google; this module
		// does the same from its own banner. Both at once would send two
		// `consent default` blocks and load gtag twice. The bridge checks the
		// same thing itself, so the order in the registry does not decide -
		// this only keeps the settings screen honest about what is loaded.
		add_filter( 'px_shop_core_module_on', array( __CLASS__, 'disable_bridge' ), 10, 2 );
		add_filter( 'px_shop_core_module_off_reason', array( __CLASS__, 'bridge_off_reason' ), 10, 2 );

		PX_Consent_Scripts::init();
		PX_Consent_Embeds::init();
		PX_Consent_Page::init();

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_banner' ), 5 );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
	}

	/* ------------------------------- Guards ------------------------------ */

	/**
	 * Is the consent layer actually being served?
	 *
	 * The class existing is not enough: it is also loaded when an external
	 * CMP stood the module down, and the px_consent_show filter can switch
	 * the layer off for a request. A theme that draws "Cookie settings" from
	 * a bare class_exists() would put a dead button in the footer, so this is
	 * the test to use - `class_exists( 'PX_Consent' ) && PX_Consent::active()`.
	 *
	 * @return bool
	 */
	public static function active() {
		return self::$running && self::enabled_here();
	}

	/**
	 * Is this module the one in charge of consent on this site?
	 *
	 * Unlike active() it says nothing about the current request - it is the
	 * question the Complianz bridge asks before deciding to stay quiet.
	 *
	 * @return bool
	 */
	public static function loaded() {
		return self::$running;
	}

	/**
	 * Name of the active external consent plugin, if any.
	 *
	 * Both tests are needed: the class/constant is the reliable one, but
	 * plugins are loaded alphabetically and a CMP whose folder sorts after
	 * ours would not be loaded yet when this runs.
	 *
	 * @return string Empty when none is active.
	 */
	public static function external_cmp() {
		if ( function_exists( 'cmplz_get_option' ) ) {
			return 'Complianz';
		}

		if ( defined( 'CLI_VERSION' ) || class_exists( 'Cookie_Law_Info' ) ) {
			return 'CookieYes';
		}

		if ( defined( 'COOKIEBOT_PLUGIN_VERSION' ) || class_exists( 'Cookiebot_WP' ) ) {
			return 'Cookiebot';
		}

		$active = (array) get_option( 'active_plugins', array() );

		// On multisite a CMP is usually activated for the whole network, and
		// then it is nowhere in active_plugins.
		if ( is_multisite() ) {
			$network = get_site_option( 'active_sitewide_plugins', array() );

			$active = array_merge( $active, array_keys( (array) $network ) );
		}

		foreach ( $active as $plugin ) {
			$folder = strtok( (string) $plugin, '/' );

			if ( isset( self::EXTERNAL_CMP[ $folder ] ) ) {
				return self::EXTERNAL_CMP[ $folder ];
			}
		}

		/**
		 * Filters the detected external CMP.
		 *
		 * Returning a non-empty string switches this module off; returning
		 * an empty string tells it the other plugin is harmless.
		 *
		 * @param string $name Name of the detected plugin.
		 */
		return (string) apply_filters( 'px_consent_external_cmp', '' );
	}

	/**
	 * Switches the Complianz bridge off while this module runs.
	 *
	 * @param bool   $on  Whether the module is on.
	 * @param string $key Module id.
	 * @return bool
	 */
	public static function disable_bridge( $on, $key ) {
		return 'consent_mode' === $key ? false : $on;
	}

	/**
	 * Why the bridge shows as off on the settings screen.
	 *
	 * Without it the screen would say "Overridden in code", which is true of
	 * the mechanism and useless to the person reading it.
	 *
	 * @param string $reason Reason so far.
	 * @param string $key    Module id.
	 * @return string
	 */
	public static function bridge_off_reason( $reason, $key ) {
		if ( 'consent_mode' !== $key ) {
			return $reason;
		}

		return __( 'Not loaded: the "Cookie consent" module sends the consent signals itself. Switch this one off.', 'px-shop-core' );
	}

	/* ------------------------------ Notices ------------------------------ */

	/**
	 * Says why the module looks switched on but draws nothing.
	 */
	public static function external_cmp_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		printf(
			/* translators: %s: name of the other consent plugin. */
			esc_html__( 'PX Shop Core: the "Cookie consent" module is switched on, but %s is active as well. Two consent banners would fight over one answer, so this module is doing nothing. Deactivate the other plugin, or switch this module off.', 'px-shop-core' ),
			esc_html( self::external_cmp() )
		);
		echo '</p></div>';
	}

	/**
	 * Two things worth saying on the settings screen: the bridge is now
	 * silent, and a service is switched on without its id.
	 */
	public static function setup_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'woocommerce_page_wc-settings' !== $screen->id ) {
			return;
		}

		$states = px_shop_core_module_states();

		if ( isset( $states['consent_mode'] ) && 'yes' === $states['consent_mode'] ) {
			echo '<div class="notice notice-info"><p>';
			esc_html_e( 'PX Shop Core: the "Google Consent Mode v2" module (the Complianz bridge) is not loaded - the "Cookie consent" module sends the signals itself. Switch the bridge off, so the screen does not promise something the site no longer does.', 'px-shop-core' );
			echo '</p></div>';
		}

		$missing = PX_Consent_Services::misconfigured();

		if ( $missing ) {
			echo '<div class="notice notice-warning"><p>';
			printf(
				/* translators: %s: comma separated service names. */
				esc_html__( 'PX Shop Core: these services are switched on but have no id filled in, so they are not loaded: %s', 'px-shop-core' ),
				esc_html( implode( ', ', $missing ) )
			);
			echo '</p></div>';
		}
	}

	/* ------------------------------ Settings ----------------------------- */

	/**
	 * Fields for the module's own section, built from the register.
	 *
	 * @return array
	 */
	public static function settings_fields() {
		$fields = array(
			array(
				'title' => __( 'Cookie consent', 'px-shop-core' ),
				'type'  => 'title',
				'desc'  => __( 'The shop\'s own consent banner. Switch on only the services the site really uses - each one adds a row to the banner, to the cookie policy page and, where it applies, a script to the page. Known limit: without IAB TCF certification this banner is not enough for AdSense or Ad Manager; GA4 and Ads conversion measurement are unaffected.', 'px-shop-core' ),
				'id'    => 'px_consent_options',
			),
			array(
				'title'    => __( 'Policy version', 'px-shop-core' ),
				'desc'     => __( 'Change it whenever a service or a purpose changes - everyone is asked again. Any short value works: 1, 2, 2026-08.', 'px-shop-core' ),
				'id'       => self::OPT_VERSION,
				'type'     => 'text',
				'default'  => '1',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Cookie policy page', 'px-shop-core' ),
				'desc'     => __( 'The banner links to it. The page holding [px_cookie_policy] is created as a draft when this module is switched on; publish it and link it from the footer.', 'px-shop-core' ),
				'id'       => self::OPT_PAGE,
				'type'     => 'single_select_page',
				'class'    => 'wc-enhanced-select-nostd',
				'css'      => 'min-width:300px;',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Google Consent Mode v2', 'px-shop-core' ),
				'desc'     => __( 'Send the consent signals to Google', 'px-shop-core' ),
				'id'       => self::OPT_GOOGLE_MODE,
				'type'     => 'checkbox',
				'default'  => 'yes',
				'desc_tip' => __( 'Keep it on even when the tags come from somewhere else - the "denied by default" state has to be in the page before any Google tag runs.', 'px-shop-core' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'px_consent_options',
			),
		);

		// One block per category, so the screen reads the way the banner does.
		foreach ( PX_Consent_Services::by_category() as $category => $services ) {
			$block = array();

			foreach ( $services as $id => $service ) {
				if ( ! empty( $service['always'] ) ) {
					continue;
				}

				$desc = $service['purpose'];

				if ( $service['note'] ) {
					$desc .= ' ' . $service['note'];
				}

				$block[] = array(
					'title'    => $service['name'],
					'desc'     => __( 'On', 'px-shop-core' ),
					'id'       => PX_Consent_Services::OPTION_PREFIX . $id,
					'type'     => 'checkbox',
					'default'  => $service['default'],
					'desc_tip' => $desc,
				);

				foreach ( $service['fields'] as $field_id => $field ) {
					$block[] = array(
						'title'             => $field['title'],
						'id'                => PX_Consent_Services::field_option( $id, $field_id ),
						'type'              => 'text',
						'desc_tip'          => isset( $field['desc'] ) ? $field['desc'] : '',
						'custom_attributes' => isset( $field['placeholder'] ) ? array( 'placeholder' => $field['placeholder'] ) : array(),
					);
				}
			}

			if ( ! $block ) {
				continue;
			}

			$categories = PX_Consent_Services::categories();
			$name       = isset( $categories[ $category ]['name'] ) ? $categories[ $category ]['name'] : $category;

			$fields[] = array(
				'title' => $name,
				'type'  => 'title',
				'id'    => 'px_consent_' . $category . '_options',
			);

			$fields = array_merge( $fields, $block );

			$fields[] = array(
				'type' => 'sectionend',
				'id'   => 'px_consent_' . $category . '_options',
			);
		}

		return $fields;
	}

	/* ------------------------------ Helpers ------------------------------ */

	/**
	 * Policy version stored in the cookie; a change asks everyone again.
	 *
	 * @return string
	 */
	public static function policy_version() {
		$version = trim( (string) get_option( self::OPT_VERSION, '1' ) );

		return '' !== $version ? $version : '1';
	}

	/**
	 * How long an answer is remembered.
	 *
	 * @return int Days.
	 */
	public static function lifetime_days() {
		/**
		 * Filters the lifetime of the consent cookie.
		 *
		 * @param int $days Days.
		 */
		return max( 1, (int) apply_filters( 'px_consent_lifetime_days', self::DEFAULT_DAYS ) );
	}

	/**
	 * URL of the cookie policy page.
	 *
	 * @return string Empty when no page is set - the banner then links to
	 *                nothing rather than to a 404.
	 */
	public static function page_url() {
		$page_id = (int) get_option( self::OPT_PAGE );
		$url     = $page_id ? get_permalink( $page_id ) : '';

		/**
		 * Filters the URL of the cookie policy page.
		 *
		 * @param string $url     Permalink.
		 * @param int    $page_id Page id from the settings.
		 */
		return (string) apply_filters( 'px_consent_page_url', $url ? $url : '', $page_id );
	}

	/**
	 * Absolute path of a template, theme override first.
	 *
	 * Override by copying the file to `yourtheme/px-shop-core/consent/<name>`
	 * (child theme wins over parent - locate_template checks both).
	 *
	 * @param string $name Path relative to templates/, e.g. consent/banner.php.
	 * @return string Empty when neither theme nor plugin has the file.
	 */
	public static function locate_template( $name ) {
		$name  = ltrim( (string) $name, '/' );
		$found = locate_template( array( 'px-shop-core/' . $name ) );

		if ( ! $found ) {
			$file = PX_SHOP_CORE_DIR . 'templates/' . $name;

			$found = file_exists( $file ) ? $file : '';
		}

		/**
		 * Filters the resolved template path.
		 *
		 * @param string $found Absolute path ('' when nothing was found).
		 * @param string $name  Template name relative to templates/.
		 */
		return (string) apply_filters( 'px_consent_locate_template', $found, $name );
	}

	/**
	 * Renders a template to string.
	 *
	 * The data arrives as one $args array, not as extracted variables: a
	 * template cannot then be surprised by a name it does not know about, and
	 * an override in a theme sees at a glance what it is given.
	 *
	 * @param string $name Path relative to templates/.
	 * @param array  $args Data for the template, read there as $args['key'].
	 * @return string
	 */
	public static function get_template( $name, $args = array() ) {
		$file = self::locate_template( $name );

		if ( ! $file ) {
			return '';
		}

		$args = (array) $args;

		ob_start();
		include $file;

		return (string) ob_get_clean();
	}

	/* ------------------------------- Front ------------------------------- */

	/**
	 * Should the layer be drawn on this request?
	 *
	 * @return bool
	 */
	public static function enabled_here() {
		/**
		 * Filters whether the consent layer is drawn.
		 *
		 * A landing page built for a campaign that must not show a banner is
		 * the exception, not the rule - switching it off does not make the
		 * blocking legal, it only hides the question.
		 *
		 * @param bool $show Whether to draw the banner and load the assets.
		 */
		return (bool) apply_filters( 'px_consent_show', ! is_admin() );
	}

	/**
	 * Layout CSS and the logic. The looks belong to the theme, which styles
	 * the px-consent-* classes; what is here only makes the banner usable on
	 * a site whose theme knows nothing about it.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::enabled_here() ) {
			return;
		}

		wp_enqueue_style( 'px-consent', plugins_url( 'assets/consent.css', PX_SHOP_CORE_FILE ), array(), PX_SHOP_CORE_VERSION );
		wp_enqueue_script( 'px-consent', plugins_url( 'assets/consent.js', PX_SHOP_CORE_FILE ), array(), PX_SHOP_CORE_VERSION, true );

		$services = array();

		foreach ( PX_Consent_Services::enabled() as $id => $service ) {
			$services[ $id ] = array(
				'category' => $service['category'],
				'mode'     => $service['mode'],
			);
		}

		$config = array(
			'cookie'     => self::COOKIE,
			'version'    => self::policy_version(),
			'days'       => self::lifetime_days(),
			'categories' => array_keys( PX_Consent_Services::categories() ),
			'locked'     => array_values( array_diff( array_keys( PX_Consent_Services::categories() ), PX_Consent_Services::optional_categories() ) ),
			'services'   => $services,
			'clear'      => PX_Consent_Services::clearable(),
			/**
			 * Reload the page when consent is withdrawn.
			 *
			 * A script that is already running cannot be unloaded; only a new
			 * page view is really free of it.
			 *
			 * @param bool $reload Whether to reload.
			 */
			'reload'     => (bool) apply_filters( 'px_consent_reload_on_revoke', true ),
		);

		wp_add_inline_script( 'px-consent', 'window.pxConsentConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * The banner and the settings modal.
	 *
	 * Always in the markup, hidden by [hidden]; JavaScript decides whether to
	 * show it. That keeps the page cacheable and stops the banner from
	 * flashing at a visitor who answered months ago.
	 *
	 * @return void
	 */
	public static function render_banner() {
		if ( ! self::enabled_here() ) {
			return;
		}

		echo PX_Consent::get_template( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
			'consent/banner.php',
			array(
				'categories' => PX_Consent_Services::categories(),
				'grouped'    => PX_Consent_Services::by_category( true ),
				'policy_url' => self::page_url(),
			)
		);
	}
}

if ( ! function_exists( 'px_consent_page_url' ) ) {
	/**
	 * URL of the cookie policy page, for themes that would rather not name
	 * the class.
	 *
	 * @return string
	 */
	function px_consent_page_url() {
		return PX_Consent::page_url();
	}
}
