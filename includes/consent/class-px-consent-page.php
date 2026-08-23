<?php
/**
 * The "Cookie policy" page: the shortcode and the page itself.
 *
 * Art. 13 GDPR wants the controller, the purpose, the storage period and the
 * recipients per service; ePrivacy wants the visitor to know what is stored
 * on their device before they decide. Both are answered from the register,
 * so the page cannot drift away from what the banner actually does - the
 * only thing an editor writes by hand is the introduction.
 *
 * The page is created as a draft on the first admin request after the module
 * is switched on, never published automatically: a legal page goes live when
 * a human has read it.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Consent_Page {

	/** Remembers that the page was offered, so a deleted page stays deleted. */
	const OPT_BOOTSTRAPPED = 'px_consent_page_created';

	public static function init() {
		add_shortcode( 'px_cookie_policy', array( __CLASS__, 'shortcode' ) );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'bootstrap' ) );
		}
	}

	/* ------------------------------- Page -------------------------------- */

	/**
	 * Creates the draft page once, right after the module is switched on.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		// Only for whoever could have switched the module on - an editor
		// opening wp-admin should not silently gain a new draft page.
		if ( get_option( self::OPT_BOOTSTRAPPED ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// The switch has just been flipped by a human in wp-admin; record the
		// attempt first, so a failure does not retry on every request.
		update_option( self::OPT_BOOTSTRAPPED, gmdate( 'c' ), false );

		$existing = (int) get_option( PX_Consent::OPT_PAGE );

		if ( $existing && get_post( $existing ) ) {
			return;
		}

		$title = __( 'Cookie policy', 'px-shop-core' );

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => $title,
				// From the title, not from a translated string of its own: a
				// slug is part of the URL, and a translator changing it would
				// silently move the page every site links to.
				'post_name'    => sanitize_title( $title ),
				'post_content' => self::default_content(),
			)
		);

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( PX_Consent::OPT_PAGE, (int) $page_id );
		}
	}

	/**
	 * Starting text of the page. Everything below the introduction is drawn
	 * by the shortcode from the register.
	 *
	 * @return string
	 */
	private static function default_content() {
		$blocks = array(
			'<!-- wp:paragraph --><p>' . __( 'Cookies are small files that a website stores in your browser. Some are needed for the shop to work at all - without them the cart would forget what you put in it. Others are only used with your consent: they measure how the site is used or make our advertising more relevant.', 'px-shop-core' ) . '</p><!-- /wp:paragraph -->',
			'<!-- wp:paragraph --><p>' . __( 'You decide about the optional ones when you first arrive and you can change your mind at any time - the button at the end of this page opens the settings again. Withdrawing consent is as easy as giving it and does not affect what happened before.', 'px-shop-core' ) . '</p><!-- /wp:paragraph -->',
			'<!-- wp:paragraph --><p>' . __( 'You also have the right to access your data, to have it corrected or erased, to restrict or object to its processing and to lodge a complaint with the supervisory authority. Details are in the privacy policy.', 'px-shop-core' ) . '</p><!-- /wp:paragraph -->',
			'<!-- wp:shortcode -->[px_cookie_policy]<!-- /wp:shortcode -->',
		);

		/**
		 * Filters the starting content of the cookie policy page.
		 *
		 * @param string $content Block markup.
		 */
		return apply_filters( 'px_consent_page_content', implode( "\n\n", $blocks ) );
	}

	/* ----------------------------- Shortcode ----------------------------- */

	/**
	 * [px_cookie_policy] - one table per service, grouped by category.
	 *
	 * @param array $atts Shortcode attributes:
	 *     all bool "yes" lists every known service, not only the enabled ones.
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		$atts = shortcode_atts( array( 'all' => 'no' ), (array) $atts, 'px_cookie_policy' );

		return PX_Consent::get_template(
			'consent/policy.php',
			array(
				'categories' => PX_Consent_Services::categories(),
				'grouped'    => PX_Consent_Services::by_category( 'yes' !== $atts['all'] ),
			)
		);
	}
}
