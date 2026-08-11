<?php
/**
 * WP-CLI: move Woodmart waitlist rows into px-shop-core product meta.
 *
 * Idempotent - an address already on a product's list is left alone, so a
 * second run reports zero changes.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Waitlist_CLI {

	/**
	 * Convert the Woodmart waitlist table to product meta.
	 *
	 * Rows carrying a variation ID land on the variation, which is where
	 * px-shop-core watches the stock status.
	 *
	 * ## OPTIONS
	 *
	 * [--table=<table>]
	 * : Source table. Defaults to <prefix>woodmart_waitlists.
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp px waitlist migrate --dry-run
	 *     wp px waitlist migrate
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function migrate( $args, $assoc_args ) {
		global $wpdb;

		$dry   = isset( $assoc_args['dry-run'] );
		$table = isset( $assoc_args['table'] ) ? $assoc_args['table'] : $wpdb->prefix . 'woodmart_waitlists';

		if ( $dry ) {
			WP_CLI::log( 'DRY RUN - nothing is written.' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from an operator-supplied option.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			WP_CLI::error( sprintf( 'Table %s does not exist.', $table ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY list_id" );

		$added    = 0;
		$existing = 0;
		$skipped  = 0;

		foreach ( $rows as $row ) {
			$product_id = (int) $row->product_id;
			if ( ! empty( $row->variation_id ) ) {
				$product_id = (int) $row->variation_id;
			}

			$email = sanitize_email( $row->user_email );

			if ( ! $email || ! is_email( $email ) || ! wc_get_product( $product_id ) ) {
				WP_CLI::log( sprintf( '  skipping row #%d - product %d or address is gone', $row->list_id, $product_id ) );
				++$skipped;
				continue;
			}

			$subscribers = PX_Waitlist::get_subscribers( $product_id );

			if ( isset( $subscribers[ $email ] ) ) {
				++$existing;
				continue;
			}

			$created = strtotime( $row->created_date_gmt ? $row->created_date_gmt : $row->created_date );

			$subscribers[ $email ] = array(
				'created'     => $created ? $created : time(),
				'confirmed'   => $row->confirmed ? ( $created ? $created : time() ) : 0,
				'confirm'     => $row->confirmed ? '' : $product_id . '.' . wp_generate_password( 20, false ),
				'unsubscribe' => $product_id . '.' . wp_generate_password( 20, false ),
				'user_id'     => (int) $row->user_id,
			);

			if ( ! $dry ) {
				update_post_meta( $product_id, PX_Waitlist::META_KEY, $subscribers );
			}
			++$added;
		}

		WP_CLI::log(
			sprintf(
				$dry ? '  %d would be added, %d already there, %d skipped' : '  %d added, %d already there, %d skipped',
				$added,
				$existing,
				$skipped
			)
		);

		WP_CLI::success( $dry ? 'Dry run finished.' : 'Waitlist converted.' );
	}
}
