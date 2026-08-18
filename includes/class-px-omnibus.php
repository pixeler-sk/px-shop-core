<?php
/**
 * EU Omnibus - "lowest price in the last 30 days" for discounted products.
 *
 * Price history is recorded on every product/variation save (plus a lazy
 * check on single product views to catch scheduled sales that activate
 * without a save). When a product is on sale, the lowest price recorded
 * in the 30 days before the current sale started is displayed.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Omnibus {

	const META_KEY    = '_px_price_history';
	const WINDOW_DAYS = 30;
	const KEEP_DAYS   = 90;

	public static function init() {
		add_action( 'woocommerce_update_product', array( __CLASS__, 'record' ) );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'record' ) );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'record' ) );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'record' ) );

		// Scheduled sales flip the price without firing a save - self-heal on view.
		add_action( 'woocommerce_before_single_product', array( __CLASS__, 'record_current_view' ) );

		// Recording always runs; only the output is switchable. A shop that
		// already displays the Omnibus price through another plugin turns the
		// output off and still builds history, so it can switch over later
		// without a gap.
		if ( ! apply_filters( 'px_omnibus_display', true ) ) {
			return;
		}

		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_single' ), 11 );
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'append_to_variation' ), 10, 3 );
	}

	/**
	 * Record the current active price when it differs from the last entry.
	 *
	 * @param int|WC_Product $product_id Product ID or object.
	 */
	public static function record( $product_id ) {
		$product = $product_id instanceof WC_Product ? $product_id : wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$price = $product->get_price();
		if ( '' === $price || null === $price ) {
			return;
		}
		$price = (float) $price;

		$history = self::get_history( $product->get_id() );
		$last    = end( $history );
		if ( $last && abs( (float) $last['price'] - $price ) < 0.0001 ) {
			return;
		}

		$history[] = array(
			'price' => $price,
			'time'  => time(),
		);

		// Prune: keep everything inside KEEP_DAYS plus the last older entry
		// (the price that was in effect when entering the retention window).
		$cutoff = time() - self::KEEP_DAYS * DAY_IN_SECONDS;
		$older  = array();
		$recent = array();
		foreach ( $history as $entry ) {
			if ( $entry['time'] < $cutoff ) {
				$older[] = $entry;
			} else {
				$recent[] = $entry;
			}
		}
		if ( $older ) {
			array_unshift( $recent, end( $older ) );
		}

		update_post_meta( $product->get_id(), self::META_KEY, array_values( $recent ) );
	}

	public static function record_current_view() {
		global $product;
		if ( $product instanceof WC_Product ) {
			self::record( $product );
		}
	}

	public static function get_history( $product_id ) {
		$history = get_post_meta( $product_id, self::META_KEY, true );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Lowest price in the 30 days before the current sale price took effect.
	 *
	 * @param WC_Product $product Product (simple or variation).
	 * @return float|null Null when the product is not on sale.
	 */
	public static function get_lowest_price( $product ) {
		if ( ! $product->is_on_sale() ) {
			return null;
		}

		$current = (float) $product->get_price();
		$history = self::get_history( $product->get_id() );

		// Start of the streak during which the current price has applied.
		$streak_start = time();
		for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
			if ( abs( (float) $history[ $i ]['price'] - $current ) < 0.0001 ) {
				$streak_start = $history[ $i ]['time'];
			} else {
				break;
			}
		}

		$window_start        = $streak_start - self::WINDOW_DAYS * DAY_IN_SECONDS;
		$candidates          = array();
		$before_window_price = null;

		foreach ( $history as $entry ) {
			if ( $entry['time'] >= $streak_start ) {
				continue; // Current sale period itself does not count.
			}
			if ( $entry['time'] >= $window_start ) {
				$candidates[] = (float) $entry['price'];
			} else {
				$before_window_price = (float) $entry['price'];
			}
		}

		// Price that was in effect when the window opened.
		if ( null !== $before_window_price ) {
			$candidates[] = $before_window_price;
		}

		// No history yet (e.g. imported catalog) - the regular price is the
		// only known previous price.
		if ( ! $candidates ) {
			$regular = (float) $product->get_regular_price();
			if ( $regular > 0 ) {
				$candidates[] = $regular;
			}
		}

		if ( ! $candidates ) {
			return null;
		}

		return (float) apply_filters( 'px_omnibus_lowest_price', min( $candidates ), $product );
	}

	public static function get_html( $product ) {
		$lowest = self::get_lowest_price( $product );
		if ( null === $lowest ) {
			return '';
		}

		return '<div class="px-omnibus">' . sprintf(
			/* translators: %s: formatted price. */
			esc_html__( 'Lowest price in the last 30 days: %s', 'px-shop-core' ),
			wc_price( $lowest )
		) . '</div>';
	}

	public static function render_single() {
		global $product;
		if ( ! $product instanceof WC_Product || $product->is_type( 'variable' ) ) {
			return; // Variable products are handled per variation.
		}
		echo self::get_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function append_to_variation( $data, $variable, $variation ) {
		$html = self::get_html( $variation );

		// Themes that place the line themselves read this and switch the
		// append below off - appending would put the same sentence twice on
		// the page, once in their own slot and once inside the price.
		$data['px_omnibus_html'] = $html;

		if ( ! $html ) {
			return $data;
		}

		/**
		 * Filters whether the line is appended to the variation price HTML.
		 *
		 * @param bool       $append    Whether to append.
		 * @param WC_Product $variation Variation.
		 */
		if ( ! apply_filters( 'px_omnibus_variation_price_html', true, $variation ) ) {
			return $data;
		}

		// WooCommerce leaves price_html empty when every variation costs the
		// same, and that empty string is an instruction to the theme: keep
		// the price already on the page. Appending to it turns the
		// instruction into a price block that holds no price - the theme
		// dutifully renders it and the price disappears on selection.
		if ( '' === (string) $data['price_html'] ) {
			return $data;
		}

		$data['price_html'] .= $html;

		return $data;
	}
}
