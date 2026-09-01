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

	/**
	 * IDs the last scan found on sale, so the next one can also look at
	 * whatever fell out of the sale meanwhile - see scan().
	 */
	const SEEN_OPTION = 'px_omnibus_scan_seen';

	/** Product types that have no price of their own, only a derived range. */
	const DERIVED_TYPES = array( 'variable', 'grouped' );

	public static function init() {
		add_action( 'woocommerce_update_product', array( __CLASS__, 'record' ) );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'record' ) );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'record' ) );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'record' ) );

		// A scheduled sale flips the price outside any save that carries the
		// new value: WooCommerce writes _price with update_post_meta() after
		// $product->save() has already fired its hooks. These hooks carry the
		// exact IDs, so they are cheap - but a shop that sets no sale dates
		// (and shops driven by an import usually do not) never fires them.
		// They are a bonus, not the mechanism.
		add_action( 'wc_product_start_scheduled_sale', array( __CLASS__, 'record' ), 20 );
		add_action( 'wc_product_end_scheduled_sale', array( __CLASS__, 'record' ), 20 );
		add_action( 'wc_after_products_starting_sales', array( __CLASS__, 'record_ids' ) );
		add_action( 'wc_after_products_ending_sales', array( __CLASS__, 'record_ids' ) );

		// And the mechanism: a daily pass over everything on sale. The 30-day
		// minimum is a legal statement, so it must not depend on someone
		// opening the product page at the right moment - with a page cache
		// the page opens without PHP most of the time.
		//
		// It gets its own cron event rather than riding on
		// woocommerce_scheduled_sales: that one runs inside Action Scheduler,
		// where a job over the whole catalog would sit in someone else's
		// queue and, past action_scheduler_failure_period (300 s), get the
		// host action marked as failed.
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
	 * Record the current catalog price when it differs from the last entry.
	 *
	 * PRICE BASE: 'edit', i.e. the price stored on the product, with no
	 * filters on woocommerce_product_get_price applied. This is the whole
	 * point, not a detail:
	 *
	 * - A sitewide campaign plugin (Global Shop Discount and friends) hooks
	 *   that filter at PHP_INT_MAX whenever ! is_admin() || DOING_AJAX. An
	 *   admin save is therefore NOT discounted, while a front-end view and a
	 *   WP-Cron request ARE - and wp-cron.php is not admin. Reading the
	 *   'view' price would put two incomparable numbers into one history and
	 *   make the streak in get_lowest_price() meaningless.
	 * - 'edit' is reproducible: the same value whoever reads it, whenever,
	 *   with or without a session, a campaign or a tax context.
	 * - And it is the right subject: the 30-day minimum is a statement about
	 *   the price of the product, not about an announced blanket campaign,
	 *   which is a separate construct with its own announcement. A shop that
	 *   sees it the other way round changes what is DISPLAYED through
	 *   px_omnibus_lowest_price - not what is recorded.
	 *
	 * Products whose price is only derived (variable, grouped) are skipped -
	 * see DERIVED_TYPES.
	 *
	 * @param int|WC_Product $product_id Product ID or object.
	 */
	public static function record( $product_id ) {
		$product = $product_id instanceof WC_Product ? $product_id : wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		if ( $product->is_type( self::DERIVED_TYPES ) ) {
			return;
		}

		$price = $product->get_price( 'edit' );
		if ( '' === $price || null === $price ) {
			return;
		}

		self::record_price( $product->get_id(), (float) $price );
	}

	/**
	 * Records an explicit list of IDs - the WooCommerce sale hooks hand them
	 * over, so there is nothing to look up.
	 *
	 * @param int[] $ids Product and variation IDs.
	 * @return int Number of products whose history got a new entry.
	 */
	public static function record_ids( $ids ) {
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );

		if ( ! $ids ) {
			return 0;
		}

		$written = 0;

		foreach ( array_chunk( self::filter_eligible( $ids ), self::SCAN_CHUNK ) as $chunk ) {
			$written += self::record_chunk( $chunk );
		}

		return $written;
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
			if ( ! isset( $entry['time'], $entry['price'] ) ) {
				continue; // Malformed leftover - dropping it is the repair.
			}

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
	 * Records the current price of everything that is on sale - and of
	 * everything that WAS on sale at the last scan and is not any more.
	 *
	 * That second half is the whole trick. A shop where discounts come from
	 * an import has no _sale_price_dates_* at all: the importer simply drops
	 * _sale_price, the product falls out of onsale, and no hook fires. Yet
	 * the return to the normal price is exactly the entry the history needs -
	 * without it the record would keep claiming the sale price is current,
	 * and the next discount would be measured against it. So the scan
	 * remembers what it saw (SEEN_OPTION) and the next run walks the
	 * difference.
	 *
	 * Writes only where the price actually moved - on a quiet day it is a
	 * handful of queries and no writes at all.
	 *
	 * @return int Number of products whose history got a new entry.
	 */
	public static function scan() {
		$on_sale = self::on_sale_ids();

		// Fell out of the sale since the last run, plus anything with a sale
		// window around today (shops that do use sale dates). Both lists come
		// from outside the on-sale query, so they go through the same
		// eligibility filter.
		$extra = array_merge( array_diff( self::seen_ids(), $on_sale ), self::sale_window_ids() );
		$extra = $extra ? self::filter_eligible( array_unique( $extra ) ) : array();

		$ids = array_values( array_unique( array_merge( $on_sale, $extra ) ) );

		/**
		 * Filters the products the daily scan records.
		 *
		 * @param int[] $ids     Product and variation IDs.
		 * @param int[] $on_sale The subset that is on sale right now.
		 */
		$ids = (array) apply_filters( 'px_omnibus_scan_ids', $ids, $on_sale );
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

		// Znova cez kontrolu vhodnosti: ID pridané filtrom by inak obišli
		// vylúčenie odvodených typov a neexistujúcich postov úplne.
		$ids = self::filter_eligible( $ids );

		/**
		 * Filters the ceiling on how many products one scan takes.
		 *
		 * Default is none. A cap would cut the list the same way every night
		 * (ordered by ID), so the same products at the end of the catalog
		 * would never get a record - and the 30-day minimum is a legal
		 * statement, not a nice-to-have. The queries are indexed and the loop
		 * writes only where the price moved, so a big sale costs seconds.
		 *
		 * The cap applies to the finished list, not to each query.
		 *
		 * @param int $limit Maximum number of products per run, 0 for none.
		 */
		$limit = max( 0, (int) apply_filters( 'px_omnibus_scan_limit', 0 ) );

		if ( $limit > 0 && count( $ids ) > $limit ) {
			$ids = array_slice( $ids, 0, $limit );
		}

		$written = 0;

		foreach ( array_chunk( $ids, self::SCAN_CHUNK ) as $chunk ) {
			$written += self::record_chunk( $chunk );
		}

		// Remembered for the next run, not autoloaded - it is read once a day
		// by cron and nowhere else. Stored as a plain list of IDs, which is
		// roughly a third of the size of a serialized array.
		update_option( self::SEEN_OPTION, implode( ',', $on_sale ), false );

		return $written;
	}

	/**
	 * IDs the previous run found on sale.
	 *
	 * @return int[]
	 */
	protected static function seen_ids() {
		$stored = (string) get_option( self::SEEN_OPTION, '' );

		if ( '' === $stored ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', explode( ',', $stored ) ) ) );
	}

	/**
	 * Everything on sale right now.
	 *
	 * wc_product_meta_lookup is one row per product AND per variation, so
	 * this is a single indexed read instead of a meta_query over postmeta.
	 *
	 * @return int[]
	 */
	protected static function on_sale_ids() {
		global $wpdb;

		$lookup = isset( $wpdb->wc_product_meta_lookup ) ? $wpdb->wc_product_meta_lookup : $wpdb->prefix . 'wc_product_meta_lookup';

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			"SELECT l.product_id
			 FROM {$lookup} l
			 INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
			 LEFT JOIN {$wpdb->posts} pp ON pp.ID = p.post_parent
			 WHERE l.onsale = 1
			 AND " . self::eligibility_sql() . '
			 ORDER BY l.product_id ASC'
		);

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * Products whose sale window opened or closed around today.
	 *
	 * Only shops that actually set sale dates have any; where discounts come
	 * from an import this returns nothing, and the drop-out list in scan()
	 * does the work instead.
	 *
	 * @return int[]
	 */
	protected static function sale_window_ids() {
		global $wpdb;

		$window = self::SCAN_WINDOW_DAYS * DAY_IN_SECONDS;

		$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key IN ( '_sale_price_dates_from', '_sale_price_dates_to' )
			 AND meta_value BETWEEN %d AND %d",
			time() - $window,
			time() + $window
		) );

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * Keeps only IDs worth a history entry.
	 *
	 * @param int[] $ids Candidate IDs.
	 * @return int[]
	 */
	protected static function filter_eligible( array $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( ! $ids ) {
			return array();
		}

		// Chunked: when a campaign over the whole catalog ends, the drop-out
		// list is thousands of IDs and a single IN () would be a query the
		// size of a small file.
		$kept = array();

		foreach ( array_chunk( $ids, self::SCAN_CHUNK ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			$found = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->posts} pp ON pp.ID = p.post_parent
				 WHERE p.ID IN ( {$placeholders} )
				 AND " . self::eligibility_sql(),
				$chunk
			) );

			$kept = array_merge( $kept, (array) $found );
		}

		return array_values( array_filter( array_map( 'absint', $kept ) ) );
	}

	/**
	 * What makes a row worth recording, as one SQL fragment shared by both
	 * queries. Expects `p` (the product) and `pp` (its parent) to be joined.
	 *
	 * Two things are filtered out:
	 *
	 * - Drafts and trash. A price nobody can buy is not a price history.
	 *   A variation keeps post_status 'publish' when its parent goes to the
	 *   trash, hence the parent check as well.
	 * - Variable and grouped parents. They have no price of their own, only
	 *   a range derived from their children - and WooCommerce stores that
	 *   range as SEVERAL _price rows on the same post (add_post_meta in a
	 *   loop over the sorted prices). Reading "the" _price of such a product
	 *   in SQL is a coin toss between the lowest and the highest, so it must
	 *   not be read at all. Nothing displays it either: render_single()
	 *   skips variable products and append_to_variation() reads the
	 *   variation.
	 *
	 * @return string
	 */
	private static function eligibility_sql() {
		global $wpdb;

		$types = "'" . implode( "', '", array_map( 'esc_sql', self::DERIVED_TYPES ) ) . "'";

		return "p.post_status IN ( 'publish', 'private' )
			 AND ( p.post_parent = 0 OR pp.post_status IN ( 'publish', 'private' ) )
			 AND NOT EXISTS (
			     SELECT 1 FROM {$wpdb->term_relationships} tr
			     INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			     INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			     WHERE tr.object_id = p.ID
			     AND tt.taxonomy = 'product_type'
			     AND t.slug IN ( {$types} )
			 )";
	}

	/**
	 * Records one batch: one query for the prices and histories, then a write
	 * per product whose price moved.
	 *
	 * Reads raw _price, which is the same number as get_price( 'edit' ) - see
	 * record() for why the history must not be built on the filtered price.
	 * Products with more than one _price row never get here; eligibility_sql()
	 * keeps them out.
	 *
	 * @param int[] $ids Product and variation IDs.
	 * @return int Number of products whose history got a new entry.
	 */
	protected static function record_chunk( array $ids ) {
		global $wpdb;

		if ( ! $ids ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
			 WHERE meta_key IN ( '_price', %s ) AND post_id IN ( {$placeholders} )
			 ORDER BY meta_id ASC",
			array_merge( array( self::META_KEY ), $ids )
		) );

		$prices    = array();
		$histories = array();

		foreach ( (array) $rows as $row ) {
			$id = (int) $row->post_id;

			if ( '_price' === $row->meta_key ) {
				// Prvý riadok, nie posledný: keby sa sem cez filter predsa len
				// dostal post s viacerými `_price` (variabilný rodič), dá to tú
				// istú hodnotu ako `get_price( 'edit' )`, teda najnižšiu.
				if ( ! isset( $prices[ $id ] ) ) {
					$prices[ $id ] = $row->meta_value;
				}
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
	 * Reads the price in the 'edit' context, the same base the history is
	 * written in - see record(). This is not a detail either: with a sitewide
	 * campaign plugin active, the 'view' price on the front end is the
	 * discounted one, it would never match any entry, and the streak below
	 * would collapse to "started now" on every single product.
	 *
	 * Odvodené typy sem nepatria z toho istého dôvodu, pre ktorý sa im história
	 * ani nezapisuje: variabilný rodič nemá vlastnú cenu, len odvodenú z variácií.
	 * Bez tejto poistky by sa im história zamrazila a pri prvom vykreslení detailu
	 * (kým JS nedoplní vybranú variáciu) by sa vypísalo číslo zo starých dát.
	 *
	 * `is_on_sale( 'edit' )` je tu z rovnakého dôvodu ako `get_price( 'edit' )`
	 * nižšie: vo `view` kontexte prechádza cena cez filtre plošných kampaní,
	 * a plugin sitewide zľavy vracia pre prázdnu `sale_price` hodnotu 0, takže
	 * `is_on_sale()` by počas kampane vrátilo true na každom produkte a Omnibus
	 * riadok by sa vypísal aj na tovare, ktorý v akcii nie je.
	 *
	 * @param WC_Product $product Product (simple or variation).
	 * @return float|null Null when the product is not on sale.
	 */
	public static function get_lowest_price( $product ) {
		if ( $product->is_type( self::DERIVED_TYPES ) || ! $product->is_on_sale( 'edit' ) ) {
			return null;
		}

		$current = (float) $product->get_price( 'edit' );
		$history = self::get_history( $product->get_id() );

		// Start of the streak during which the current price has applied.
		$streak_start = time();
		for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
			if ( ! isset( $history[ $i ]['price'], $history[ $i ]['time'] ) ) {
				break;
			}

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
			if ( ! isset( $entry['price'], $entry['time'] ) ) {
				continue;
			}

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
		// only known previous price. 'edit' again, for the same reason.
		if ( ! $candidates ) {
			$regular = (float) $product->get_regular_price( 'edit' );
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
