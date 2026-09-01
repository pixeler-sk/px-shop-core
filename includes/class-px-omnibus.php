<?php
/**
 * EU Omnibus - "lowest price in the last 30 days" for discounted products.
 *
 * Price history is recorded on every product/variation save. A scheduled
 * sale flips the price without a save that carries the new value, so a
 * daily scan goes over everything on sale (and everything whose sale window
 * just opened or closed) and records what is missing. The lazy check on
 * single product views stays as a rescue, but it cannot be relied on: with
 * a page cache in front, PHP only runs on a cache miss, and the misses are
 * never where the history needs them.
 *
 * When a product is on sale, the lowest price recorded in the 30 days
 * before the current sale started is displayed.
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

	/** Daily scan. The name is repeated as a literal in includes/modules.php. */
	const CRON_HOOK = 'px_omnibus_scan';

	/** How far around today the scan looks for sale windows opening/closing. */
	const SCAN_WINDOW_DAYS = 3;

	/** Products handled per query in the scan. */
	const SCAN_CHUNK = 200;

	public static function init() {
		add_action( 'woocommerce_update_product', array( __CLASS__, 'record' ) );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'record' ) );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'record' ) );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'record' ) );

		// A scheduled sale flips the price outside any save that carries the
		// new value: WooCommerce writes _price with update_post_meta() after
		// $product->save() has already fired its hooks. Whichever path the
		// installed version takes, the price change itself is recorded here.
		add_action( 'wc_product_start_scheduled_sale', array( __CLASS__, 'record' ), 20 );
		add_action( 'wc_product_end_scheduled_sale', array( __CLASS__, 'record' ), 20 );
		add_action( 'woocommerce_scheduled_sales', array( __CLASS__, 'scan' ), 20 );

		// And the backstop: a daily pass over everything on sale. The 30-day
		// minimum is a legal statement, so it must not depend on someone
		// opening the product page at the right moment - with a page cache
		// the page opens without PHP most of the time.
		add_action( self::CRON_HOOK, array( __CLASS__, 'scan' ) );
		self::maybe_schedule();

		// Kept as a rescue for anything the scan does not reach.
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

		self::record_price( $product->get_id(), (float) $price );
	}

	/**
	 * The write itself, without loading a product object.
	 *
	 * The scan works from plain meta rows, so it must be able to hand the
	 * history in - reading it back product by product would be a query per
	 * product on a job that runs over the whole sale.
	 *
	 * @param int        $product_id Product or variation ID.
	 * @param float      $price      Current active price.
	 * @param array|null $history    Already loaded history, null to read it.
	 * @return bool Whether a new entry was written.
	 */
	protected static function record_price( $product_id, $price, $history = null ) {
		if ( null === $history ) {
			$history = self::get_history( $product_id );
		}

		$last = end( $history );
		if ( $last && isset( $last['price'] ) && abs( (float) $last['price'] - $price ) < 0.0001 ) {
			return false;
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

		update_post_meta( $product_id, self::META_KEY, array_values( $recent ) );

		return true;
	}

	public static function record_current_view() {
		global $product;
		if ( $product instanceof WC_Product ) {
			self::record( $product );
		}
	}

	/* -------------------------- Scheduled scan --------------------------- */

	/**
	 * Books the daily scan. Time is 03:20 local: after the WooCommerce
	 * midnight sale job, outside the busy hours.
	 */
	protected static function maybe_schedule() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		$offset = (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
		$first  = (int) ( strtotime( 'tomorrow 03:20:00', (int) ( time() + $offset ) ) - $offset );

		wp_schedule_event( $first, 'daily', self::CRON_HOOK );
	}

	/**
	 * Records the current price of everything on sale (and everything whose
	 * sale window just opened or closed).
	 *
	 * Writes only where the price actually moved - on a normal day it is a
	 * handful of queries and no writes at all.
	 *
	 * @return int Number of products whose history got a new entry.
	 */
	public static function scan() {
		$ids = self::scan_ids();

		if ( ! $ids ) {
			return 0;
		}

		$written = 0;

		foreach ( array_chunk( $ids, self::SCAN_CHUNK ) as $chunk ) {
			$written += self::record_chunk( $chunk );
		}

		return $written;
	}

	/**
	 * Product and variation IDs the scan cares about.
	 *
	 * @return int[]
	 */
	protected static function scan_ids() {
		global $wpdb;

		$lookup = isset( $wpdb->wc_product_meta_lookup ) ? $wpdb->wc_product_meta_lookup : $wpdb->prefix . 'wc_product_meta_lookup';

		/**
		 * Filters the ceiling on how many products one scan takes.
		 *
		 * Default is none. A cap would cut the list the same way every
		 * night (ordered by ID), so the same products at the end of the
		 * catalog would never get a record - and the 30-day minimum is a
		 * legal statement, not a nice-to-have. Both queries are indexed and
		 * the loop writes only where the price moved, so a big sale costs
		 * seconds, not minutes.
		 *
		 * @param int $limit Maximum number of IDs per query, 0 for no limit.
		 */
		$limit     = max( 0, (int) apply_filters( 'px_omnibus_scan_limit', 0 ) );
		$limit_sql = $limit > 0 ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';

		// On sale right now. wc_product_meta_lookup is one row per product
		// AND per variation, so this is a single indexed read instead of a
		// meta_query over postmeta.
		$ids = $wpdb->get_col( "SELECT product_id FROM {$lookup} WHERE onsale = 1 ORDER BY product_id ASC" . $limit_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		// Sale windows around today. A sale that has ended is no longer
		// "onsale", yet its end is exactly the price change nobody recorded -
		// without it the history would keep claiming the sale price is the
		// current one, and the next discount would be measured against it.
		$window = self::SCAN_WINDOW_DAYS * DAY_IN_SECONDS;
		$dates  = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key IN ( '_sale_price_dates_from', '_sale_price_dates_to' )
			 AND meta_value BETWEEN %d AND %d
			 ORDER BY post_id ASC" . $limit_sql,
			time() - $window,
			time() + $window
		) );

		$ids = array_values( array_unique( array_map( 'absint', array_merge( (array) $ids, (array) $dates ) ) ) );

		/**
		 * Filters the products the daily scan records.
		 *
		 * @param int[] $ids Product and variation IDs.
		 */
		$ids = (array) apply_filters( 'px_omnibus_scan_ids', $ids );

		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Records one batch: two queries, then a write per product that moved.
	 *
	 * @param int[] $ids Product and variation IDs.
	 * @return int Number of products whose history got a new entry.
	 */
	protected static function record_chunk( array $ids ) {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
			 WHERE meta_key IN ( '_price', %s ) AND post_id IN ( {$placeholders} )",
			array_merge( array( self::META_KEY ), $ids )
		) );

		$prices    = array();
		$histories = array();

		foreach ( (array) $rows as $row ) {
			$id = (int) $row->post_id;

			if ( '_price' === $row->meta_key ) {
				$prices[ $id ] = $row->meta_value;
				continue;
			}

			$stored           = maybe_unserialize( $row->meta_value );
			$histories[ $id ] = is_array( $stored ) ? $stored : array();
		}

		$written = 0;

		foreach ( $ids as $id ) {
			if ( ! isset( $prices[ $id ] ) || '' === $prices[ $id ] || null === $prices[ $id ] ) {
				continue;
			}

			$history = isset( $histories[ $id ] ) ? $histories[ $id ] : array();

			if ( self::record_price( $id, (float) $prices[ $id ], $history ) ) {
				$written++;
			}
		}

		return $written;
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
