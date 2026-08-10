<?php
/**
 * Free-shipping progress data.
 *
 * Detects the lowest "minimum order amount" among enabled free_shipping
 * methods across all shipping zones and exposes the cart progress towards
 * it. Rendering belongs to the theme (px_free_shipping_state()).
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Shipping_Bar {

	public static function init() {}

	/**
	 * Lowest free-shipping threshold, or 0 when free shipping is not offered.
	 *
	 * @return float
	 */
	public static function threshold() {
		static $threshold = null;

		if ( null !== $threshold ) {
			return apply_filters( 'px_free_shipping_threshold', $threshold );
		}

		$threshold = 0.0;

		$zones   = WC_Shipping_Zones::get_zones();
		$zones[] = array( 'zone_id' => 0 ); // "Rest of the world".

		foreach ( $zones as $zone_data ) {
			$zone = WC_Shipping_Zones::get_zone( $zone_data['zone_id'] );
			if ( ! $zone ) {
				continue;
			}

			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				if ( 'free_shipping' !== $method->id ) {
					continue;
				}
				if ( ! in_array( $method->get_option( 'requires' ), array( 'min_amount', 'either', 'both' ), true ) ) {
					continue;
				}

				$min = (float) $method->get_option( 'min_amount' );
				if ( $min > 0 && ( 0.0 === $threshold || $min < $threshold ) ) {
					$threshold = $min;
				}
			}
		}

		return apply_filters( 'px_free_shipping_threshold', $threshold );
	}

	/**
	 * Cart progress towards free shipping.
	 *
	 * @return array|null Null when free shipping is not offered;
	 *                    otherwise [ threshold, remaining, progress 0-100 ].
	 */
	public static function state() {
		if ( ! WC()->cart ) {
			return null;
		}

		$threshold = self::threshold();
		if ( $threshold <= 0 ) {
			return null;
		}

		// Mirrors WC_Shipping_Free_Shipping: displayed subtotal minus discounts.
		$subtotal = (float) WC()->cart->get_displayed_subtotal();
		if ( WC()->cart->display_prices_including_tax() ) {
			$subtotal -= (float) ( WC()->cart->get_discount_total() + WC()->cart->get_discount_tax() );
		} else {
			$subtotal -= (float) WC()->cart->get_discount_total();
		}

		$remaining = max( 0.0, $threshold - $subtotal );

		return array(
			'threshold' => $threshold,
			'remaining' => $remaining,
			'progress'  => (int) min( 100, round( ( $threshold - $remaining ) / $threshold * 100 ) ),
		);
	}
}

/**
 * Theme-facing helper.
 *
 * @return array|null See PX_Shipping_Bar::state().
 */
function px_free_shipping_state() {
	return PX_Shipping_Bar::state();
}
