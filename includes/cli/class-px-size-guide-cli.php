<?php
/**
 * WP-CLI: convert Woodmart size guides to px-shop-core ones.
 *
 * Idempotent - a second run reports zero changes. Every converted guide keeps
 * a _px_size_guide_source meta with the ID of the Woodmart post it came from,
 * which is what makes the command re-runnable and the mapping auditable.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Size_Guide_CLI {

	const SOURCE_META = '_px_size_guide_source';

	/**
	 * Convert Woodmart size guides, product assignments and category
	 * assignments to px-shop-core storage.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp px size-guide migrate --dry-run
	 *     wp px size-guide migrate
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function migrate( $args, $assoc_args ) {
		$dry = isset( $assoc_args['dry-run'] );

		if ( $dry ) {
			WP_CLI::log( 'DRY RUN - nothing is written.' );
		}

		$map = $this->migrate_guides( $dry );

		if ( ! $map ) {
			WP_CLI::warning( 'No Woodmart size guides found - nothing to convert.' );
			return;
		}

		$this->migrate_terms( $map, $dry );
		$this->migrate_products( $map, $dry );
		$this->report_unused( $map );

		WP_CLI::success( $dry ? 'Dry run finished.' : 'Size guides converted.' );
	}

	/**
	 * Copy the guide posts.
	 *
	 * @param bool $dry Dry run.
	 * @return array Woodmart guide ID => new guide ID.
	 */
	protected function migrate_guides( $dry ) {
		$legacy = get_posts(
			array(
				'post_type'      => PX_Size_Guide::LEGACY_POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
			)
		);

		$map = array();

		foreach ( $legacy as $post ) {
			$table = get_post_meta( $post->ID, PX_Size_Guide::LEGACY_TABLE, true );
			$table = is_array( $table ) ? $table : array();
			$hide  = 'hide' === get_post_meta( $post->ID, PX_Size_Guide::LEGACY_HIDE, true );

			if ( ! $table && '' === trim( $post->post_content ) ) {
				WP_CLI::log( sprintf( '  skipping #%d "%s" - empty', $post->ID, $post->post_title ) );
				continue;
			}

			$existing = $this->find_converted( $post->ID );

			if ( $existing ) {
				$map[ $post->ID ] = $existing;
				WP_CLI::log( sprintf( '  #%d "%s" already converted as #%d', $post->ID, $post->post_title, $existing ) );
				continue;
			}

			if ( $dry ) {
				$map[ $post->ID ] = -1;
				WP_CLI::log( sprintf( '  would convert #%d "%s" (%d rows)', $post->ID, $post->post_title, count( $table ) ) );
				continue;
			}

			$new_id = wp_insert_post(
				array(
					'post_type'    => PX_Size_Guide::POST_TYPE,
					'post_status'  => $post->post_status,
					'post_title'   => $post->post_title,
					'post_content' => $post->post_content,
				),
				true
			);

			if ( is_wp_error( $new_id ) ) {
				WP_CLI::warning( sprintf( 'could not convert #%d: %s', $post->ID, $new_id->get_error_message() ) );
				continue;
			}

			update_post_meta( $new_id, PX_Size_Guide::META_TABLE, $table );
			if ( $hide ) {
				update_post_meta( $new_id, PX_Size_Guide::META_HIDE, 'yes' );
			}
			update_post_meta( $new_id, self::SOURCE_META, $post->ID );

			$map[ $post->ID ] = $new_id;
			WP_CLI::log( sprintf( '  converted #%d "%s" -> #%d (%d rows)', $post->ID, $post->post_title, $new_id, count( $table ) ) );
		}

		return $map;
	}

	/**
	 * Name the guides nothing points at - usually demo content that came with
	 * the old theme and can be trashed by hand.
	 *
	 * @param array $map Guide ID map.
	 */
	protected function report_unused( $map ) {
		global $wpdb;

		foreach ( $map as $legacy_id => $new_id ) {
			$products = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
					PX_Size_Guide::LEGACY_PRODUCT,
					(string) $legacy_id
				)
			);
			$terms    = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s",
					PX_Size_Guide::LEGACY_TERM,
					(string) $legacy_id
				)
			);

			if ( ! $products && ! $terms ) {
				WP_CLI::log( sprintf( '  note: "%s" is used by no product and no category', get_the_title( $legacy_id ) ) );
			}
		}
	}

	/**
	 * @param int $legacy_id Woodmart guide ID.
	 * @return int New guide ID, or 0.
	 */
	protected function find_converted( $legacy_id ) {
		$found = get_posts(
			array(
				'post_type'      => PX_Size_Guide::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $legacy_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Category assignments.
	 *
	 * @param array $map Guide ID map.
	 * @param bool  $dry Dry run.
	 */
	protected function migrate_terms( $map, $dry ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value != ''",
				PX_Size_Guide::LEGACY_TERM
			)
		);

		$done = 0;
		foreach ( $rows as $row ) {
			$legacy_id = (int) $row->meta_value;
			if ( empty( $map[ $legacy_id ] ) ) {
				continue;
			}
			if ( '' !== get_term_meta( $row->term_id, PX_Size_Guide::META_LINK, true ) ) {
				continue;
			}

			if ( ! $dry ) {
				update_term_meta( $row->term_id, PX_Size_Guide::META_LINK, (string) $map[ $legacy_id ] );
			}
			++$done;
		}

		WP_CLI::log( sprintf( $dry ? '  %d categories would be linked' : '  %d categories linked', $done ) );
	}

	/**
	 * Product assignments. 'none' is not carried over - an absent meta means
	 * "inherit from category", which is what 'none' meant.
	 *
	 * @param array $map Guide ID map.
	 * @param bool  $dry Dry run.
	 */
	protected function migrate_products( $map, $dry ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value NOT IN ('', 'none')",
				PX_Size_Guide::LEGACY_PRODUCT
			)
		);

		$linked  = 0;
		$hidden  = 0;
		$skipped = 0;

		foreach ( $rows as $row ) {
			if ( '' !== get_post_meta( $row->post_id, PX_Size_Guide::META_LINK, true ) ) {
				continue;
			}

			if ( 'disable' === $row->meta_value ) {
				if ( ! $dry ) {
					update_post_meta( $row->post_id, PX_Size_Guide::META_LINK, PX_Size_Guide::OFF );
				}
				++$hidden;
				continue;
			}

			$legacy_id = (int) $row->meta_value;
			if ( empty( $map[ $legacy_id ] ) ) {
				++$skipped;
				continue;
			}

			if ( ! $dry ) {
				update_post_meta( $row->post_id, PX_Size_Guide::META_LINK, (string) $map[ $legacy_id ] );
			}
			++$linked;
		}

		WP_CLI::log( sprintf( $dry ? '  %d products would be linked, %d hidden' : '  %d products linked, %d hidden', $linked, $hidden ) );

		if ( $skipped ) {
			WP_CLI::warning( sprintf( '%d products point at a guide that was not converted (empty or missing).', $skipped ) );
		}
	}
}
