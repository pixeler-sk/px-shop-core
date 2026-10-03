<?php
/**
 * Seller details in one place - trade name, registered address, company
 * and tax ids, register entry, contact and supervisory authority.
 *
 * A shop must show them on the website, in the terms and in order e-mails
 * (act 22/2004 § 4, act 108/2024). Typed by hand they end up in four
 * places and drift apart; here they are entered once and used through:
 *
 *   [px_seller]                         block for Contact page, footer, terms
 *   [px_seller field="ico"]             one value (inline, e.g. inside terms)
 *   [px_seller field="name,ico,dic"]    chosen lines
 *   {px_seller}                         one line in WooCommerce e-mail texts
 *                                       (footer, additional content)
 *   PX_Seller::get( 'ico' )             value for the theme or a site plugin
 *
 * The address falls back to the store address of WooCommerce (General
 * settings), so a shop whose seat and store are the same types it once.
 *
 * Markup is neutral (px-seller*), styling belongs to the theme.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Seller {

	const OPTION = 'px_seller';

	/** Fields of the default block, in order. */
	const BLOCK = array( 'name', 'address', 'ico', 'dic', 'ic_dph', 'register', 'email', 'phone' );

	public static function init() {
		add_shortcode( 'px_seller', array( __CLASS__, 'shortcode' ) );
		// Subjects, headings and additional content go through format_string,
		// the footer through its own filter.
		add_filter( 'woocommerce_email_format_string', array( __CLASS__, 'email_placeholder' ) );
		add_filter( 'woocommerce_email_footer_text', array( __CLASS__, 'email_placeholder' ) );
	}

	/* ------------------------------ Settings ------------------------------ */

	public static function settings_fields() {
		$text = static function ( $id, $title, $desc = '', $type = 'text' ) {
			return array(
				'title'    => $title,
				'desc'     => $desc,
				'desc_tip' => '' !== $desc,
				'id'       => self::OPTION . '[' . $id . ']',
				'type'     => $type,
				'css'      => 'textarea' === $type ? 'min-width:400px;height:4em' : 'min-width:400px',
			);
		};

		return array(
			array(
				'title' => __( 'Seller details', 'px-shop-core' ),
				'type'  => 'title',
				/* translators: %s: shortcode and placeholder examples. */
				'desc'  => sprintf( __( 'Entered once, shown wherever the law asks for them: %s.', 'px-shop-core' ), '<code>[px_seller]</code>, <code>[px_seller field="ico"]</code>, <code>{px_seller}</code>' ),
				'id'    => 'px_seller_options',
			),
			$text( 'name', __( 'Trade name', 'px-shop-core' ), __( 'Exactly as in the register, including the legal form (s. r. o.).', 'px-shop-core' ) ),
			$text( 'address', __( 'Registered address', 'px-shop-core' ), __( 'Empty uses the store address from WooCommerce → Settings → General.', 'px-shop-core' ), 'textarea' ),
			$text( 'ico', __( 'Company ID (IČO)', 'px-shop-core' ) ),
			$text( 'dic', __( 'Tax ID (DIČ)', 'px-shop-core' ) ),
			$text( 'ic_dph', __( 'VAT ID (IČ DPH)', 'px-shop-core' ), __( 'Leave empty when the seller is not a VAT payer.', 'px-shop-core' ) ),
			$text( 'register', __( 'Register entry', 'px-shop-core' ), __( 'E.g. "Commercial register of the City Court Bratislava III, section Sro, insert no. 12345/B" or the trade licence office.', 'px-shop-core' ), 'textarea' ),
			$text( 'email', __( 'Customer e-mail', 'px-shop-core' ) ),
			$text( 'phone', __( 'Customer phone', 'px-shop-core' ) ),
			$text( 'supervisor', __( 'Supervisory authority', 'px-shop-core' ), __( 'Slovak Trade Inspection inspectorate for the seat region, with its address. Used in the terms: [px_seller field="supervisor"].', 'px-shop-core' ), 'textarea' ),
			array(
				'type' => 'sectionend',
				'id'   => 'px_seller_options',
			),
		);
	}

	/* -------------------------------- Data -------------------------------- */

	/**
	 * Labels of the fields as shown in the block.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'name'       => '',
			'address'    => '',
			'ico'        => __( 'Company ID', 'px-shop-core' ),
			'dic'        => __( 'Tax ID', 'px-shop-core' ),
			'ic_dph'     => __( 'VAT ID', 'px-shop-core' ),
			'register'   => '',
			'email'      => __( 'E-mail', 'px-shop-core' ),
			'phone'      => __( 'Phone', 'px-shop-core' ),
			'supervisor' => __( 'Supervisory authority', 'px-shop-core' ),
		);
	}

	/**
	 * All details, or one of them.
	 *
	 * @param string $field Field id; '' returns the whole array.
	 * @return array|string
	 */
	public static function get( $field = '' ) {
		$stored = get_option( self::OPTION, array() );
		$data   = array();

		foreach ( array_keys( self::labels() ) as $key ) {
			$data[ $key ] = is_array( $stored ) && isset( $stored[ $key ] ) ? trim( (string) $stored[ $key ] ) : '';
		}

		if ( '' === $data['address'] ) {
			$data['address'] = self::store_address();
		}

		/**
		 * Filters the seller details - e.g. a site plugin that keeps them
		 * elsewhere.
		 *
		 * @param array $data Field id => value.
		 */
		$data = (array) apply_filters( 'px_seller_data', $data );

		if ( '' === $field ) {
			return $data;
		}

		return isset( $data[ $field ] ) ? (string) $data[ $field ] : '';
	}

	/**
	 * Store address from the WooCommerce general settings, one line per
	 * part ("Hlavná 1", "811 01 Bratislava", country outside the base).
	 *
	 * @return string
	 */
	private static function store_address() {
		if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
			return '';
		}

		$countries = WC()->countries;
		$street    = trim( $countries->get_base_address() . ' ' . $countries->get_base_address_2() );
		$city      = trim( $countries->get_base_postcode() . ' ' . $countries->get_base_city() );
		$country   = $countries->get_base_country();
		$names     = $countries->get_countries();

		$lines = array_filter( array( $street, $city, isset( $names[ $country ] ) ? $names[ $country ] : '' ) );

		// Without street and city the country alone is no address.
		return '' === $street && '' === $city ? '' : implode( "\n", $lines );
	}

	/* ------------------------------- Output ------------------------------- */

	/**
	 * [px_seller field="…" labels="yes|no"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'field'  => '',
				'labels' => 'yes',
			),
			$atts,
			'px_seller'
		);

		$fields = '' === $atts['field'] ? self::BLOCK : array_filter( array_map( 'trim', explode( ',', $atts['field'] ) ) );

		// One field = inline value, no wrapper - fits inside a sentence.
		if ( 1 === count( $fields ) ) {
			return nl2br( esc_html( self::get( reset( $fields ) ) ), false );
		}

		return self::html( $fields, 'no' !== $atts['labels'] );
	}

	/**
	 * Block of chosen fields as <address>, '' when all are empty.
	 *
	 * @param array $fields      Field ids in order.
	 * @param bool  $with_labels Prefix ids and contacts with their label.
	 * @return string
	 */
	public static function html( $fields = self::BLOCK, $with_labels = true ) {
		$data   = self::get();
		$labels = self::labels();
		$lines  = array();

		foreach ( $fields as $key ) {
			$value = isset( $data[ $key ] ) ? $data[ $key ] : '';
			if ( '' === $value ) {
				continue;
			}

			if ( 'email' === $key && is_email( $value ) ) {
				$html = '<a href="' . esc_url( 'mailto:' . $value ) . '">' . esc_html( $value ) . '</a>';
			} elseif ( 'phone' === $key ) {
				$html = '<a href="' . esc_url( 'tel:' . preg_replace( '/[^+0-9]/', '', $value ) ) . '">' . esc_html( $value ) . '</a>';
			} else {
				$html = nl2br( esc_html( $value ), false );
			}

			if ( 'name' === $key ) {
				$html = '<strong>' . $html . '</strong>';
			} elseif ( $with_labels && ! empty( $labels[ $key ] ) ) {
				$html = esc_html( $labels[ $key ] ) . ': ' . $html;
			}

			$lines[] = '<span class="px-seller__' . esc_attr( sanitize_html_class( $key ) ) . '">' . $html . '</span>';
		}

		if ( ! $lines ) {
			return '';
		}

		return '<address class="px-seller">' . implode( '<br>', $lines ) . '</address>';
	}

	/**
	 * {px_seller} in WooCommerce e-mail texts - one line separated by " · ".
	 *
	 * @param string $text E-mail text with placeholders.
	 * @return string
	 */
	public static function email_placeholder( $text ) {
		if ( ! is_string( $text ) || false === strpos( $text, '{px_seller}' ) ) {
			return $text;
		}

		$data   = self::get();
		$labels = self::labels();
		$parts  = array();

		foreach ( array( 'name', 'address', 'ico', 'dic', 'ic_dph', 'email', 'phone' ) as $key ) {
			if ( empty( $data[ $key ] ) ) {
				continue;
			}
			// Not escaped: the subject and plain-text e-mails would show
			// "&amp;"; WooCommerce passes the HTML footer through wp_kses_post.
			$value   = str_replace( "\n", ', ', (string) $data[ $key ] );
			$parts[] = ! empty( $labels[ $key ] ) && ! in_array( $key, array( 'email', 'phone' ), true ) ? $labels[ $key ] . ' ' . $value : $value;
		}

		return str_replace( '{px_seller}', implode( ' · ', $parts ), $text );
	}
}
