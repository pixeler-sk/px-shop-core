<?php
/**
 * Product media abilities for MCP clients.
 *
 * Registers the product image abilities (read the featured image and
 * gallery, replace or extend them, fix an attachment's alt text) with the
 * WordPress Abilities API, so an MCP connector (MCP Adapter) can manage
 * product photos. The abilities themselves live in abilities-media.php.
 *
 * Off by default: it only makes sense on a site that runs an MCP connector,
 * and it lets an authorised client sideload images from a URL.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Meta key recording the URL a sideloaded attachment was downloaded from. */
if ( ! defined( 'PX_SHOP_CORE_SOURCE_URL_META' ) ) {
	define( 'PX_SHOP_CORE_SOURCE_URL_META', '_px_source_url' );
}

class PX_Media_Abilities {

	/**
	 * Loads the abilities when the Abilities API exists (WordPress 6.9+).
	 */
	public static function init() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		require_once PX_SHOP_CORE_DIR . 'includes/abilities-media.php';
	}
}
