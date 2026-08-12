<?php
/**
 * WP-CLI: move Woodmart wishlist rows into px-shop-core user meta.
 *
 * Idempotent - a product already on a user's list is left alone, so a second
 * run reports zero changes.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Wishlist_CLI {

	/**
	 * Convert the Woodmart wishlist tables to user meta.
	 *
	 * Most rows in `woodmart_wishlists` are empty containers created on every
	 * visit, so the source is the products table - a wishlist with no products
	 * has nothing to move. Guest lists are skipped: Woodmart kept them against
	 * a session, px-shop-core keeps guests in a cookie, and there is no user
	 * to attach them to.
	 *
	 * ## OPTIONS
	 *
	 * [--lists-table=<table>]
	 * : Source table of lists. Defaults to <prefix>woodmart_wishlists.
	 *
	 * [--products-table=<table>]
	 * : Source table of list items. Defaults to <prefix>woodmart_wishlist_products.
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp px wishlist migrate --dry-run
	 *     wp px wishlist migrate
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function migrate( $args, $assoc_args ) {
		global $wpdb;

		$dry            = isset( $assoc_args['dry-run'] );
		$lists_table    = isset( $assoc_args['lists-table'] ) ? $assoc_args['lists-table'] : $wpdb->prefix . 'woodmart_wishlists';
		$products_table = isset( $assoc_args['products-table'] ) ? $assoc_args['products-table'] : $wpdb->prefix . 'woodmart_wishlist_products';

		if ( $dry ) {
			WP_CLI::log( 'DRY RUN - nothing is written.' );
		}

		foreach ( array( $lists_table, $products_table ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from an operator-supplied option.
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				WP_CLI::error( sprintf( 'Table %s does not exist.', $table ) );
			}
		}

		// Ordered by date so the newest additions survive the MAX cut below.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			"SELECT l.user_id, p.product_id
			 FROM `{$products_table}` p
			 INNER JOIN `{$lists_table}` l ON l.ID = p.wishlist_id
			 ORDER BY p.date_added"
		);

		if ( ! $rows ) {
			WP_CLI::success( 'Nothing to convert - no wishlist items found.' );
			return;
		}

		$by_user = array();

		foreach ( $rows as $row ) {
			$user_id = (int) $row->user_id;

			if ( $user_id <= 0 ) {
				continue;
			}

			$by_user[ $user_id ][] = (int) $row->product_id;
		}

		$users    = 0;
		$added    = 0;
		$existing = 0;
		$skipped  = 0;

		foreach ( $by_user as $user_id => $product_ids ) {
			if ( ! get_userdata( $user_id ) ) {
				WP_CLI::log( sprintf( '  skipping user #%d - the account is gone', $user_id ) );
				$skipped += count( $product_ids );
				continue;
			}

			$current = array_filter( array_map( 'absint', (array) get_user_meta( $user_id, PX_Wishlist::META_KEY, true ) ) );
			$before  = $current;

			foreach ( $product_ids as $product_id ) {
				if ( ! wc_get_product( $product_id ) ) {
					WP_CLI::log( sprintf( '  skipping product #%d for user #%d - the product is gone', $product_id, $user_id ) );
					++$skipped;
					continue;
				}

				if ( in_array( $product_id, $current, true ) ) {
					++$existing;
					continue;
				}

				$current[] = $product_id;
				++$added;
			}

			if ( $current === $before ) {
				continue;
			}

			++$users;

			if ( ! $dry ) {
				update_user_meta(
					$user_id,
					PX_Wishlist::META_KEY,
					array_slice( array_values( array_unique( $current ) ), - PX_Wishlist::MAX )
				);
			}
		}

		WP_CLI::log(
			sprintf(
				$dry
					? '  %d products would be added across %d users, %d already there, %d skipped'
					: '  %d products added across %d users, %d already there, %d skipped',
				$added,
				$users,
				$existing,
				$skipped
			)
		);

		WP_CLI::success( $dry ? 'Dry run finished.' : 'Wishlists converted.' );
	}
}
