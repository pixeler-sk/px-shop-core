<?php
/**
 * Catalog mode.
 *
 * Turns the shop into a browse-only catalog: products become
 * non-purchasable, add-to-cart controls are removed and replaced by a
 * link (to the product or a custom URL), and prices can optionally be
 * hidden. Settings live in WooCommerce -> Settings -> PX Shop -> Catalog
 * mode (the module registry points the section at settings_fields()), so
 * the behaviour survives a theme switch. Presentation (button classes,
 * hiding the cart UI) is left to the active theme, which reads
 * px_catalog_mode() and styles the neutral markup emitted here.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Catalog {

	public static function init() {
		// Behaviour is wired only while the mode is on. The settings UI is
		// rendered by PX_Settings from settings_fields() below.
		add_action( 'init', array( __CLASS__, 'apply' ) );
	}

	/* ------------------------------ Settings ----------------------------- */

	/**
	 * Fields for the module's section (option ids unchanged since 1.0).
	 *
	 * @return array
	 */
	public static function settings_fields() {
		return array(
			array(
				'title' => __( 'Catalog mode', 'px-shop-core' ),
				'type'  => 'title',
				'desc'  => __( 'Turn the shop into a browse-only catalog: add-to-cart buttons and the cart are hidden. Useful for showrooms or price-on-request shops.', 'px-shop-core' ),
				'id'    => 'px_catalog_options',
			),
			array(
				'title'   => __( 'Catalog mode', 'px-shop-core' ),
				'desc'    => __( 'Enable catalog mode', 'px-shop-core' ),
				'id'      => 'px_catalog_mode',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'   => __( 'Prices', 'px-shop-core' ),
				'desc'    => __( 'Also hide prices', 'px-shop-core' ),
				'id'      => 'px_catalog_hide_price',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'    => __( 'Replacement button text', 'px-shop-core' ),
				'desc'     => __( 'Shown instead of "Add to cart". Empty falls back to "View product".', 'px-shop-core' ),
				'id'       => 'px_catalog_button_text',
				'type'     => 'text',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Replacement button link', 'px-shop-core' ),
				'desc'     => __( 'Where the button points. Empty links to the product page.', 'px-shop-core' ),
				'id'       => 'px_catalog_button_url',
				'type'     => 'url',
				'desc_tip' => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'px_catalog_options',
			),
		);
	}

	/* ----------------------------- Behaviour ----------------------------- */

	public static function apply() {
		if ( ! px_catalog_mode() ) {
			return;
		}

		// Nothing is purchasable in catalog mode.
		add_filter( 'woocommerce_is_purchasable', '__return_false' );

		// Replace the single-product add-to-cart form with our button.
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'single_button' ), 30 );

		// Remove the default loop add-to-cart (custom loops still render fine;
		// the theme's own cards call px_catalog_button() directly).
		remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );

		if ( px_catalog_hide_price() ) {
			add_filter( 'woocommerce_get_price_html', '__return_empty_string' );
			add_filter( 'woocommerce_grouped_price_html', '__return_empty_string' );
		}
	}

	/**
	 * Replacement button on the single product summary.
	 */
	public static function single_button() {
		global $product;
		if ( ! $product ) {
			return;
		}
		echo '<div class="px-catalog-cta">';
		px_catalog_button( $product );
		echo '</div>';
	}
}

/* ------------------------------- Helpers ------------------------------- */

/**
 * Is catalog mode enabled?
 */
function px_catalog_mode() {
	return 'yes' === get_option( 'px_catalog_mode', 'no' );
}

/**
 * Should prices be hidden (only while catalog mode is on)?
 */
function px_catalog_hide_price() {
	return px_catalog_mode() && 'yes' === get_option( 'px_catalog_hide_price', 'no' );
}

/**
 * Render the catalog replacement button for a product.
 *
 * @param WC_Product $product Product.
 * @param string     $classes Anchor classes. Empty uses the filtered default
 *                            (theme can override via 'px_catalog_button_class').
 */
function px_catalog_button( $product, $classes = '' ) {
	if ( ! $product instanceof WC_Product ) {
		return;
	}

	if ( '' === $classes ) {
		$classes = apply_filters( 'px_catalog_button_class', 'button' );
	}

	$text = get_option( 'px_catalog_button_text', '' );
	$url  = get_option( 'px_catalog_button_url', '' );

	$text = $text ? $text : __( 'View product', 'px-shop-core' );
	$url  = $url ? $url : $product->get_permalink();

	printf(
		'<a href="%1$s" class="%2$s">%3$s</a>',
		esc_url( $url ),
		esc_attr( $classes ),
		esc_html( $text )
	);
}
