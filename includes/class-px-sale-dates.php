<?php
/**
 * Sale validity - until when a discounted price applies.
 *
 * WooCommerce knows the end of a sale (_sale_price_dates_to) but never
 * shows it. This module resolves that date - including for variable
 * products, whose parent usually carries no date of its own - and hands
 * it to the theme as a timestamp.
 *
 * Not every shop discounts through native sale prices: plugins that cut
 * prices at runtime (woocommerce_product_get_sale_price and friends) leave
 * no date on the product at all. For those, the resolution falls back to a
 * shop-wide campaign end set in this module's settings, and the
 * px_sale_end_date filter can override the result for anything more
 * involved.
 *
 * Wording and markup belong to the theme, which reads get_end(); get_html()
 * is the fallback for themes that draw nothing of their own.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Sale_Dates {

	const OPT_GLOBAL_END    = 'px_sale_dates_global_end';
	const OPT_MAX_DAYS      = 'px_sale_dates_max_days';
	const OPT_COUNTDOWN_H   = 'px_sale_dates_countdown_hours';
	const OPT_SHOW_CARD     = 'px_sale_dates_show_card';
	const OPT_SHOW_SINGLE   = 'px_sale_dates_show_single';

	public static function init() {
		// Variations carry their own dates - a theme that redraws the summary
		// on variation change reads px_sale_end from the variation payload.
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'append_to_variation' ), 10, 3 );

		// Fallback output. px-shop-theme composes its own strip from get_end()
		// and never fires this hook, so nothing is drawn twice. Priority 10
		// puts the validity right under the price (also 10, hooked earlier by
		// WooCommerce) and above the Omnibus line at 11 - the term of the sale
		// belongs to the price, the 30-day low is a footnote to it.
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_single' ), 10 );
	}

	/* ------------------------------ Settings ----------------------------- */

	/**
	 * Fields for the module's section.
	 *
	 * @return array
	 */
	public static function settings_fields() {
		return array(
			array(
				'title' => __( 'Sale validity', 'px-shop-core' ),
				'type'  => 'title',
				'desc'  => __( 'Shows how long a discounted price applies. The date comes from the product (Sale price dates); products discounted by a plugin that sets no dates fall back to the shop-wide campaign end below.', 'px-shop-core' ),
				'id'    => 'px_sale_dates_options',
			),
			array(
				'title'   => __( 'Product cards', 'px-shop-core' ),
				'desc'    => __( 'Show the end of the sale on product cards', 'px-shop-core' ),
				'id'      => self::OPT_SHOW_CARD,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Product detail', 'px-shop-core' ),
				'desc'    => __( 'Show the end of the sale on the product page', 'px-shop-core' ),
				'id'      => self::OPT_SHOW_SINGLE,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'             => __( 'Live countdown under', 'px-shop-core' ),
				'desc'              => __( 'hours before the end. The date turns into a ticking countdown; 0 keeps the date.', 'px-shop-core' ),
				'id'                => self::OPT_COUNTDOWN_H,
				'type'              => 'number',
				'default'           => '48',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			),
			array(
				'title'             => __( 'Hide when further away than', 'px-shop-core' ),
				'desc'              => __( 'days. A sale running for months is not news; 0 always shows the date.', 'px-shop-core' ),
				'id'                => self::OPT_MAX_DAYS,
				'type'              => 'number',
				'default'           => '0',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			),
			array(
				'title'    => __( 'Shop-wide campaign end', 'px-shop-core' ),
				'desc'     => __( 'Used for products whose discount carries no date of its own - typically a sitewide discount plugin. Leave empty when every sale has its own dates.', 'px-shop-core' ),
				'id'       => self::OPT_GLOBAL_END,
				'type'     => 'datetime-local',
				'desc_tip' => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'px_sale_dates_options',
			),
		);
	}

	/**
	 * Is the end of the sale shown in this context?
	 *
	 * @param string $context 'card' | 'single'.
	 * @return bool
	 */
	public static function show( $context = 'single' ) {
		$option = 'card' === $context ? self::OPT_SHOW_CARD : self::OPT_SHOW_SINGLE;

		return 'yes' === get_option( $option, 'yes' );
	}

	/**
	 * Hours before the end at which the date becomes a live countdown.
	 *
	 * @return int Zero when the countdown is off.
	 */
	public static function countdown_hours() {
		return max( 0, (int) get_option( self::OPT_COUNTDOWN_H, 48 ) );
	}

	/* ------------------------------ Resolving ---------------------------- */

	/**
	 * End of the current sale.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return int|null Unix timestamp, or null when the product is not on
	 *                  sale, the sale has no known end, or the end is too
	 *                  far away to be worth showing.
	 */
	public static function get_end( $product ) {
		if ( ! $product instanceof WC_Product || ! $product->is_on_sale() ) {
			return null;
		}

		$end = self::from_product( $product );

		if ( ! $end ) {
			$end = self::global_end();
		}

		/**
		 * Filters the resolved end of the sale.
		 *
		 * The last word on the date: it supplies one where nothing else
		 * knows it (a campaign plugin with its own schedule) and suppresses
		 * one by returning 0.
		 *
		 * @param int        $end     Unix timestamp, 0 when unknown.
		 * @param WC_Product $product Product or variation.
		 */
		$end = (int) apply_filters( 'px_sale_end_date', (int) $end, $product );

		if ( $end <= time() ) {
			return null;
		}

		$max_days = (int) get_option( self::OPT_MAX_DAYS, 0 );

		if ( $max_days > 0 && $end - time() > $max_days * DAY_IN_SECONDS ) {
			return null;
		}

		return $end;
	}

	/**
	 * Date stored on the product itself.
	 *
	 * A variable product normally has no dates of its own - the sale ends
	 * when the last discounted variation stops being discounted. One
	 * open-ended discounted variation therefore leaves the whole sale
	 * open-ended.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return int Unix timestamp, 0 when unknown.
	 */
	private static function from_product( $product ) {
		$own = $product->get_date_on_sale_to();

		if ( $own ) {
			return (int) $own->getTimestamp();
		}

		if ( ! $product->is_type( 'variable' ) ) {
			return 0;
		}

		// Walking the variations costs one product load each - on an archive
		// full of discounted variable products that adds up, and the answer
		// cannot change within a single request.
		static $cache = array();

		$id = $product->get_id();

		if ( isset( $cache[ $id ] ) ) {
			return $cache[ $id ];
		}

		$end = 0;

		foreach ( $product->get_visible_children() as $child_id ) {
			$child = wc_get_product( $child_id );

			if ( ! $child || ! $child->is_on_sale() ) {
				continue;
			}

			$date = $child->get_date_on_sale_to();

			if ( ! $date ) {
				$cache[ $id ] = 0;

				return 0;
			}

			$end = max( $end, (int) $date->getTimestamp() );
		}

		$cache[ $id ] = $end;

		return $end;
	}

	/**
	 * Shop-wide campaign end from the settings.
	 *
	 * @return int Unix timestamp, 0 when not set.
	 */
	private static function global_end() {
		$value = (string) get_option( self::OPT_GLOBAL_END, '' );

		if ( '' === $value ) {
			return 0;
		}

		// The field stores wall-clock time ("2026-08-24T23:59"). Read in the
		// site's timezone, not UTC - a bare strtotime() would move the end of
		// the campaign by the offset.
		$date = date_create_immutable( $value, wp_timezone() );

		return $date ? (int) $date->getTimestamp() : 0;
	}

	/* ------------------------------- Output ------------------------------ */

	/**
	 * Neutral markup for themes that draw nothing of their own.
	 *
	 * The timestamp travels in the data attribute so a countdown can be
	 * computed in the browser - a full-page cache would otherwise serve a
	 * frozen one.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string Empty when there is no date to show.
	 */
	public static function get_html( $product ) {
		$end = self::get_end( $product );

		if ( ! $end ) {
			return '';
		}

		return sprintf(
			'<div class="px-sale-end" data-px-sale-end="%1$d" data-px-sale-countdown="%2$d"><time datetime="%3$s">%4$s</time></div>',
			$end,
			self::countdown_hours(),
			esc_attr( wp_date( 'c', $end ) ),
			esc_html(
				sprintf(
					/* translators: %s: date the sale price stops applying. */
					__( 'Sale price valid until %s', 'px-shop-core' ),
					wp_date( get_option( 'date_format' ), $end )
				)
			)
		);
	}

	public static function render_single() {
		global $product;

		if ( ! $product instanceof WC_Product || ! self::show( 'single' ) ) {
			return;
		}

		echo self::get_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * End of the sale for the selected variation.
	 *
	 * @param array                $data      Variation payload.
	 * @param WC_Product_Variable  $variable  Parent product.
	 * @param WC_Product_Variation $variation Variation.
	 * @return array
	 */
	public static function append_to_variation( $data, $variable, $variation ) {
		$end = self::get_end( $variation );

		$data['px_sale_end'] = $end ? $end : 0;

		return $data;
	}
}

if ( ! function_exists( 'px_sale_end' ) ) {
	/**
	 * End of the sale, for themes that would rather not name the class.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return int|null Unix timestamp or null.
	 */
	function px_sale_end( $product ) {
		return PX_Sale_Dates::get_end( $product );
	}
}
