<?php
/**
 * WP-CLI: package content behind the unit price.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Unit_Price_CLI {

	/**
	 * Set the package content of a product or variation.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Product or variation ID.
	 *
	 * <qty>
	 * : Quantity in the package (decimal point or comma).
	 *
	 * <unit>
	 * : Unit code: ml, l, g, kg, m, m2, m3, pc.
	 *
	 * ## EXAMPLES
	 *
	 *     wp px unit-price set 2391 750 ml
	 *     wp px unit-price set 2405 100 pc
	 *
	 * @param array $args Positional arguments.
	 */
	public function set( $args ) {
		list( $id, $qty, $unit ) = $args;

		if ( ! isset( PX_Unit_Price::units()[ $unit ] ) ) {
			WP_CLI::error( sprintf( 'Unknown unit "%s". Known: %s.', $unit, implode( ', ', array_keys( PX_Unit_Price::units() ) ) ) );
		}

		if ( ! PX_Unit_Price::set( (int) $id, str_replace( ',', '.', $qty ), $unit ) ) {
			WP_CLI::error( sprintf( 'Product %d does not exist.', $id ) );
		}

		$product = wc_get_product( (int) $id );
		$data    = PX_Unit_Price::get( $product );

		WP_CLI::success( sprintf(
			'#%d %s: %s %s → %s',
			$id,
			$product->get_name(),
			$qty,
			$unit,
			$data ? sprintf( '%s / %s', wp_strip_all_tags( PX_Unit_Price::format_amount( $data['price'] ) ), $data['base_label'] ) : 'no unit price shown (exempt or equal to the selling price)'
		) );
	}

	/**
	 * Remove the package content from a product or variation.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Product or variation ID.
	 *
	 * @param array $args Positional arguments.
	 */
	public function clear( $args ) {
		if ( ! PX_Unit_Price::set( (int) $args[0], '', '' ) ) {
			WP_CLI::error( sprintf( 'Product %d does not exist.', $args[0] ) );
		}

		WP_CLI::success( sprintf( 'Cleared #%d.', $args[0] ) );
	}

	/**
	 * List published products with their package content and unit price.
	 *
	 * ## OPTIONS
	 *
	 * [--missing]
	 * : Only products without package content.
	 *
	 * [--format=<format>]
	 * : table, csv, json, count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp px unit-price list --missing
	 *     wp px unit-price list --format=csv > unit-prices.csv
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function list( $args, $assoc_args ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames
		$missing = isset( $assoc_args['missing'] );
		$rows    = array();

		$ids = wc_get_products( array(
			'status'  => 'publish',
			'type'    => array( 'simple', 'variation', 'external' ),
			'limit'   => -1,
			'return'  => 'ids',
			'orderby' => 'ID',
			'order'   => 'ASC',
		) );

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );

			if ( ! $product ) {
				continue;
			}

			$content = PX_Unit_Price::get_content( $product );

			if ( $missing && $content ) {
				continue;
			}

			$data = PX_Unit_Price::get( $product );

			$rows[] = array(
				'id'         => $id,
				'sku'        => $product->get_sku(),
				'name'       => $product->get_name(),
				'content'    => $content ? $content['qty'] . ' ' . $content['unit'] : '',
				'unit_price' => $data ? wp_strip_all_tags( PX_Unit_Price::format_amount( $data['price'] ) ) . ' / ' . $data['base_label'] : '',
			);
		}

		WP_CLI\Utils\format_items( $assoc_args['format'], $rows, array( 'id', 'sku', 'name', 'content', 'unit_price' ) );
	}
}
