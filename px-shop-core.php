<?php
/**
 * Plugin Name: PX Shop Core
 * Description: WooCommerce extensions shared across Pixelers shop projects - live product search endpoint, brand product tab and future shop features. Presentation lives in the theme; this plugin only provides functionality.
 * Version: 1.8.0
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

define( 'PX_SHOP_CORE_VERSION', '1.8.0' );
define( 'PX_SHOP_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PX_SHOP_CORE_FILE', __FILE__ );

// Page cache helpers (DONOTCACHEPAGE, purge). Loaded unconditionally: they
// are used by modules and site plugins alike, and they cost nothing when no
// cache plugin is installed.
require_once PX_SHOP_CORE_DIR . 'includes/cache.php';

// Updates from GitHub. Deliberately outside px_shop_core_init(): the plugin
// does nothing without WooCommerce, but it must always be updatable.
if ( file_exists( PX_SHOP_CORE_DIR . 'includes/updater.php' ) ) {
	require_once PX_SHOP_CORE_DIR . 'includes/updater.php';
	add_action( 'init', 'px_shop_core_init_updater' );
}

add_action( 'plugins_loaded', 'px_shop_core_init' );
register_deactivation_hook( __FILE__, 'px_shop_core_deactivate' );

/**
 * Nothing of this plugin may keep running once it is switched off - a
 * scheduled event whose callback no longer exists would otherwise be
 * re-scheduled by WP-Cron forever.
 */
function px_shop_core_deactivate() {
	require_once PX_SHOP_CORE_DIR . 'includes/modules.php';

	foreach ( px_shop_core_modules() as $module ) {
		if ( empty( $module['cron'] ) ) {
			continue;
		}

		foreach ( (array) $module['cron'] as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}

/**
 * Loads the modules that are switched on (see includes/modules.php).
 *
 * A module that is off is never required, so its class does not exist -
 * which is exactly the test themes already use before drawing its UI.
 */
function px_shop_core_init() {

	load_plugin_textdomain( 'px-shop-core', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	require_once PX_SHOP_CORE_DIR . 'includes/modules.php';

	$has_wc = class_exists( 'WooCommerce' );
	$is_cli = defined( 'WP_CLI' ) && WP_CLI;

	foreach ( px_shop_core_modules() as $key => $module ) {
		$needs_wc = ! isset( $module['wc'] ) || $module['wc'];

		$switched_off = ! px_module_on( $key );

		// A module someone switched off must not leave a cron event behind.
		// Missing WooCommerce is deliberately NOT a reason to unschedule:
		// one wp-admin load during a WooCommerce update would drop
		// px_omnibus_scan, and the module would only book it again for
		// tomorrow - a silently lost day of compliance data.
		//
		// Admin-only on purpose: this is tidying up after a switch someone
		// flipped in admin, not work every visitor pays for.
		if ( $switched_off && is_admin() && ! empty( $module['cron'] ) ) {
			foreach ( (array) $module['cron'] as $hook ) {
				if ( wp_next_scheduled( $hook ) ) {
					wp_clear_scheduled_hook( $hook );
				}
			}
		}

		if ( ( $needs_wc && ! $has_wc ) || $switched_off ) {
			continue;
		}

		require_once PX_SHOP_CORE_DIR . $module['file'];
		call_user_func( array( $module['class'], 'init' ) );

		if ( ! $is_cli || empty( $module['cli'] ) ) {
			continue;
		}

		// CLI commands belong to their module - a switched-off feature
		// should not offer a command that migrates data into it.
		foreach ( $module['cli'] as $command ) {
			list( $file, $name, $class ) = $command;

			require_once PX_SHOP_CORE_DIR . $file;
			WP_CLI::add_command( $name, $class );
		}
	}

	// Settings screen (WooCommerce -> Settings -> PX Shop).
	if ( $has_wc && is_admin() ) {
		add_filter( 'woocommerce_get_settings_pages', 'px_shop_core_settings_page' );
	}
}

/**
 * Registers the settings page with WooCommerce.
 *
 * @param array $pages Settings pages.
 * @return array
 */
function px_shop_core_settings_page( $pages ) {
	require_once PX_SHOP_CORE_DIR . 'includes/admin/class-px-settings.php';

	$pages[] = new PX_Settings();

	return $pages;
}
