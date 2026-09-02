<?php
/**
 * Unit price - the price per 1 kg / 1 l / 1 m / 1 piece next to the selling
 * price, as the price marking rules require (Directive 98/6/EC art. 2-3;
 * in Slovakia act 108/2024 § 2 h) and § 6).
 *
 * The law fixes the unit: kilogram, litre, metre, square or cubic metre, or
 * "another unit of quantity" (a piece for goods sold by count). This module
 * therefore has no reference-quantity setting - a shop that wants "per
 * 100 ml" marketing lines is asking for something else. What it does:
 *
 *  - the product carries the content of its package (quantity + unit) as two
 *    meta values, edited under the price in admin, importable by CSV
 *    ("Meta: _px_unit_qty" / "Meta: _px_unit") or set from WP-CLI;
 *  - the unit price is computed from the price the shop displays
 *    (wc_get_price_to_display: active price, tax display as configured), so
 *    a discount changes it and a shop showing net prices stays consistent;
 *  - it is left out where the law leaves it out: when it would equal the
 *    selling price (§ 6 (1) - one piece, exactly one litre) and for packs of
 *    at most 50 g / 50 ml (§ 6 (3) a)). Sets of different goods sold at one
 *    price (§ 6 (3) b)) simply get no data.
 *
 * Output is neutral markup (`.px-unit-price`); px-shop-theme places it in its
 * own slots and turns the default hooks off with the px_unit_price_display
 * filter, the same way it handles the Omnibus line.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Unit_Price {

	const META_QTY  = '_px_unit_qty';
	const META_UNIT = '_px_unit';

	/**
	 * Nominal content at or under which no unit price is required
	 * (108/2024 § 6 (3) a): "najviac 50 g alebo 50 ml").
	 */
	const SMALL_PACK_LIMIT = 50;

	public static function init() {
		// Admin: two fields in the General tab, after the price group. Not
		// inside woocommerce_product_options_pricing - WooCommerce wraps that
		// in `show_if_simple show_if_external`, which would hide the fields on
		// a variable parent, and the parent is where a content shared by all
		// variations is meant to be entered.
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'admin_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'admin_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save_variation' ), 10, 2 );

		// A variation carries its own line - the theme swaps it in on selection.
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'append_to_variation' ), 10, 3 );

		// The default output is decided on init, not here: init() runs on
		// plugins_loaded, before the theme's functions.php exists, so a filter
		// the theme adds would otherwise come too late to be heard.
		add_action( 'init', array( __CLASS__, 'hook_default_output' ), 5 );
	}

	/**
	 * Hook the fallback output unless the theme draws the line itself.
	 */
	public static function hook_default_output() {
		/**
		 * Filters whether the plugin prints the line itself.
		 *
		 * A theme that places the unit price in its own markup returns false
		 * and calls PX_Unit_Price::get_html() where it wants it; otherwise the
		 * line would be on the page twice.
		 *
		 * @param bool $display Whether to hook the default output.
		 */
		if ( ! apply_filters( 'px_unit_price_display', true ) ) {
			return;
		}

		// Right under the price in both places WooCommerce prints it (loop
		// price at 10, summary price at 10 - hooked later at the same priority
		// means right after).
		add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'render' ), 10 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render' ), 10 );
	}

	/* ------------------------------- Units ------------------------------- */

	/**
	 * Units a package content can be entered in.
	 *
	 * Each converts to one of the legal bases (kg, l, m, m2, m3, pc) with a
	 * factor; "1 kg" is then the label the unit price is quoted against.
	 *
	 * @return array code => array( label, base, factor )
	 */
	public static function units() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$units = array(
			'ml' => array( 'label' => 'ml', 'base' => 'l', 'factor' => 0.001 ),
			'l'  => array( 'label' => 'l', 'base' => 'l', 'factor' => 1 ),
			'g'  => array( 'label' => 'g', 'base' => 'kg', 'factor' => 0.001 ),
			'kg' => array( 'label' => 'kg', 'base' => 'kg', 'factor' => 1 ),
			'm'  => array( 'label' => 'm', 'base' => 'm', 'factor' => 1 ),
			'm2' => array( 'label' => 'm²', 'base' => 'm2', 'factor' => 1 ),
			'm3' => array( 'label' => 'm³', 'base' => 'm3', 'factor' => 1 ),
			'pc' => array( 'label' => _x( 'pc', 'unit: piece', 'px-shop-core' ), 'base' => 'pc', 'factor' => 1 ),
		);

		/**
		 * Filters the units a package content can be entered in.
		 *
		 * Adding a unit means naming its base too (see bases()).
		 *
		 * @param array $units Unit definitions keyed by code.
		 */
		$cache = apply_filters( 'px_unit_price_units', $units );

		return $cache;
	}

	/**
	 * Labels of the legal reference quantities.
	 *
	 * @return array base => label
	 */
	public static function bases() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$bases = array(
			'kg' => '1 kg',
			'l'  => '1 l',
			'm'  => '1 m',
			'm2' => '1 m²',
			'm3' => '1 m³',
			'pc' => _x( '1 pc', 'unit price reference: one piece', 'px-shop-core' ),
		);

		/**
		 * Filters the reference quantity labels.
		 *
		 * @param array $bases Labels keyed by base code.
		 */
		$cache = apply_filters( 'px_unit_price_bases', $bases );

		return $cache;
	}

	/* -------------------------------- Data ------------------------------- */

	/**
	 * Package content stored on the product.
	 *
	 * A variation without its own content inherits the parent's - variations
	 * that differ only in scent or colour are entered once.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return array|null array( 'qty' => float, 'unit' => string ) or null.
	 */
	public static function get_content( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$qty  = (string) $product->get_meta( self::META_QTY, true, 'edit' );
		$unit = (string) $product->get_meta( self::META_UNIT, true, 'edit' );

		if ( ( '' === $qty || '' === $unit ) && $product->get_parent_id() ) {
			// One parent load per request, not one per variation: a product
			// with fifty variations builds the same parent fifty times in
			// get_available_variations() otherwise.
			static $parents = array();

			$parent_id = $product->get_parent_id();

			if ( ! array_key_exists( $parent_id, $parents ) ) {
				$parents[ $parent_id ] = wc_get_product( $parent_id );
			}

			if ( $parents[ $parent_id ] ) {
				$qty  = (string) $parents[ $parent_id ]->get_meta( self::META_QTY, true, 'edit' );
				$unit = (string) $parents[ $parent_id ]->get_meta( self::META_UNIT, true, 'edit' );
			}
		}

		$qty  = (float) wc_format_decimal( $qty );
		$unit = self::normalize_unit( $unit );

		if ( $qty <= 0 || '' === $unit ) {
			return null;
		}

		return array(
			'qty'  => $qty,
			'unit' => $unit,
		);
	}

	/**
	 * Unit code as stored, tolerant to what a CSV import writes straight into
	 * postmeta ("L", "ks", " ml").
	 *
	 * @param string $unit Raw unit.
	 * @return string Known unit code or '' when unknown.
	 */
	public static function normalize_unit( $unit ) {
		$unit = strtolower( trim( (string) $unit ) );

		$aliases = array(
			'ks'  => 'pc',
			'pcs' => 'pc',
			'm²'  => 'm2',
			'm³'  => 'm3',
		);

		if ( isset( $aliases[ $unit ] ) ) {
			$unit = $aliases[ $unit ];
		}

		return isset( self::units()[ $unit ] ) ? $unit : '';
	}

	/**
	 * Unit price of a product.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return array|null array( 'price' => float, 'base' => string,
	 *                    'base_label' => string, 'qty' => float,
	 *                    'unit' => string ) or null when there is nothing to
	 *                    show - no data, a variable parent (each variation has
	 *                    its own), or one of the legal exemptions.
	 */
	public static function get( $product ) {
		if ( ! $product instanceof WC_Product || $product->is_type( 'variable' ) ) {
			return null;
		}

		/**
		 * Filters whether the unit price may be shown at all.
		 *
		 * The unit price reveals the selling price - a shop that hides prices
		 * from guests (PX_Guest_Prices and the like) must hide this too, in
		 * the loop, the variation payload and the public search endpoint.
		 *
		 * @param bool       $visible Whether to compute and show it.
		 * @param WC_Product $product Product or variation.
		 */
		if ( ! apply_filters( 'px_unit_price_visible', self::prices_visible(), $product ) ) {
			return null;
		}

		$content = self::get_content( $product );

		if ( ! $content ) {
			return null;
		}

		$units = self::units();
		$unit  = $units[ $content['unit'] ];
		$bases = self::bases();

		if ( ! isset( $bases[ $unit['base'] ] ) ) {
			return null;
		}

		$in_base = $content['qty'] * (float) $unit['factor'];

		if ( $in_base <= 0 ) {
			return null;
		}

		// § 6 (1): no unit price when it equals the selling price.
		if ( abs( $in_base - 1 ) < 0.000001 ) {
			return null;
		}

		// § 6 (3) a): packs of at most 50 g / 50 ml.
		if ( in_array( $unit['base'], array( 'kg', 'l' ), true ) && $in_base <= self::SMALL_PACK_LIMIT / 1000 ) {
			/**
			 * Filters whether small packs (at most 50 g / 50 ml) skip the unit
			 * price. The law does not require it there; a shop that wants it
			 * anyway returns false.
			 *
			 * @param bool       $exempt  Whether to skip.
			 * @param WC_Product $product Product or variation.
			 */
			if ( apply_filters( 'px_unit_price_small_pack_exempt', true, $product ) ) {
				return null;
			}
		}

		if ( '' === $product->get_price() ) {
			return null;
		}

		// The price the customer sees: active (sale) price, tax as the shop
		// displays it. Unit price has to match the selling price next to it.
		$display = (float) wc_get_price_to_display( $product );

		if ( $display <= 0 ) {
			return null;
		}

		$result = array(
			'price'      => $display / $in_base,
			'base'       => $unit['base'],
			'base_label' => $bases[ $unit['base'] ],
			'qty'        => $content['qty'],
			'unit'       => $content['unit'],
		);

		/**
		 * Filters the computed unit price.
		 *
		 * @param array|null $result  See get().
		 * @param WC_Product $product Product or variation.
		 */
		return apply_filters( 'px_unit_price', $result, $product );
	}

	/**
	 * Are selling prices shown to the current visitor?
	 *
	 * Knows the site plugins' guest-price module by name; anything else
	 * hooks px_unit_price_visible.
	 *
	 * @return bool
	 */
	protected static function prices_visible() {
		if ( class_exists( 'PX_Guest_Prices' ) && method_exists( 'PX_Guest_Prices', 'isHidden' ) ) {
			return ! PX_Guest_Prices::isHidden();
		}

		return true;
	}

	/* ------------------------------- Output ------------------------------ */

	/**
	 * Formatted amount, with more decimals when the shop's rounding would
	 * show zero (200 toothpicks for 0.80 € are 0.004 € apiece, not 0.00 €).
	 *
	 * @param float $price Unit price.
	 * @return string wc_price() HTML.
	 */
	public static function format_amount( $price ) {
		$decimals = wc_get_price_decimals();

		while ( $price > 0 && round( $price, $decimals ) <= 0 && $decimals < 4 ) {
			$decimals++;
		}

		return wc_price( $price, array( 'decimals' => $decimals ) );
	}

	/**
	 * Neutral markup of the unit price line.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string Empty when nothing is to be shown.
	 */
	public static function get_html( $product ) {
		$data = self::get( $product );

		if ( ! $data ) {
			return '';
		}

		return sprintf(
			'<span class="px-unit-price"><span class="screen-reader-text">%1$s </span>%2$s</span>',
			esc_html__( 'Unit price:', 'px-shop-core' ),
			sprintf(
				/* translators: 1: formatted price, 2: reference quantity ("1 l", "1 kg"). */
				_x( '%1$s / %2$s', 'unit price line', 'px-shop-core' ),
				self::format_amount( $data['price'] ),
				esc_html( $data['base_label'] )
			)
		);
	}

	/**
	 * Default output for themes that draw nothing of their own.
	 */
	public static function render() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		echo self::get_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Unit price line of the selected variation.
	 *
	 * @param array                $data      Variation payload.
	 * @param WC_Product_Variable  $variable  Parent product.
	 * @param WC_Product_Variation $variation Variation.
	 * @return array
	 */
	public static function append_to_variation( $data, $variable, $variation ) {
		$data['px_unit_price_html'] = self::get_html( $variation );

		return $data;
	}

	/* -------------------------------- Admin ------------------------------ */

	/**
	 * Options of the unit select, empty first.
	 *
	 * @return array
	 */
	protected static function unit_options() {
		$options = array( '' => '—' );

		foreach ( self::units() as $code => $unit ) {
			$options[ $code ] = $unit['label'];
		}

		return $options;
	}

	public static function admin_fields() {
		global $post;

		$product = $post ? wc_get_product( $post->ID ) : null;

		echo '<div class="options_group px-unit-price-fields">'; // Own group - the pricing group is closed by now.

		woocommerce_wp_text_input( array(
			'id'          => self::META_QTY,
			'label'       => __( 'Package content', 'px-shop-core' ),
			'description' => __( 'Quantity in the package, for the unit price the price marking rules require (per 1 kg, 1 l, 1 m or 1 piece). Leave empty for goods sold as one piece and for sets of different goods at one price; packs of at most 50 g / 50 ml show no unit price either way.', 'px-shop-core' ),
			'desc_tip'    => true,
			'data_type'   => 'decimal',
			'value'       => $product ? wc_format_localized_decimal( $product->get_meta( self::META_QTY, true, 'edit' ) ) : '',
		) );

		woocommerce_wp_select( array(
			'id'      => self::META_UNIT,
			'label'   => __( 'Unit', 'px-shop-core' ),
			'options' => self::unit_options(),
			'value'   => $product ? $product->get_meta( self::META_UNIT, true, 'edit' ) : '',
		) );

		echo '</div>';
	}

	/**
	 * @param int     $loop           Variation index in the form.
	 * @param array   $variation_data Legacy variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function admin_variation_fields( $loop, $variation_data, $variation ) {
		$product = wc_get_product( $variation->ID );

		if ( ! $product ) {
			return;
		}

		woocommerce_wp_text_input( array(
			'id'            => self::META_QTY . '_' . $loop,
			'name'          => self::META_QTY . '[' . $loop . ']',
			'label'         => __( 'Package content', 'px-shop-core' ),
			'description'   => __( 'Empty inherits the parent product.', 'px-shop-core' ),
			'desc_tip'      => true,
			'data_type'     => 'decimal',
			'wrapper_class' => 'form-row form-row-first',
			'value'         => wc_format_localized_decimal( $product->get_meta( self::META_QTY, true, 'edit' ) ),
		) );

		woocommerce_wp_select( array(
			'id'            => self::META_UNIT . '_' . $loop,
			'name'          => self::META_UNIT . '[' . $loop . ']',
			'label'         => __( 'Unit', 'px-shop-core' ),
			'options'       => self::unit_options(),
			'wrapper_class' => 'form-row form-row-last',
			'value'         => $product->get_meta( self::META_UNIT, true, 'edit' ),
		) );
	}

	/**
	 * Put a pair on a product object without saving; an empty or invalid pair
	 * clears it. For code that saves the product itself afterwards (importers
	 * building a product before the first save()).
	 *
	 * @param WC_Product  $product Product or variation.
	 * @param string|null $qty     Raw quantity.
	 * @param string|null $unit    Raw unit code.
	 */
	public static function assign( $product, $qty, $unit ) {
		$qty  = wc_format_decimal( wp_unslash( (string) $qty ) );
		$unit = self::normalize_unit( sanitize_text_field( wp_unslash( (string) $unit ) ) );

		if ( '' === $qty || (float) $qty <= 0 || '' === $unit ) {
			$product->delete_meta_data( self::META_QTY );
			$product->delete_meta_data( self::META_UNIT );

			return;
		}

		$product->update_meta_data( self::META_QTY, $qty );
		$product->update_meta_data( self::META_UNIT, $unit );
	}

	/**
	 * Nonce and capability are verified by WooCommerce before this hook fires.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public static function save( $product ) {
		if ( ! isset( $_POST[ self::META_QTY ] ) || is_array( $_POST[ self::META_QTY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in assign().
		self::assign(
			$product,
			$_POST[ self::META_QTY ],
			isset( $_POST[ self::META_UNIT ] ) && ! is_array( $_POST[ self::META_UNIT ] ) ? $_POST[ self::META_UNIT ] : ''
		);
		// phpcs:enable
	}

	/**
	 * @param WC_Product_Variation $variation Variation being saved.
	 * @param int                  $i         Its index in the form.
	 */
	public static function save_variation( $variation, $i ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in assign().
		// The array check matters: a scalar _px_unit_qty in the same request
		// would make isset( '750'[0] ) true and store the first character.
		if ( ! isset( $_POST[ self::META_QTY ] ) || ! is_array( $_POST[ self::META_QTY ] ) || ! isset( $_POST[ self::META_QTY ][ $i ] ) ) {
			return;
		}

		self::assign(
			$variation,
			$_POST[ self::META_QTY ][ $i ],
			isset( $_POST[ self::META_UNIT ] ) && is_array( $_POST[ self::META_UNIT ] ) && isset( $_POST[ self::META_UNIT ][ $i ] ) ? $_POST[ self::META_UNIT ][ $i ] : ''
		);
		// phpcs:enable
	}

	/**
	 * Set the package content from code (imports, CLI). Saves the product.
	 *
	 * @param int|WC_Product $product Product or ID.
	 * @param float|string   $qty     Quantity; 0 or '' clears.
	 * @param string         $unit    Unit code.
	 * @return bool False when the product does not exist.
	 */
	public static function set( $product, $qty, $unit ) {
		$product = $product instanceof WC_Product ? $product : wc_get_product( $product );

		if ( ! $product ) {
			return false;
		}

		self::assign( $product, (string) $qty, (string) $unit );
		$product->save();

		return true;
	}
}

if ( ! function_exists( 'px_unit_price' ) ) {
	/**
	 * Unit price data for themes that would rather not name the class.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return array|null See PX_Unit_Price::get().
	 */
	function px_unit_price( $product ) {
		return PX_Unit_Price::get( $product );
	}
}
