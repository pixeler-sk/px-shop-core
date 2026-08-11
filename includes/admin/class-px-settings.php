<?php
/**
 * Settings screen - WooCommerce -> Settings -> PX Shop.
 *
 * The default section lists every module with a checkbox; each module that
 * declares a 'settings' callback in the registry gets its own section next
 * to it. Everything is rendered and saved by the WooCommerce settings API,
 * so there is no custom markup, no custom save and no extra capability
 * check to get wrong (the page is behind manage_woocommerce like the rest
 * of the WooCommerce settings).
 *
 * Module switches are stored in one array option (px_shop_core_modules),
 * hence the field ids in px_shop_core_modules[<key>] form - the settings
 * API reads and writes array options natively.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Settings extends WC_Settings_Page {

	public function __construct() {
		$this->id    = 'px_shop';
		$this->label = __( 'PX Shop', 'px-shop-core' );

		parent::__construct();
	}

	/**
	 * Modules first, then a section per module that has settings.
	 *
	 * Sections of modules that are off are not offered - their class is not
	 * even loaded, so there would be nothing to render.
	 */
	protected function get_own_sections() {
		$sections = array( '' => __( 'Modules', 'px-shop-core' ) );

		foreach ( px_shop_core_modules() as $key => $module ) {
			if ( empty( $module['settings'] ) || ! px_module_on( $key ) ) {
				continue;
			}

			$sections[ $key ] = $module['title'];
		}

		return $sections;
	}

	protected function get_settings_for_section_core( $section_id ) {
		if ( '' === $section_id ) {
			return self::module_fields();
		}

		$modules = px_shop_core_modules();

		if ( empty( $modules[ $section_id ]['settings'] ) || ! px_module_on( $section_id ) ) {
			return array();
		}

		$callback = $modules[ $section_id ]['settings'];

		return is_callable( $callback ) ? (array) call_user_func( $callback ) : array();
	}

	/**
	 * One checkbox per module.
	 */
	private static function module_fields() {
		$fields = array(
			array(
				'title' => __( 'Modules', 'px-shop-core' ),
				'type'  => 'title',
				'desc'  => __( 'Only the modules switched on here are loaded. A module that is off registers no hooks, no REST routes and no admin screens; stored data (waitlist subscribers, wishlists, GPSR fields) stays untouched and comes back with it.', 'px-shop-core' ),
				'id'    => 'px_shop_core_modules_options',
			),
		);

		$states = px_shop_core_module_states();

		foreach ( px_shop_core_modules() as $key => $module ) {
			$default = isset( $module['default'] ) ? $module['default'] : 'yes';
			$stored  = isset( $states[ $key ] ) ? $states[ $key ] : $default;
			$desc    = $module['desc'];

			// A site can pin the state in code; say so, otherwise the
			// checkbox would claim something the site does not do.
			if ( ( 'yes' === $stored ) !== px_module_on( $key ) ) {
				$desc .= ' <strong>' . esc_html__( 'Overridden in code - the switch below has no effect.', 'px-shop-core' ) . '</strong>';
			}

			$fields[] = array(
				'title'   => $module['title'],
				'desc'    => $desc,
				'id'      => PX_SHOP_CORE_MODULES_OPTION . '[' . $key . ']',
				'type'    => 'checkbox',
				'default' => $default,
			);
		}

		$fields[] = array(
			'type' => 'sectionend',
			'id'   => 'px_shop_core_modules_options',
		);

		return $fields;
	}
}
