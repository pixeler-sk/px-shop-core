<?php
/**
 * Anti-spam challenge on public forms - Cloudflare Turnstile or Google
 * reCAPTCHA v2 (checkbox).
 *
 * One switch per place (context): back-in-stock waitlist, registration,
 * lost password, login, product reviews and the withdrawal / claim forms
 * of px-wc-requests. Each place prints the widget with field_html() and
 * checks the answer with verify() - the provider is a setting, the forms
 * do not care which one runs.
 *
 * Keys come from wp-config.php (PX_ANTISPAM_SITE_KEY, PX_ANTISPAM_SECRET_KEY,
 * optionally PX_ANTISPAM_PROVIDER) or, where wp-config is out of reach, from
 * the module settings. Without both keys the module does nothing, so
 * switching it on before the keys exist cannot lock customers out.
 *
 * The widget markup carries only the public site key, so pages with it stay
 * cacheable. The provider script loads only on pages that print a widget.
 *
 * Other plugins add their own place through the px_antispam_contexts
 * filter and call field_html() / verify() with its id.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Antispam {

	const PROVIDERS = array(
		'turnstile' => array(
			'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
			'verify' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			'field'  => 'cf-turnstile-response',
		),
		'recaptcha' => array(
			'script' => 'https://www.google.com/recaptcha/api.js',
			'verify' => 'https://www.google.com/recaptcha/api/siteverify',
			'field'  => 'g-recaptcha-response',
		),
	);

	public static function init() {
		if ( ! self::configured() ) {
			return;
		}

		if ( self::stored_on( 'register' ) ) {
			add_action( 'woocommerce_register_form', array( __CLASS__, 'echo_field_register' ) );
			add_filter( 'woocommerce_process_registration_errors', array( __CLASS__, 'check_wc_form_register' ) );
		}
		if ( self::stored_on( 'login' ) ) {
			add_action( 'woocommerce_login_form', array( __CLASS__, 'echo_field_login' ) );
			add_filter( 'woocommerce_process_login_errors', array( __CLASS__, 'check_wc_form_login' ) );
		}
		if ( self::stored_on( 'lost_password' ) ) {
			add_action( 'woocommerce_lostpassword_form', array( __CLASS__, 'echo_field_lost_password' ) );
			add_action( 'lostpassword_post', array( __CLASS__, 'check_lost_password' ) );
		}
		if ( self::stored_on( 'reviews' ) ) {
			add_filter( 'comment_form_submit_field', array( __CLASS__, 'review_field' ) );
			add_filter( 'preprocess_comment', array( __CLASS__, 'check_review' ) );
		}
		if ( self::stored_on( 'requests' ) ) {
			add_action( 'pxer_request_form_after_fields', array( __CLASS__, 'echo_field_requests' ) );
			add_filter( 'pxer_submit_check', array( __CLASS__, 'check_requests' ), 10, 2 );
		}
	}

	/* ------------------------------ Settings ------------------------------ */

	/**
	 * Places the challenge can guard, id => label (settings screen only).
	 *
	 * @return array
	 */
	public static function contexts() {
		$contexts = array(
			'waitlist'      => __( 'Back-in-stock waitlist', 'px-shop-core' ),
			'register'      => __( 'Registration (My account)', 'px-shop-core' ),
			'lost_password' => __( 'Lost password', 'px-shop-core' ),
			'reviews'       => __( 'Product reviews', 'px-shop-core' ),
			'requests'      => __( 'Withdrawal and claim forms (px-wc-requests)', 'px-shop-core' ),
			'login'         => __( 'Login (My account, checkout)', 'px-shop-core' ),
		);

		/**
		 * Filters the places the anti-spam challenge can guard.
		 *
		 * A plugin adds its own form here, then prints
		 * PX_Antispam::field_html( 'its-id' ) and checks
		 * PX_Antispam::verify( 'its-id' ).
		 *
		 * @param array $contexts Context id => label.
		 */
		return (array) apply_filters( 'px_antispam_contexts', $contexts );
	}

	/**
	 * Default state of a place. Login stays off: a security plugin
	 * (Wordfence) usually guards it already, and a challenge on every login
	 * annoys customers. No labels here - enabled_for() runs before init,
	 * when translations must not load yet.
	 *
	 * @param string $context Context id.
	 * @return string 'yes'|'no'.
	 */
	private static function default_state( $context ) {
		return 'login' === $context ? 'no' : 'yes';
	}

	public static function settings_fields() {
		$fields = array(
			array(
				'title' => __( 'Anti-spam', 'px-shop-core' ),
				'type'  => 'title',
				'desc'  => self::settings_intro(),
				'id'    => 'px_antispam_options',
			),
		);

		if ( ! defined( 'PX_ANTISPAM_PROVIDER' ) ) {
			$fields[] = array(
				'title'   => __( 'Service', 'px-shop-core' ),
				'id'      => 'px_antispam_provider',
				'type'    => 'select',
				'default' => 'turnstile',
				'options' => array(
					'turnstile' => __( 'Cloudflare Turnstile', 'px-shop-core' ),
					'recaptcha' => __( 'Google reCAPTCHA v2 (checkbox)', 'px-shop-core' ),
				),
			);
		}

		if ( ! defined( 'PX_ANTISPAM_SITE_KEY' ) ) {
			$fields[] = array(
				'title' => __( 'Site key', 'px-shop-core' ),
				'id'    => 'px_antispam_site_key',
				'type'  => 'text',
			);
		}

		if ( ! defined( 'PX_ANTISPAM_SECRET_KEY' ) ) {
			$fields[] = array(
				'title' => __( 'Secret key', 'px-shop-core' ),
				'desc'  => __( 'Prefer the PX_ANTISPAM_SECRET_KEY constant in wp-config.php - a key in the database travels with every backup and export.', 'px-shop-core' ),
				'id'    => 'px_antispam_secret_key',
				'type'  => 'password',
			);
		}

		$first = true;
		$last  = array_key_last( self::contexts() );

		foreach ( self::contexts() as $id => $label ) {
			$fields[] = array(
				'title'         => $first ? __( 'Protect', 'px-shop-core' ) : '',
				'desc'          => $label,
				'id'            => 'px_antispam_contexts[' . $id . ']',
				'type'          => 'checkbox',
				'default'       => self::default_state( $id ),
				'checkboxgroup' => $first ? 'start' : ( $last === $id ? 'end' : '' ),
			);
			$first    = false;
		}

		$fields[] = array(
			'type' => 'sectionend',
			'id'   => 'px_antispam_options',
		);

		return $fields;
	}

	private static function settings_intro() {
		$intro = __( 'A challenge on public forms against spam bots. Turnstile is free in any Cloudflare account and works even when the site does not run through Cloudflare (add the domain to a Turnstile widget). reCAPTCHA sets Google cookies - mention it in the privacy policy, and a consent tool (Complianz) may block it until consent, leaving the forms unusable; prefer Turnstile there. The checkout is never guarded: a failed challenge there costs orders. Only the WooCommerce forms are guarded - wp-login.php is left to the security plugin (Wordfence). After changing the service or the keys, clear the page cache.', 'px-shop-core' );

		$locked = array();
		foreach ( array( 'PX_ANTISPAM_PROVIDER', 'PX_ANTISPAM_SITE_KEY', 'PX_ANTISPAM_SECRET_KEY' ) as $constant ) {
			if ( defined( $constant ) ) {
				$locked[] = $constant;
			}
		}
		if ( $locked ) {
			/* translators: %s: list of constant names. */
			$intro .= ' ' . sprintf( __( 'Set in wp-config.php: %s.', 'px-shop-core' ), implode( ', ', $locked ) );
		}

		if ( ! self::configured() ) {
			$intro .= ' <strong>' . esc_html__( 'Both keys are missing or incomplete - no form is guarded yet.', 'px-shop-core' ) . '</strong>';
		}

		return $intro;
	}

	/* ----------------------------- Configuration -------------------------- */

	/**
	 * @return string 'turnstile' | 'recaptcha'.
	 */
	public static function provider() {
		$provider = defined( 'PX_ANTISPAM_PROVIDER' ) ? (string) PX_ANTISPAM_PROVIDER : (string) get_option( 'px_antispam_provider', 'turnstile' );

		return isset( self::PROVIDERS[ $provider ] ) ? $provider : 'turnstile';
	}

	private static function site_key() {
		return trim( defined( 'PX_ANTISPAM_SITE_KEY' ) ? (string) PX_ANTISPAM_SITE_KEY : (string) get_option( 'px_antispam_site_key', '' ) );
	}

	private static function secret_key() {
		return trim( defined( 'PX_ANTISPAM_SECRET_KEY' ) ? (string) PX_ANTISPAM_SECRET_KEY : (string) get_option( 'px_antispam_secret_key', '' ) );
	}

	/**
	 * Both keys present - otherwise nothing is printed nor checked.
	 *
	 * @return bool
	 */
	public static function configured() {
		return '' !== self::site_key() && '' !== self::secret_key();
	}

	/**
	 * Place switched on in the settings (no filter) - decides which hooks
	 * init() adds. The px_antispam_required filter is applied later, in
	 * enabled_for(), when a theme has loaded too.
	 *
	 * @param string $context Context id.
	 * @return bool
	 */
	private static function stored_on( $context ) {
		$stored = get_option( 'px_antispam_contexts', array() );
		$state  = is_array( $stored ) && isset( $stored[ $context ] ) ? $stored[ $context ] : self::default_state( $context );

		return 'yes' === $state;
	}

	/**
	 * Is the challenge on for this place (and for this visitor)?
	 *
	 * @param string $context Context id.
	 * @return bool
	 */
	public static function enabled_for( $context ) {
		if ( ! self::configured() ) {
			return false;
		}

		/**
		 * Filters whether the challenge is required in a place.
		 *
		 * Decide by the place or the request, not by who is logged in: the
		 * waitlist posts to REST without a nonce, where every visitor is a
		 * guest - a widget hidden for a customer would then fail the check.
		 *
		 * @param bool   $required Whether the challenge is required.
		 * @param string $context  Context id.
		 */
		return (bool) apply_filters( 'px_antispam_required', self::stored_on( $context ), $context );
	}

	/* -------------------------------- Widget ------------------------------ */

	/**
	 * Widget markup, or '' when the place is not guarded.
	 *
	 * @param string $context Context id.
	 * @return string
	 */
	public static function field_html( $context ) {
		if ( ! self::enabled_for( $context ) ) {
			return '';
		}

		self::enqueue();

		return sprintf(
			'<div class="px-antispam px-antispam--%1$s" data-px-antispam="%1$s"><div class="px-antispam__widget" data-sitekey="%2$s"></div></div>',
			esc_attr( sanitize_html_class( $context ) ),
			esc_attr( self::site_key() )
		);
	}

	/**
	 * Provider script with explicit rendering - every widget gets its own id,
	 * so a form can reset just its widget after a failed AJAX submit (a
	 * token is valid once). Enqueued only by a page that prints a widget.
	 */
	private static function enqueue() {
		if ( wp_script_is( 'px-antispam', 'enqueued' ) ) {
			return;
		}

		$provider = self::provider();
		$api      = 'turnstile' === $provider ? 'turnstile' : 'grecaptcha';
		$args     = array(
			'render' => 'explicit',
			'onload' => 'pxAntispamLoad',
		);
		if ( 'recaptcha' === $provider ) {
			$args['hl'] = substr( determine_locale(), 0, 2 );
		}

		wp_register_script(
			'px-antispam',
			add_query_arg( $args, self::PROVIDERS[ $provider ]['script'] ),
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- third-party API, a version query would only break its cache.
			array(
				'in_footer' => true,
				'strategy'  => 'async',
			)
		);

		$js = sprintf(
			'window.pxAntispamLoad=function(){
if(!window.%1$s||!window.%1$s.render){return;}
document.querySelectorAll(".px-antispam__widget").forEach(function(el){
if(el.dataset.widgetId!==undefined){return;}
var o={sitekey:el.dataset.sitekey};%3$s
var id=window.%1$s.render(el,o);
if(id!==undefined&&id!==null){el.dataset.widgetId=String(id);}
});
};
document.addEventListener("DOMContentLoaded",function(){window.pxAntispamLoad();});
window.pxAntispamToken=function(form){
var i=form&&form.querySelector(".px-antispam [name=\"%2$s\"]");
return i?i.value:"";
};
window.pxAntispamReset=function(form){
var el=form&&form.querySelector(".px-antispam__widget");
if(el&&el.dataset.widgetId!==undefined&&window.%1$s){window.%1$s.reset(%4$s);}
};
if(window.jQuery){jQuery(document).on("pxer:failed",function(e,form){window.pxAntispamReset(form&&form.jquery?form[0]:form);});}',
			$api,
			self::PROVIDERS[ $provider ]['field'],
			'turnstile' === $provider ? 'o.size="flexible";' : '',
			'turnstile' === $provider ? 'el.dataset.widgetId' : 'parseInt(el.dataset.widgetId,10)'
		);

		// Before the API script: its onload callback must already exist. When
		// another plugin loaded the same API first, our onload may never run -
		// DOMContentLoaded renders the widgets then. pxAntispamLoad() is also
		// the public call for a form inserted later (quick view, AJAX).
		wp_add_inline_script( 'px-antispam', $js, 'before' );
		wp_enqueue_script( 'px-antispam' );
	}

	/* ------------------------------ Verification -------------------------- */

	/**
	 * Checks the answer for a place.
	 *
	 * @param string      $context Context id.
	 * @param string|null $token   Token; null reads it from $_POST under the
	 *                             provider's field name.
	 * @return true|WP_Error True when the place is not guarded or passed.
	 */
	public static function verify( $context, $token = null ) {
		if ( ! self::enabled_for( $context ) ) {
			return true;
		}

		$provider = self::provider();

		if ( null === $token ) {
			$field = self::PROVIDERS[ $provider ]['field'];
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the token is the check; forms carry their own nonce.
			$token = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}

		$error = new WP_Error( 'px_antispam', __( 'Please confirm that you are not a robot.', 'px-shop-core' ), array( 'status' => 400 ) );

		if ( '' === (string) $token ) {
			return $error;
		}

		$response = wp_remote_post(
			self::PROVIDERS[ $provider ]['verify'],
			array(
				'timeout' => 8,
				'body'    => array(
					'secret'   => self::secret_key(),
					'response' => (string) $token,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$reason = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response );

			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning( sprintf( 'Anti-spam verification unreachable (%s, %s): %s', $provider, $context, $reason ), array( 'source' => 'px-antispam' ) );
			}

			/**
			 * Filters whether a form goes through when the provider cannot be
			 * reached. True (default): an outage of the service must not stop
			 * customers; a few minutes without the challenge are the lesser
			 * evil.
			 *
			 * @param bool   $pass    Let the submission through.
			 * @param string $context Context id.
			 */
			return apply_filters( 'px_antispam_fail_open', true, $context ) ? true : $error;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['success'] ) ) {
			return true;
		}

		// A wrong secret or a domain missing in the widget refuses everyone -
		// log it; a bad or reused token is the normal "robot" case.
		$codes = isset( $body['error-codes'] ) ? array_diff( (array) $body['error-codes'], array( 'invalid-input-response', 'timeout-or-duplicate', 'missing-input-response' ) ) : array();
		if ( $codes && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( sprintf( 'Anti-spam refused (%s, %s): %s - check the keys and the widget domains.', $provider, $context, implode( ', ', $codes ) ), array( 'source' => 'px-antispam' ) );
		}

		return $error;
	}

	/* ------------------------------ Places -------------------------------- */

	public static function echo_field_register() {
		echo self::field_html( 'register' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in field_html().
	}

	public static function echo_field_login() {
		echo self::field_html( 'login' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in field_html().
	}

	public static function echo_field_lost_password() {
		echo self::field_html( 'lost_password' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in field_html().
	}

	public static function echo_field_requests() {
		echo self::field_html( 'requests' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in field_html().
	}

	/**
	 * Adds a failed check to the WooCommerce form errors.
	 *
	 * @param WP_Error $errors  Errors so far.
	 * @param string   $context Context id.
	 * @return WP_Error
	 */
	private static function add_error( $errors, $context ) {
		$result = self::verify( $context );

		if ( is_wp_error( $result ) && $errors instanceof WP_Error ) {
			$errors->add( $result->get_error_code(), $result->get_error_message() );
		}

		return $errors;
	}

	public static function check_wc_form_register( $errors ) {
		return self::add_error( $errors, 'register' );
	}

	public static function check_wc_form_login( $errors ) {
		return self::add_error( $errors, 'login' );
	}

	/**
	 * Lost password - WordPress fires this for wp-login.php too, where no
	 * widget is printed, so only the WooCommerce form is checked.
	 *
	 * @param WP_Error $errors Errors.
	 */
	public static function check_lost_password( $errors ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only detects which form posted; WooCommerce checks its nonce.
		if ( ! isset( $_POST['wc_reset_password'] ) ) {
			return;
		}

		self::add_error( $errors, 'lost_password' );
	}

	/**
	 * Widget above the submit button of the product review form.
	 *
	 * @param string $field Submit field markup.
	 * @return string
	 */
	public static function review_field( $field ) {
		if ( 'product' !== get_post_type() ) {
			return $field;
		}

		return self::field_html( 'reviews' ) . $field;
	}

	/**
	 * Product reviews - moderators (admin replies) and WP-CLI are left alone,
	 * everything else needs the answer, AJAX and REST included.
	 *
	 * @param array $data Comment data.
	 * @return array
	 */
	public static function check_review( $data ) {
		// Not is_admin(): that is true for every admin-ajax.php request, guests
		// included. Not REST either - products are in the comments endpoint.
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( 'moderate_comments' ) ) {
			return $data;
		}
		if ( empty( $data['comment_post_ID'] ) || 'product' !== get_post_type( (int) $data['comment_post_ID'] ) ) {
			return $data;
		}

		$result = self::verify( 'reviews' );

		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				esc_html__( 'Review not saved', 'px-shop-core' ),
				array(
					'response'  => 400,
					'back_link' => true,
				)
			);
		}

		return $data;
	}

	/**
	 * px-wc-requests submit (pxer_submit_check filter).
	 *
	 * @param true|WP_Error $ok     Result so far.
	 * @param array         $params Submitted fields.
	 * @return true|WP_Error
	 */
	public static function check_requests( $ok, $params ) {
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		$field = self::PROVIDERS[ self::provider() ]['field'];
		$token = isset( $params[ $field ] ) ? sanitize_text_field( wp_unslash( $params[ $field ] ) ) : '';

		return self::verify( 'requests', $token );
	}
}
