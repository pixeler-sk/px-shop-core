<?php
/**
 * Automatic updates from GitHub.
 *
 * The plugin is not on wordpress.org — it is distributed from the public
 * repository https://github.com/pixeler-sk/px-shop-core. Plugin Update Checker
 * asks the GitHub API for the latest release, compares it with
 * PX_SHOP_CORE_VERSION and, when newer, the update shows up in
 * Dashboard → Updates like any other plugin.
 *
 * The update source is the **zip attached to the release** (asset), not the
 * auto-generated source archive — the asset is built by CI and contains no
 * development files. Hence REQUIRE_RELEASE_ASSETS: if a release has no asset,
 * no update is offered rather than shipping the raw repository.
 *
 * The changelog shown in "View details" is read from readme.txt.
 * The release process is documented in RELEASING.md.
 *
 * @package PxShopCore
 */

defined( 'ABSPATH' ) || exit;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Public repository updates are pulled from.
 */
const PX_SHOP_CORE_GITHUB_REPO = 'https://github.com/pixeler-sk/px-shop-core/';

/**
 * Wires up the update checker.
 *
 * Hooked outside px_shop_core_init() so the plugin stays updatable even
 * when WooCommerce is not active.
 */
function px_shop_core_init_updater() {
	// Updates are only checked in admin, cron and WP-CLI contexts — there is
	// no reason to load the library on the frontend at all.
	if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	require_once PX_SHOP_CORE_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

	$checker = PucFactory::buildUpdateChecker(
		PX_SHOP_CORE_GITHUB_REPO,
		PX_SHOP_CORE_FILE,
		'px-shop-core' // Must match the plugin directory name, or the update lands next to it.
	);

	// The constant is read from the instance on purpose. The Api class lives in
	// a directory named after the library version (e.g. v5p7\Vcs\Api), so a
	// `use` with the full name would fatal after a library upgrade.
	$api = $checker->getVcsApi();
	$api->enableReleaseAssets(
		'/^px-shop-core-\d+\.\d+\.\d+\.zip$/',
		$api::REQUIRE_RELEASE_ASSETS
	);
}
