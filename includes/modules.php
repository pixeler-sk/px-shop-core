<?php
/**
 * Module registry.
 *
 * Every feature of this plugin is a module: one file, one class with an
 * init() method and an on/off state. px_shop_core_init() loads only the
 * modules that are on, so a module that is off registers nothing at all -
 * no hooks, no REST routes, no admin UI, no CLI commands. Themes keep
 * using class_exists( 'PX_Wishlist' ) as the "is this feature available?"
 * test and need no changes.
 *
 * State lives in a single option (px_shop_core_modules) as key => yes|no.
 * A missing key falls back to the default declared here, so updating the
 * plugin never changes the behaviour of a site whose owner has not opened
 * the settings screen. Sites managed in code can force a state with the
 * px_shop_core_module_on filter instead of touching the option.
 *
 * Definition keys:
 *   title    - name in the settings screen (required)
 *   desc     - one line under the checkbox (required)
 *   file     - path relative to the plugin root (required)
 *   class    - class with a static init() (required)
 *   default  - 'yes' | 'no'; defaults to 'yes'
 *   wc       - needs WooCommerce; defaults to true
 *   settings - callable returning WooCommerce settings fields for the
 *              module's own section, or absent when it has none
 *   cli      - array of array( file, command, class ) loaded under WP-CLI
 *   cron     - WP-Cron hook names the module schedules; the loader clears
 *              them when the module is off and the plugin clears them on
 *              deactivation, so no orphan event survives the switch
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PX_SHOP_CORE_MODULES_OPTION = 'px_shop_core_modules';

/**
 * All known modules, keyed by module id.
 *
 * @return array
 */
