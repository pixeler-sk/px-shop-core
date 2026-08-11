<?php
/**
 * Plugin Name: PX Shop Core
 * Description: WooCommerce extensions shared across Pixelers shop projects - live product search endpoint, brand product tab and future shop features. Presentation lives in the theme; this plugin only provides functionality.
 * Version: 1.2.0
 * Author: Pixelers
 * Author URI: https://pixeler.sk/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License: GPL v2 or later
 * Text Domain: px-shop-core
 * Domain Path: /languages
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PX_SHOP_CORE_VERSION', '1.2.0' );
define( 'PX_SHOP_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PX_SHOP_CORE_FILE', __FILE__ );

// Updates from GitHub. Deliberately outside px_shop_core_init(): the plugin
// does nothing without WooCommerce, but it must always be updatable.
if ( file_exists( PX_SHOP_CORE_DIR . 'includes/updater.php' ) ) {
	require_once PX_SHOP_CORE_DIR . 'includes/updater.php';
	add_action( 'init', 'px_shop_core_init_updater' );
}

add_action( 'plugins_loaded', 'px_shop_core_init' );
function px_shop_core_init() {

	load_plugin_textdomain( 'px-shop-core', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// WooCommerce-independent features.
	require PX_SHOP_CORE_DIR . 'includes/class-px-content.php';
	PX_Content::init();

	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require PX_SHOP_CORE_DIR . 'includes/class-px-search.php';
	require PX_SHOP_CORE_DIR . 'includes/class-px-brand-tab.php';
	require PX_SHOP_CORE_DIR . 'includes/class-px-omnibus.php';
	require PX_SHOP_CORE_DIR . 'includes/class-px-gpsr.php';
	require PX_SHOP_CORE_DIR . 'includes/class-px-wishlist.php';
	require PX_SHOP_CORE_DIR . 'includes/class-px-compare.php';
	require PX_SHOP_CORE_DIR . 'includes/class-px-shipping-bar.php';
	require PX_SHOP_CORE_DIR . 'includes/class-px-waitlist.php';
	require PX_SHOP_CORE_DIR . 'includes/class-px-catalog.php';
	require PX_SHOP_CORE_DIR . 'includes/class-px-attribute-image.php';
	require PX_SHOP_CORE_DIR . 'includes/class-px-size-guide.php';

	PX_Search::init();
	PX_Brand_Tab::init();
	PX_Omnibus::init();
	PX_GPSR::init();
	PX_Wishlist::init();
	PX_Compare::init();
	PX_Shipping_Bar::init();
	PX_Waitlist::init();
	PX_Catalog::init();
	PX_Attribute_Image::init();
	PX_Size_Guide::init();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require PX_SHOP_CORE_DIR . 'includes/cli/class-px-size-guide-cli.php';
		require PX_SHOP_CORE_DIR . 'includes/cli/class-px-waitlist-cli.php';

		WP_CLI::add_command( 'px size-guide', 'PX_Size_Guide_CLI' );
		WP_CLI::add_command( 'px waitlist', 'PX_Waitlist_CLI' );
	}
}