function px_shop_core_modules() {
	$modules = array(
		'content'         => array(
			'title' => __( 'Content blocks', 'px-shop-core' ),
			'desc'  => __( 'Non-public "Content" post type for reusable blocks (banners, USP strips) rendered by the theme.', 'px-shop-core' ),
			'file'  => 'includes/class-px-content.php',
			'class' => 'PX_Content',
			'wc'    => false,
		),
		'search'          => array(
			'title' => __( 'Live search', 'px-shop-core' ),
			'desc'  => __( 'REST endpoint behind the search field in the header.', 'px-shop-core' ),
			'file'  => 'includes/class-px-search.php',
			'class' => 'PX_Search',
		),
		'brand_tab'       => array(
			'title' => __( 'Brand tab', 'px-shop-core' ),
			'desc'  => __( 'Adds an "About the brand" product tab from the product_brand term description.', 'px-shop-core' ),
			'file'  => 'includes/class-px-brand-tab.php',
			'class' => 'PX_Brand_Tab',
		),
		'omnibus'         => array(
			'title' => __( 'EU Omnibus', 'px-shop-core' ),
			'desc'  => __( 'Records price history and shows the lowest price of the last 30 days on discounted products.', 'px-shop-core' ),
			'file'  => 'includes/class-px-omnibus.php',
			'class' => 'PX_Omnibus',
			// Literal, not PX_Omnibus::CRON_HOOK - the registry is read
			// exactly when the class is not loaded (module off, plugin
			// being deactivated).
			'cron'  => array( 'px_omnibus_scan' ),
		),
		'sale_dates'      => array(
			'title'    => __( 'Sale validity', 'px-shop-core' ),
			'desc'     => __( 'Shows until when a discounted price applies, as a date or a countdown near the end.', 'px-shop-core' ),
			'file'     => 'includes/class-px-sale-dates.php',
			'class'    => 'PX_Sale_Dates',
			'settings' => array( 'PX_Sale_Dates', 'settings_fields' ),
		),
		'gpsr'            => array(
			'title' => __( 'GPSR product safety', 'px-shop-core' ),
			'desc'  => __( 'Manufacturer, safety warnings and instructions on products, with the data tab in admin.', 'px-shop-core' ),
			'file'  => 'includes/class-px-gpsr.php',
			'class' => 'PX_GPSR',
		),
		'company_fields'  => array(
			'title'    => __( 'Company details', 'px-shop-core' ),
			'desc'     => __( 'IČO, DIČ and IČ DPH on the checkout, filled in from the RPO/ARES register, VAT id verified in VIES, EU reverse charge and export outside the EU. Off by default - switch it on only once the plugin that used to handle these fields is gone, or the checkout shows them twice.', 'px-shop-core' ),
			'file'     => 'includes/class-px-company-fields.php',
			'class'    => 'PX_Company_Fields',
			'default'  => 'no',
			'settings' => array( 'PX_Company_Fields', 'settings_fields' ),
		),
		'wishlist'        => array(
			'title'    => __( 'Wishlist', 'px-shop-core' ),
			'desc'     => __( 'Favourite products per customer (user meta, cookie for guests) with REST endpoints.', 'px-shop-core' ),
			'file'     => 'includes/class-px-wishlist.php',
			'class'    => 'PX_Wishlist',
			'settings' => array( 'PX_Wishlist', 'settings_fields' ),
			'cli'      => array(
				array( 'includes/cli/class-px-wishlist-cli.php', 'px wishlist', 'PX_Wishlist_CLI' ),
			),
		),
		'compare'         => array(
			'title'    => __( 'Compare', 'px-shop-core' ),
			'desc'     => __( 'Side-by-side comparison of up to four products (cookie based) with REST endpoints.', 'px-shop-core' ),
			'file'     => 'includes/class-px-compare.php',
			'class'    => 'PX_Compare',
			'settings' => array( 'PX_Compare', 'settings_fields' ),
		),
		'shipping_bar'    => array(
			'title' => __( 'Free shipping bar', 'px-shop-core' ),
			'desc'  => __( 'Data for the "how much is left to free shipping" progress bar drawn by the theme.', 'px-shop-core' ),
			'file'  => 'includes/class-px-shipping-bar.php',
			'class' => 'PX_Shipping_Bar',
		),
		'waitlist'        => array(
			'title' => __( 'Back-in-stock waitlist', 'px-shop-core' ),
			'desc'  => __( 'Visitors subscribe to an out-of-stock product (double opt-in) and are mailed once it is back.', 'px-shop-core' ),
			'file'  => 'includes/class-px-waitlist.php',
			'class' => 'PX_Waitlist',
			'cli'   => array(
				array( 'includes/cli/class-px-waitlist-cli.php', 'px waitlist', 'PX_Waitlist_CLI' ),
			),
		),
		'size_guide'      => array(
			'title' => __( 'Size guides', 'px-shop-core' ),
			'desc'  => __( 'Size tables assigned to a product or inherited from its category.', 'px-shop-core' ),
			'file'  => 'includes/class-px-size-guide.php',
			'class' => 'PX_Size_Guide',
			'cli'   => array(
				array( 'includes/cli/class-px-size-guide-cli.php', 'px size-guide', 'PX_Size_Guide_CLI' ),
			),
		),
		'related_cats'    => array(
			'title'    => __( 'Related categories', 'px-shop-core' ),
			'desc'     => __( 'Accessories set once per product category instead of product by product ("bicycles" go with "lights" and "bottle cages"). Subcategories inherit from their parent.', 'px-shop-core' ),
			'file'     => 'includes/class-px-related-cats.php',
			'class'    => 'PX_Related_Cats',
			'settings' => array( 'PX_Related_Cats', 'settings_fields' ),
		),
		'attribute_image' => array(
			'title' => __( 'Attribute images', 'px-shop-core' ),
			'desc'  => __( 'Image field on every product attribute term (pa_*), used by swatches and filters.', 'px-shop-core' ),
			'file'  => 'includes/class-px-attribute-image.php',
			'class' => 'PX_Attribute_Image',
		),
		'consent'         => array(
			'title'    => __( 'Cookie consent', 'px-shop-core' ),
			'desc'     => __( 'The shop\'s own consent banner: a service is asked about before it loads, the answer is kept in a first-party cookie and translated into Google Consent Mode v2 signals. Replaces Complianz and friends - it does not run next to them. Off by default; a site that keeps an external CMP leaves it off and nothing of this module is loaded.', 'px-shop-core' ),
			'file'     => 'includes/class-px-consent.php',
			'class'    => 'PX_Consent',
			'default'  => 'no',
			'settings' => array( 'PX_Consent', 'settings_fields' ),
		),
		'consent_mode'    => array(
			'title'   => __( 'Google Consent Mode v2', 'px-shop-core' ),
			'desc'    => __( 'Sends Google the consent signals the free Complianz build cannot. Complianz stays the consent banner, this only translates its decisions for gtag. Needs Complianz; off by default, so switch it on only once nothing else on the site emits gtag - otherwise it loads twice.', 'px-shop-core' ),
			'file'    => 'includes/class-px-consent-mode.php',
			'class'   => 'PX_Consent_Mode',
			'default' => 'no',
			'wc'      => false,
		),
		'catalog'         => array(
			'title'    => __( 'Catalog mode', 'px-shop-core' ),
			'desc'     => __( 'Makes a browse-only shop possible. The mode itself is switched on in this module\'s settings.', 'px-shop-core' ),
			'file'     => 'includes/class-px-catalog.php',
			'class'    => 'PX_Catalog',
			'settings' => array( 'PX_Catalog', 'settings_fields' ),
		),
	);

	/**
	 * Filters the module registry.
	 *
	 * A site plugin can add its own module here and get the on/off switch,
	 * the settings section and the loading for free.
	 *
	 * @param array $modules Module definitions keyed by module id.
	 */
	return apply_filters( 'px_shop_core_modules', $modules );
}

/**
 * Stored module states (key => yes|no). Keys never saved are absent.
 *
 * @return array
 */
function px_shop_core_module_states() {
	$states = get_option( PX_SHOP_CORE_MODULES_OPTION, array() );

	return is_array( $states ) ? $states : array();
}

/**
 * Is a module on?
 *
 * Unknown ids are off - a typo in a filter should not silently load
 * nothing while pretending everything is fine.
 *
 * @param string $key Module id.
 * @return bool
 */
function px_module_on( $key ) {
	$modules = px_shop_core_modules();

	if ( ! isset( $modules[ $key ] ) ) {
		return false;
	}

	$states  = px_shop_core_module_states();
	$default = isset( $modules[ $key ]['default'] ) ? $modules[ $key ]['default'] : 'yes';
	$state   = isset( $states[ $key ] ) ? $states[ $key ] : $default;

	/**
	 * Filters whether a module is on.
	 *
	 * Lets a site fix the state in code (git-managed installs); the
	 * settings screen then shows the switch as overridden.
	 *
	 * @param bool   $on  Whether the module is on.
	 * @param string $key Module id.
	 */
	return (bool) apply_filters( 'px_shop_core_module_on', 'yes' === $state, $key );
}
