<?php
/**
 * Company details on the checkout: IČO, DIČ and IČ DPH.
 *
 * What a Slovak or Czech shop needs from a business customer, plus the two
 * things that hang off it: filling the details from the public register, and
 * deciding whether the order carries VAT at all (EU reverse charge, export
 * outside the EU).
 *
 * Storage is deliberately the same as the plugin most of our shops used
 * before this module (WPify Woo): the checkout posts billing_ic, billing_dic
 * and billing_dic_dph, WooCommerce stores them as _billing_ic, _billing_dic
 * and _billing_dic_dph on the order and without the underscore on the user.
 * Invoicing plugins, exports and site plugins already read those keys, so
 * switching a shop over is a plugin swap with no data migration - and orders
 * placed before the swap keep rendering.
 *
 * Field markup is WooCommerce's own (woocommerce_form_field), the module adds
 * only px-* classes; how any of it looks belongs to the theme.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once PX_SHOP_CORE_DIR . 'includes/class-px-company-lookup.php';

class PX_Company_Fields {

	/** Name of the "I'm buying for a company" checkbox, kept from WPify Woo. */
	const TOGGLE = 'company_details';

	/** Reason for the VAT decision, stored on the order for the accountant. */
	const REASON_META = '_px_vat_exempt_reason';

	/** EU member states as of 2026 - the reverse charge only applies inside. */
	const EU = array(
		'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR',
		'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO',
		'SE', 'SI', 'SK',
	);

	/**
	 * VIES answer for the number being checked, remembered for the length of
	 * the request: validation, the VAT decision and the log entry all need it
	 * and each one would otherwise ask the Commission again.
	 *
	 * @var array|null
	 */
	private static $vies = null;

	public static function init() {
		// Both plugins write the same three fields into the same three meta
		// keys, which is what makes the switch painless - and what would put
		// every field on the checkout twice if they ran together.
		if ( self::wpify_owns_the_fields() ) {
			add_action( 'admin_notices', array( __CLASS__, 'conflict_notice' ) );

			return;
		}

		// One definition feeds the checkout and the My Account address form,
		// because WooCommerce builds the checkout from the billing fields.
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'billing_fields' ), 10, 2 );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'checkout_fields' ) );
		add_filter( 'woocommerce_form_field', array( __CLASS__, 'clean_toggle_label' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );

		// Admin, e-mails and the formatted address.
		add_filter( 'woocommerce_admin_billing_fields', array( __CLASS__, 'admin_fields' ) );
		add_filter( 'woocommerce_customer_meta_fields', array( __CLASS__, 'profile_fields' ) );
		add_filter( 'woocommerce_localisation_address_formats', array( __CLASS__, 'address_formats' ) );
		add_filter( 'woocommerce_formatted_address_replacements', array( __CLASS__, 'address_replacements' ), 10, 2 );
		add_filter( 'woocommerce_order_formatted_billing_address', array( __CLASS__, 'order_address' ), 10, 2 );

		// Invoicing: SuperFaktúra only reads these fields when WPify Woo is
		// the active plugin, so it has to be told about ours.
		add_filter( 'sf_client_data', array( __CLASS__, 'superfaktura_client_data' ), 10, 2 );

		// It also ships the same block of its own (checkbox, company, ID, TAX
		// ID, VAT ID) and has it on by default - dormant only while no company
		// field exists, which is precisely what this module puts back. Its
		// block writes different meta and runs its own reverse charge, so two
		// plugins would be deciding one tax. Stand it down; SuperFaktúra
		// already does the same for WC Nastavenia SK/CZ.
		add_filter( 'pre_option_woocommerce_sf_add_company_billing_fields', array( __CLASS__, 'silence_superfaktura_fields' ) );

		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );

		if ( px_company_vat_rules_on() ) {
			add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'exempt_on_order_review' ) );
			add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'exempt_on_validation' ), 20, 2 );
			add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'save_reason' ), 10, 3 );
			add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_vat_notice' ) );
		}
	}

	/**
	 * Is WPify Woo still the one doing this?
	 *
	 * @return bool
	 */
	private static function wpify_owns_the_fields() {
		if ( ! class_exists( 'WpifyWoo\Plugin' ) ) {
			return false;
		}

		$settings = get_option( 'wpify-woo-settings-general', array() );
		$modules  = ( is_array( $settings ) && isset( $settings['enabled_modules'] ) ) ? (array) $settings['enabled_modules'] : array();

		return in_array( 'ic_dic', $modules, true );
	}

	/**
	 * Says why the module looks switched on but does nothing.
	 */
	public static function conflict_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'PX Shop Core: the "Company details" module is switched on, but WPify Woo still handles IČO and DIČ. Turn off its "IČ DIČ" module (or deactivate the plugin) and the fields will be served by PX Shop Core - the stored data is the same and stays where it is.', 'px-shop-core' );
		echo '</p></div>';
	}

	/* ------------------------------ Settings ----------------------------- */

	/**
	 * Fields for the module's own section in WooCommerce → Settings → PX Shop.
	 *
	 * @return array
	 */
	public static function settings_fields() {
		return array(
			array(
				'title' => __( 'Checkout fields', 'px-shop-core' ),
				'type'  => 'title',
				'desc'  => __( 'IČO, DIČ and IČ DPH are stored as _billing_ic, _billing_dic and _billing_dic_dph, the keys invoicing plugins and exports already expect. IČ DPH is only shown for Slovak billing addresses; elsewhere the VAT id belongs in DIČ. The company name field is part of the block even when WooCommerce is set to hide it - an invoice needs a name, not just a number.', 'px-shop-core' ),
				'id'    => 'px_company_options',
			),
			array(
				'title'   => __( 'Company checkbox', 'px-shop-core' ),
				'desc'    => __( 'Show the "I\'m buying for a company" checkbox and keep the fields hidden until it is ticked', 'px-shop-core' ),
				'id'      => 'px_company_checkbox',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'    => __( 'Field position', 'px-shop-core' ),
				'desc'     => __( 'Keep the company block together right after the name', 'px-shop-core' ),
				'id'       => 'px_company_move_fields',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'desc_tip' => __( 'Name, then the checkbox, then the company name and the numbers - all above the address, so the register lookup has somewhere to fill in. With this off the block follows wherever the company field already sits.', 'px-shop-core' ),
			),
			array(
				'title'   => __( 'Company name', 'px-shop-core' ),
				'desc'    => __( 'Required when buying for a company', 'px-shop-core' ),
				'id'      => 'px_company_required_company',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Identification number', 'px-shop-core' ),
				'desc'    => __( 'When is IČO required?', 'px-shop-core' ),
				'id'      => 'px_company_required_ic',
				'type'    => 'select',
				'default' => 'if_checkbox',
				'options' => array(
					'if_checkbox' => __( 'When the company checkbox is ticked', 'px-shop-core' ),
					'if_company'  => __( 'When the company name is filled in', 'px-shop-core' ),
					'never'       => __( 'Never', 'px-shop-core' ),
				),
			),
			array(
				'title'    => __( 'Format check', 'px-shop-core' ),
				'desc'     => __( 'Reject numbers that cannot be right before the order is placed', 'px-shop-core' ),
				'id'       => 'px_company_validate_format',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'desc_tip' => __( 'IČO 8 digits, SK DIČ 10 digits, SK IČ DPH SK + 10 digits, other EU VAT ids country prefix + number.', 'px-shop-core' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'px_company_options',
			),

			array(
				'title' => __( 'Public registers', 'px-shop-core' ),
				'type'  => 'title',
				'desc'  => __( 'RPO (Statistical Office) for Slovak numbers, ARES (Ministry of Finance) for Czech ones. Both are free, need no key and are queried straight from the shop; answers are cached for a week.', 'px-shop-core' ),
				'id'    => 'px_company_register_options',
			),
			array(
				'title'    => __( 'Fill in from the register', 'px-shop-core' ),
				'desc'     => __( 'Offer a "Load from register" button next to IČO', 'px-shop-core' ),
				'id'       => 'px_company_autofill',
				'type'     => 'checkbox',
				'default'  => 'no',
				'desc_tip' => __( 'Fills the company name and registered address. The customer can still edit everything afterwards.', 'px-shop-core' ),
			),
			array(
				'title'    => __( 'Verify the number', 'px-shop-core' ),
				'desc'     => __( 'Refuse an IČO the register does not know', 'px-shop-core' ),
				'id'       => 'px_company_verify_ic',
				'type'     => 'checkbox',
				'default'  => 'no',
				'desc_tip' => __( 'Checked when the order is placed. If the register is down the order goes through - the outage is ours to log, not the customer\'s to solve.', 'px-shop-core' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'px_company_register_options',
			),

			array(
				'title' => __( 'VAT id verification (VIES)', 'px-shop-core' ),
				'type'  => 'title',
				'desc'  => __( 'VIES is the European Commission register of VAT identification numbers. It is the only evidence that a reverse-charge invoice was issued to a real VAT payer, so keep it on wherever the reverse charge is on.', 'px-shop-core' ),
				'id'    => 'px_company_vies_options',
			),
			array(
				'title'   => __( 'VIES check', 'px-shop-core' ),
				'id'      => 'px_company_vies',
				'type'    => 'select',
				'default' => 'off',
				'options' => array(
					'off'  => __( 'Off', 'px-shop-core' ),
					'soft' => __( 'Check and warn, let the order through', 'px-shop-core' ),
					'hard' => __( 'Check and refuse an unverified VAT id', 'px-shop-core' ),
				),
				'desc'    => __( 'A VIES outage never blocks an order, not even in the strict setting - only an answer saying the number is unknown does.', 'px-shop-core' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'px_company_vies_options',
			),

			array(
				'title' => __( 'VAT exemption', 'px-shop-core' ),
				'type'  => 'title',
				/* translators: %s: link to the WooCommerce tax settings */
				'desc'  => sprintf(
					__( 'Which country decides follows the WooCommerce setting <em>Calculate tax based on</em> (%s): the shipping country for goods, the billing country for services. Leave both switches off in a shop that sells at home only.', 'px-shop-core' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=tax' ) ) . '">' . esc_html__( 'WooCommerce → Settings → Tax', 'px-shop-core' ) . '</a>'
				),
				'id'    => 'px_company_vat_options',
			),
			array(
				'title'    => __( 'EU reverse charge', 'px-shop-core' ),
				'desc'     => __( 'No VAT for a business customer in another EU country', 'px-shop-core' ),
				'id'       => 'px_company_reverse_charge',
				'type'     => 'checkbox',
				'default'  => 'no',
				'desc_tip' => __( 'The invoice has to carry the "reverse charge" notice and the order belongs in the EC Sales List.', 'px-shop-core' ),
			),
			array(
				'title'    => __( 'Export outside the EU', 'px-shop-core' ),
				'desc'     => __( 'No VAT when the goods leave the EU', 'px-shop-core' ),
				'id'       => 'px_company_export_exempt',
				'type'     => 'checkbox',
				'default'  => 'no',
				'desc_tip' => __( 'Applies to consumers as well as businesses. You need the customs proof of export to defend it.', 'px-shop-core' ),
			),
			array(
				'title'    => __( 'Proof required', 'px-shop-core' ),
				'desc'     => __( 'Only drop the VAT when VIES confirms the VAT id', 'px-shop-core' ),
				'id'       => 'px_company_exempt_requires_vies',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'desc_tip' => __( 'With this off, any correctly formatted VAT id is enough and the tax risk is the shop\'s. Turning it on with the VIES check off leaves the reverse charge inactive.', 'px-shop-core' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'px_company_vat_options',
			),
		);
	}

	/* ------------------------------- Fields ------------------------------ */

	/**
	 * The three fields, added to the billing address group.
	 *
	 * @param array  $fields  Billing fields.
	 * @param string $country Country the form is rendered for.
	 * @return array
	 */
	public static function billing_fields( $fields, $country = '' ) {
		// A shop that hid the company field in WooCommerce still needs it the
		// moment somebody buys as a company - an invoice with an IČO and no
		// name on it is no use to anyone. It folds away with the rest of the
		// block, so nothing changes for private customers.
		if ( ! isset( $fields['billing_company'] ) && self::forces_company_field() ) {
			$fields['billing_company'] = array(
				'label'        => __( 'Company name', 'px-shop-core' ),
				'required'     => false,
				'class'        => array( 'form-row-wide' ),
				'priority'     => 30,
				'autocomplete' => 'organization',
			);
		}

		$company_priority = isset( $fields['billing_company']['priority'] ) ? (int) $fields['billing_company']['priority'] : 30;

		// The block sits after the name, never before it: the customer first
		// says who they are, then that they are buying for a company. Asking
		// for an IČO above the name puts the exception before the rule.
		if ( px_company_move_fields() ) {
			$company_priority = 22;
		}

		if ( isset( $fields['billing_company'] ) ) {
			$fields['billing_company']['priority'] = $company_priority;
			$fields['billing_company']['class']    = array_values(
				array_unique(
					array_merge(
						isset( $fields['billing_company']['class'] ) ? (array) $fields['billing_company']['class'] : array( 'form-row-wide' ),
						array( 'px-company-field', 'px-company-field--company' )
					)
				)
			);
		}

		$fields['billing_ic'] = array(
			'label'        => __( 'Company ID', 'px-shop-core' ),
			'placeholder'  => __( '12345678', 'px-shop-core' ),
			'required'     => false,
			'class'        => array( 'form-row-wide', 'px-company-field', 'px-company-field--ic' ),
			'priority'     => $company_priority + 1,
			'autocomplete' => 'off',
		);

		$fields['billing_dic'] = array(
			'label'        => __( 'Tax ID', 'px-shop-core' ),
			'required'     => false,
			'class'        => array( 'form-row-wide', 'px-company-field', 'px-company-field--dic' ),
			'priority'     => $company_priority + 2,
			'autocomplete' => 'off',
		);

		$fields['billing_dic_dph'] = array(
			'label'        => __( 'VAT ID', 'px-shop-core' ),
			'placeholder'  => __( 'SK1234567890', 'px-shop-core' ),
			'required'     => false,
			'class'        => array( 'form-row-wide', 'px-company-field', 'px-company-field--dic-dph' ),
			'priority'     => $company_priority + 3,
			'autocomplete' => 'off',
		);

		// Only Slovakia keeps the tax number and the VAT number apart, and
		// for everyone else the second box is a question the customer cannot
		// answer - but which country that is changes while the form is open,
		// so the field is always rendered and the script hides it. Dropping it
		// here would leave a Czech customer who switches to Slovakia with no
		// field to switch to.
		if ( ! self::uses_dic_dph() ) {
			unset( $fields['billing_dic_dph'] );
		}

		return $fields;
	}

	/**
	 * Checkout-only adjustments. Whether a field is required depends on a
	 * checkbox that exists only here, so it cannot live in billing_fields().
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public static function checkout_fields( $fields ) {
		// Required-ness is decided in validate(): the customer may untick the
		// company checkbox after filling something in, and a field marked
		// required in the markup would block them in the browser.
		foreach ( array( 'billing_ic', 'billing_dic', 'billing_dic_dph' ) as $key ) {
			if ( isset( $fields['billing'][ $key ] ) ) {
				$fields['billing'][ $key ]['required'] = false;
			}
		}

		// The checkbox is a checkout field rather than something printed above
		// the form, so it takes its place in the priority order - directly
		// above the block it opens, and below the name. It is never stored:
		// WooCommerce only keeps posted fields whose key starts with billing_
		// or shipping_, and this one deliberately does not.
		if ( px_company_checkbox_on() ) {
			$company_priority = isset( $fields['billing']['billing_company']['priority'] )
				? (int) $fields['billing']['billing_company']['priority']
				: 30;

			$fields['billing'][ self::TOGGLE ] = array(
				'type'     => 'checkbox',
				'label'    => __( 'I\'m buying for a company', 'px-shop-core' ),
				'required' => false,
				'class'    => array( 'form-row-wide', 'px-company-toggle' ),
				'priority' => $company_priority - 1,
				// A returning company customer arrives with the fields filled
				// in; an unticked box would look like the data was lost.
				'default'  => self::customer_has_company_data() ? 1 : 0,
			);
		}

		return $fields;
	}

	/**
	 * Drops the "(optional)" suffix from the company checkbox.
	 *
	 * WooCommerce appends it to every field that is not required. On a
	 * checkbox it reads as though there were a company purchase one could be
	 * obliged to make.
	 *
	 * @param string $field Rendered field HTML.
	 * @param string $key   Field key.
	 * @return string
	 */
	public static function clean_toggle_label( $field, $key ) {
		if ( self::TOGGLE !== $key ) {
			return $field;
		}

		return preg_replace( '~(&nbsp;)?<span class="optional">.*?</span>~', '', $field );
	}

	/**
	 * Does the customer already have company details stored?
	 *
	 * @return bool
	 */
	private static function customer_has_company_data() {
		if ( ! WC()->customer ) {
			return false;
		}

		$values = (string) WC()->customer->get_billing_company()
			. (string) WC()->customer->get_meta( 'billing_ic' )
			. (string) WC()->customer->get_meta( 'billing_dic' )
			. (string) WC()->customer->get_meta( 'billing_dic_dph' );

		return '' !== trim( $values );
	}

	/**
	 * Does the module supply the company name field when WooCommerce is set
	 * to hide it?
	 *
	 * @return bool
	 */
	private static function forces_company_field() {
		/**
		 * Filters whether the company name field is added back.
		 *
		 * Returning false honours the WooCommerce "Company field" setting even
		 * for company purchases - the block then collects numbers only.
		 *
		 * @param bool $force Whether to add the field.
		 */
		return (bool) apply_filters( 'px_company_force_company_field', true );
	}

	/**
	 * Is there a company name field to fill at all?
	 *
	 * @return bool
	 */
	private static function company_field_shown() {
		return 'hidden' !== get_option( 'woocommerce_checkout_company_field', 'optional' ) || self::forces_company_field();
	}

	/**
	 * Does this shop use the separate IČ DPH field at all?
	 *
	 * @return bool
	 */
	private static function uses_dic_dph() {
		/**
		 * Filters whether the separate IČ DPH field exists.
		 *
		 * Returning false removes it everywhere; a shop that never sells to
		 * Slovak companies has no use for it.
		 *
		 * @param bool $use Whether the field is used.
		 */
		return (bool) apply_filters( 'px_company_use_dic_dph', true );
	}

	/**
	 * Script that hides the fields behind the checkbox, offers the register
	 * lookup and checks the VAT id while the customer types.
	 */
	public static function enqueue() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}

		wp_enqueue_script(
			'px-checkout-company',
			plugins_url( 'assets/checkout-company.js', PX_SHOP_CORE_FILE ),
			array( 'jquery' ),
			PX_SHOP_CORE_VERSION,
			true
		);

		wp_localize_script(
			'px-checkout-company',
			'pxCompany',
			array(
				'root'      => esc_url_raw( rest_url( 'pixeler/v1/company/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'toggle'    => px_company_checkbox_on(),
				'autofill'  => px_company_autofill_on(),
				'registers' => PX_Company_Lookup::registers(),
				'vies'      => px_company_vies_mode(),
				'i18n'      => array(
					'load'      => __( 'Load from register', 'px-shop-core' ),
					'loading'   => __( 'Loading…', 'px-shop-core' ),
					'filled'    => __( 'Details filled in from the register.', 'px-shop-core' ),
					'vatValid'  => __( 'VAT id verified in VIES.', 'px-shop-core' ),
					'vatFailed' => __( 'VIES does not know this VAT id.', 'px-shop-core' ),
					'vatUnkown' => __( 'VIES did not answer, the number could not be verified.', 'px-shop-core' ),
					'error'     => __( 'The register did not answer. Please fill the details in by hand.', 'px-shop-core' ),
				),
			)
		);
	}

	/* ----------------------------- Validation ---------------------------- */

	/**
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Collected errors.
	 */
	public static function validate( $data, $errors ) {
		// WooCommerce verifies the checkout nonce before this hook runs.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$posted  = wp_unslash( $_POST );
		$company = isset( $posted[ self::TOGGLE ] ) && '1' === (string) $posted[ self::TOGGLE ];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$country = isset( $data['billing_country'] ) ? strtoupper( sanitize_text_field( $data['billing_country'] ) ) : '';
		$name    = isset( $data['billing_company'] ) ? sanitize_text_field( $data['billing_company'] ) : '';
		$ic      = isset( $data['billing_ic'] ) ? sanitize_text_field( $data['billing_ic'] ) : '';
		$dic     = isset( $data['billing_dic'] ) ? sanitize_text_field( $data['billing_dic'] ) : '';
		$dic_dph = isset( $data['billing_dic_dph'] ) ? sanitize_text_field( $data['billing_dic_dph'] ) : '';

		// Without the checkbox the company block is always visible, so anyone
		// who filled a field in is buying for a company.
		if ( ! px_company_checkbox_on() ) {
			$company = ( '' !== trim( $name . $ic . $dic . $dic_dph ) );
		}

		self::validate_required( $company, $name, $ic, $errors );

		if ( px_company_validate_format() ) {
			self::validate_format( $country, $ic, $dic, $dic_dph, $errors );
		}

		if ( '' !== $ic && px_company_verify_ic() ) {
			$details = PX_Company_Lookup::company( $ic, $country );

			// Only a register that answered "no such number" is an error. If
			// it did not answer at all the order goes through; the outage is
			// in the log and the customer can do nothing about it.
			if ( is_wp_error( $details ) && 'px_company_not_found' === $details->get_error_code() ) {
				$errors->add( 'validation', $details->get_error_message() );
			}
		}

		$vat_id = self::vat_id( $country, $dic, $dic_dph );

		if ( '' !== $vat_id && 'off' !== px_company_vies_mode() ) {
			$vies = self::vies( $vat_id );

			if ( false === $vies['valid'] && 'hard' === px_company_vies_mode() ) {
				$errors->add(
					'validation',
					__( 'The VAT id was not found in VIES. Please check it, or leave it empty and the order will be invoiced with VAT.', 'px-shop-core' )
				);
			} elseif ( null === $vies['valid'] ) {
				self::log( sprintf( 'VIES did not answer for %s, the order was let through.', $vat_id ) );
			}
		}
	}

	/**
	 * @param bool     $company Buying for a company.
	 * @param string   $name    Company name.
	 * @param string   $ic      Identification number.
	 * @param WP_Error $errors  Collected errors.
	 */
	private static function validate_required( $company, $name, $ic, $errors ) {
		if ( $company && px_company_required_company() && '' === $name && self::company_field_shown() ) {
			$errors->add(
				'required-field',
				/* translators: %s: field label */
				sprintf( __( '%s is a required field when buying for a company.', 'px-shop-core' ), '<strong>' . __( 'Company name', 'px-shop-core' ) . '</strong>' )
			);
		}

		$rule     = px_company_required_ic();
		$required = ( 'if_checkbox' === $rule && $company ) || ( 'if_company' === $rule && '' !== $name );

		if ( $required && '' === $ic ) {
			$errors->add(
				'required-field',
				/* translators: %s: field label */
				sprintf( __( '%s is a required field when buying for a company.', 'px-shop-core' ), '<strong>' . __( 'Company ID', 'px-shop-core' ) . '</strong>' )
			);
		}
	}

	/**
	 * Shape checks only. Whether the number exists is the registers' job -
	 * a checksum rule would reject identification numbers issued before it
	 * was introduced, and that costs orders.
	 *
	 * @param string   $country Billing country.
	 * @param string   $ic      Identification number.
	 * @param string   $dic     Tax number.
	 * @param string   $dic_dph VAT id.
	 * @param WP_Error $errors  Collected errors.
	 */
	private static function validate_format( $country, $ic, $dic, $dic_dph, $errors ) {
		$ic = preg_replace( '~\s~', '', $ic );

		if ( '' !== $ic ) {
			$pattern = in_array( $country, array( 'SK', 'CZ' ), true ) ? '~^\d{8}$~' : '~^[0-9A-Za-z]{6,14}$~';

			if ( ! preg_match( $pattern, $ic ) ) {
				$errors->add(
					'validation',
					in_array( $country, array( 'SK', 'CZ' ), true )
						? __( 'The company ID is not in the expected format (8 digits, no spaces).', 'px-shop-core' )
						: __( 'The company ID is not in the expected format.', 'px-shop-core' )
				);
			}
		}

		$dic     = preg_replace( '~\s~', '', $dic );
		$dic_dph = strtoupper( preg_replace( '~\s~', '', $dic_dph ) );

		if ( 'SK' === $country ) {
			if ( '' !== $dic && ! preg_match( '~^\d{10}$~', $dic ) ) {
				$errors->add( 'validation', __( 'The tax ID is not in the expected format (10 digits, no spaces).', 'px-shop-core' ) );
			}

			if ( '' !== $dic_dph && ! preg_match( '~^SK\d{10}$~', $dic_dph ) ) {
				$errors->add( 'validation', __( 'The VAT ID is not in the expected format (SK followed by 10 digits).', 'px-shop-core' ) );
			}

			// Both are the same number, the VAT one just carries the prefix.
			if ( '' !== $dic && '' !== $dic_dph && 'SK' . $dic !== $dic_dph ) {
				$errors->add( 'validation', __( 'The VAT ID has to be the tax ID with an SK prefix.', 'px-shop-core' ) );
			}
		} elseif ( '' !== $dic && in_array( $country, self::EU, true ) ) {
			// Greek VAT ids carry EL where the country code is GR.
			$prefix = ( 'GR' === $country ) ? 'EL' : $country;

			if ( ! preg_match( '~^' . $prefix . '[0-9A-Z]{2,12}$~', strtoupper( $dic ) ) ) {
				$errors->add(
					'validation',
					/* translators: %s: country prefix of a VAT id, e.g. CZ */
					sprintf( __( 'The tax ID is not in the expected format (prefix %s followed by the number).', 'px-shop-core' ), $prefix )
				);
			}
		}
	}

	/* ---------------------------- VAT decision --------------------------- */

	/**
	 * Which of the two fields holds the VAT id for this country.
	 *
	 * @param string $country Billing country.
	 * @param string $dic     Tax number.
	 * @param string $dic_dph VAT id.
	 * @return string
	 */
	public static function vat_id( $country, $dic, $dic_dph ) {
		$dic     = PX_Company_Lookup::normalize_vat( $dic );
		$dic_dph = PX_Company_Lookup::normalize_vat( $dic_dph );

		if ( 'SK' === strtoupper( (string) $country ) ) {
			return '' !== $dic_dph ? $dic_dph : ( '' !== $dic ? 'SK' . $dic : '' );
		}

		return '' !== $dic ? $dic : $dic_dph;
	}

	/**
	 * VIES answer for a number, asked at most once per request.
	 *
	 * @param string $vat_id VAT id.
	 * @return array
	 */
	private static function vies( $vat_id ) {
		if ( null === self::$vies || self::$vies['id'] !== $vat_id ) {
			self::$vies       = PX_Company_Lookup::vat( $vat_id );
			self::$vies['id'] = $vat_id;
		}

		return self::$vies;
	}

	/**
	 * Should this order carry VAT, and on what grounds.
	 *
	 * @param string $billing_country  Billing country.
	 * @param string $shipping_country Shipping country.
	 * @param string $vat_id           VAT id with its country prefix.
	 * @return array {
	 *     @type bool   $exempt Whether VAT is dropped.
	 *     @type string $reason domestic|export|reverse_charge|standard|unverified.
	 * }
	 */
	public static function decide( $billing_country, $shipping_country, $vat_id = '' ) {
		$shop = WC()->countries ? WC()->countries->get_base_country() : '';

		// Goods are taxed where they are delivered, services where the
		// customer is established; WooCommerce already knows which this shop
		// sells, so follow its setting instead of inventing a second one.
		$destination = ( 'billing' === get_option( 'woocommerce_tax_based_on', 'shipping' ) )
			? $billing_country
			: ( $shipping_country ? $shipping_country : $billing_country );

		$destination = strtoupper( (string) $destination );

		/**
		 * Filters the country the VAT decision is made for.
		 *
		 * @param string $destination      Country code.
		 * @param string $billing_country  Billing country.
		 * @param string $shipping_country Shipping country.
		 */
		$destination = apply_filters( 'px_company_vat_destination', $destination, $billing_country, $shipping_country );

		$result = array(
			'exempt' => false,
			'reason' => 'standard',
		);

		if ( '' === $destination || $destination === strtoupper( $shop ) ) {
			$result['reason'] = 'domestic';
		} elseif ( px_company_export_exempt() && ! in_array( $destination, self::EU, true ) ) {
			$result = array(
				'exempt' => true,
				'reason' => 'export',
			);
		} elseif ( px_company_reverse_charge() && in_array( $destination, self::EU, true ) && '' !== $vat_id ) {
			$result = self::decide_reverse_charge( $vat_id );
		}

		/**
		 * Filters the VAT decision.
		 *
		 * @param array  $result           Decision and its reason.
		 * @param string $destination      Country the decision was made for.
		 * @param string $vat_id           VAT id.
		 * @param string $billing_country  Billing country.
		 * @param string $shipping_country Shipping country.
		 */
		return apply_filters( 'px_company_vat_decision', $result, $destination, $vat_id, $billing_country, $shipping_country );
	}

	/**
	 * The reverse charge, and what counts as proof for it.
	 *
	 * @param string $vat_id VAT id.
	 * @return array
	 */
	private static function decide_reverse_charge( $vat_id ) {
		if ( ! px_company_exempt_requires_vies() ) {
			// The shop takes the risk: a well-formed number is enough.
			return array(
				'exempt' => true,
				'reason' => 'reverse_charge',
			);
		}

		if ( 'off' === px_company_vies_mode() ) {
			// Proof demanded and nothing set up to obtain it. Charging the VAT
			// is the safe half of the mistake; the log says why.
			self::log( 'Reverse charge is on and VIES proof is required, but the VIES check is off - VAT was charged.' );

			return array(
				'exempt' => false,
				'reason' => 'unverified',
			);
		}

		$vies = self::vies( $vat_id );

		if ( true === $vies['valid'] ) {
			return array(
				'exempt' => true,
				'reason' => 'reverse_charge',
			);
		}

		if ( null === $vies['valid'] ) {
			self::log( sprintf( 'VIES did not answer for %s, VAT was charged.', $vat_id ) );
		}

		return array(
			'exempt' => false,
			'reason' => 'unverified',
		);
	}

	/**
	 * Totals are recalculated over AJAX while the customer types, and the
	 * fields arrive as the serialized form.
	 *
	 * @param string $form Serialized checkout form.
	 */
	public static function exempt_on_order_review( $form ) {
		parse_str( (string) $form, $data );

		self::apply_exempt(
			isset( $data['billing_country'] ) ? $data['billing_country'] : '',
			self::shipping_country_from( $data ),
			self::vat_id(
				isset( $data['billing_country'] ) ? $data['billing_country'] : '',
				isset( $data['billing_dic'] ) ? $data['billing_dic'] : '',
				isset( $data['billing_dic_dph'] ) ? $data['billing_dic_dph'] : ''
			)
		);
	}

	/**
	 * The decision that counts: made from the posted data just before the
	 * order is created, so the totals on the order match it.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Collected errors.
	 */
	public static function exempt_on_validation( $data, $errors ) {
		if ( $errors->has_errors() ) {
			return;
		}

		self::apply_exempt(
			isset( $data['billing_country'] ) ? $data['billing_country'] : '',
			self::shipping_country_from( $data ),
			self::vat_id(
				isset( $data['billing_country'] ) ? $data['billing_country'] : '',
				isset( $data['billing_dic'] ) ? $data['billing_dic'] : '',
				isset( $data['billing_dic_dph'] ) ? $data['billing_dic_dph'] : ''
			)
		);
	}

	/**
	 * @param array $data Posted checkout data.
	 * @return string
	 */
	private static function shipping_country_from( $data ) {
		$ship_elsewhere = ! empty( $data['ship_to_different_address'] );

		if ( $ship_elsewhere && ! empty( $data['shipping_country'] ) ) {
			return $data['shipping_country'];
		}

		return isset( $data['billing_country'] ) ? $data['billing_country'] : '';
	}

	/**
	 * Writes the decision onto the customer so WooCommerce recalculates.
	 *
	 * @param string $billing_country  Billing country.
	 * @param string $shipping_country Shipping country.
	 * @param string $vat_id           VAT id.
	 * @return array The decision.
	 */
	private static function apply_exempt( $billing_country, $shipping_country, $vat_id ) {
		$decision = self::decide( $billing_country, $shipping_country, $vat_id );

		if ( WC()->customer ) {
			WC()->customer->set_is_vat_exempt( $decision['exempt'] );
		}

		return $decision;
	}

	/**
	 * Records why the order was or was not taxed. The number itself is on the
	 * order already; this is the part an accountant cannot reconstruct later,
	 * because VIES only answers about today.
	 *
	 * @param int      $order_id Order id.
	 * @param array    $data     Posted data.
	 * @param WC_Order $order    The order.
	 */
	public static function save_reason( $order_id, $data, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			return;
		}

		$vat_id   = self::vat_id( $order->get_billing_country(), $order->get_meta( '_billing_dic' ), $order->get_meta( '_billing_dic_dph' ) );
		$decision = self::decide( $order->get_billing_country(), $order->get_shipping_country(), $vat_id );

		$order->update_meta_data( self::REASON_META, $decision['reason'] );
		$order->save();

		self::log(
			sprintf(
				'Order %1$s: VAT %2$s (%3$s), VAT id %4$s.',
				$order->get_order_number(),
				$decision['exempt'] ? 'not charged' : 'charged',
				$decision['reason'],
				'' !== $vat_id ? $vat_id : '-'
			)
		);
	}

	/* ------------------------- Admin, mails, invoice ---------------------- */

	/**
	 * Editable in the order screen, next to the rest of the billing address.
	 *
	 * @param array $fields Admin billing fields.
	 * @return array
	 */
	public static function admin_fields( $fields ) {
		$fields['ic'] = array(
			'label' => __( 'Company ID', 'px-shop-core' ),
			'show'  => true,
		);

		$fields['dic'] = array(
			'label' => __( 'Tax ID', 'px-shop-core' ),
			'show'  => true,
		);

		$fields['dic_dph'] = array(
			'label' => __( 'VAT ID', 'px-shop-core' ),
			'show'  => true,
		);

		return $fields;
	}

	/**
	 * The same three on the customer profile.
	 *
	 * @param array $fields Customer meta fields.
	 * @return array
	 */
	public static function profile_fields( $fields ) {
		if ( ! isset( $fields['billing']['fields'] ) ) {
			return $fields;
		}

		$fields['billing']['fields']['billing_ic'] = array(
			'label'       => __( 'Company ID', 'px-shop-core' ),
			'description' => '',
		);

		$fields['billing']['fields']['billing_dic'] = array(
			'label'       => __( 'Tax ID', 'px-shop-core' ),
			'description' => '',
		);

		$fields['billing']['fields']['billing_dic_dph'] = array(
			'label'       => __( 'VAT ID', 'px-shop-core' ),
			'description' => '',
		);

		return $fields;
	}

	/**
	 * Adds the three placeholders to every localised address format, so the
	 * numbers show up wherever WooCommerce prints an address.
	 *
	 * @param array $formats Address formats per country.
	 * @return array
	 */
	public static function address_formats( $formats ) {
		foreach ( $formats as $country => $format ) {
			$formats[ $country ] = $format . "\n{billing_ic}\n{billing_dic}\n{billing_dic_dph}";
		}

		return $formats;
	}

	/**
	 * @param array $replacements Placeholder values.
	 * @param array $args         Address parts.
	 * @return array
	 */
	public static function address_replacements( $replacements, $args ) {
		$labels = array(
			'billing_ic'      => __( 'Company ID', 'px-shop-core' ),
			'billing_dic'     => __( 'Tax ID', 'px-shop-core' ),
			'billing_dic_dph' => __( 'VAT ID', 'px-shop-core' ),
		);

		foreach ( $labels as $key => $label ) {
			$replacements[ '{' . $key . '}' ] = empty( $args[ $key ] )
				? ''
				: $label . ': ' . $args[ $key ];
		}

		return $replacements;
	}

	/**
	 * Feeds the placeholders from the order.
	 *
	 * @param array    $address Address parts.
	 * @param WC_Order $order   The order.
	 * @return array
	 */
	public static function order_address( $address, $order ) {
		$address['billing_ic']      = $order->get_meta( '_billing_ic' );
		$address['billing_dic']     = $order->get_meta( '_billing_dic' );
		$address['billing_dic_dph'] = $order->get_meta( '_billing_dic_dph' );

		return $address;
	}

	/**
	 * SuperFaktúra fills the invoice from WPify Woo's meta and checks that the
	 * plugin is active before it does. With WPify gone the keys are still
	 * there and still ours, so hand them over.
	 *
	 * @param array    $client Client data going to the invoice.
	 * @param WC_Order $order  The order.
	 * @return array
	 */
	public static function superfaktura_client_data( $client, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $client;
		}

		$ic      = $order->get_meta( '_billing_ic' );
		$dic     = $order->get_meta( '_billing_dic' );
		$dic_dph = $order->get_meta( '_billing_dic_dph' );

		// Never overwrite what another integration already resolved.
		if ( empty( $client['ico'] ) && $ic ) {
			$client['ico'] = $ic;
		}

		if ( empty( $client['dic'] ) && $dic ) {
			$client['dic'] = $dic;
		}

		if ( empty( $client['ic_dph'] ) ) {
			$vat = ( 'SK' === $order->get_billing_country() ) ? $dic_dph : $dic;

			if ( $vat ) {
				$client['ic_dph'] = $vat;
			}
		}

		return $client;
	}

	/**
	 * Keeps SuperFaktúra's own checkout company block switched off.
	 *
	 * Its invoices still get the numbers - through sf_client_data above.
	 *
	 * @param mixed $value Short-circuit value for the option.
	 * @return string
	 */
	public static function silence_superfaktura_fields( $value ) {
		return 'no';
	}

	/**
	 * Why this order was or was not taxed, in the order screen.
	 *
	 * @param WC_Order $order The order.
	 */
	public static function admin_vat_notice( $order ) {
		$reason = $order->get_meta( self::REASON_META );

		if ( ! $reason ) {
			return;
		}

		$labels = array(
			'domestic'       => __( 'Domestic sale, VAT charged.', 'px-shop-core' ),
			'standard'       => __( 'VAT charged.', 'px-shop-core' ),
			'export'         => __( 'Export outside the EU, VAT not charged.', 'px-shop-core' ),
			'reverse_charge' => __( 'EU reverse charge, VAT not charged (VAT id verified).', 'px-shop-core' ),
			'unverified'     => __( 'VAT id could not be verified, VAT charged.', 'px-shop-core' ),
		);

		echo '<p class="px-vat-reason"><strong>' . esc_html__( 'VAT', 'px-shop-core' ) . ':</strong> ';
		echo esc_html( isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason );
		echo '</p>';
	}

	/* -------------------------------- REST -------------------------------- */

	public static function rest_routes() {
		register_rest_route(
			'pixeler/v1',
			'/company/lookup',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_lookup' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'ic'      => array( 'required' => true ),
					'country' => array( 'required' => false ),
				),
			)
		);

		register_rest_route(
			'pixeler/v1',
			'/company/vat',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_vat' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'vat' => array( 'required' => true ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_lookup( $request ) {
		if ( ! px_company_autofill_on() ) {
			return new WP_Error( 'px_company_off', __( 'Register lookup is switched off.', 'px-shop-core' ), array( 'status' => 403 ) );
		}

		if ( self::rate_limited() ) {
			return new WP_Error( 'px_company_rate_limited', __( 'Too many requests, please wait a moment.', 'px-shop-core' ), array( 'status' => 429 ) );
		}

		$details = PX_Company_Lookup::company(
			$request->get_param( 'ic' ),
			$request->get_param( 'country' ) ? $request->get_param( 'country' ) : 'SK'
		);

		if ( is_wp_error( $details ) ) {
			$details->add_data( array( 'status' => 404 ) );

			return $details;
		}

		return rest_ensure_response( $details );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_vat( $request ) {
		if ( 'off' === px_company_vies_mode() ) {
			return new WP_Error( 'px_company_off', __( 'VAT id verification is switched off.', 'px-shop-core' ), array( 'status' => 403 ) );
		}

		if ( self::rate_limited() ) {
			return new WP_Error( 'px_company_rate_limited', __( 'Too many requests, please wait a moment.', 'px-shop-core' ), array( 'status' => 429 ) );
		}

		return rest_ensure_response( PX_Company_Lookup::vat( $request->get_param( 'vat' ) ) );
	}

	/**
	 * Both endpoints hand a visitor a free proxy to a public register, so the
	 * shop must not be the cheapest way to scrape one.
	 *
	 * @return bool
	 */
	private static function rate_limited() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( '' === $ip ) {
			return false;
		}

		$key  = 'px_company_rl_' . md5( $ip );
		$hits = (int) get_transient( $key );

		/**
		 * Filters how many register lookups one address gets per five minutes.
		 *
		 * @param int $limit Number of requests.
		 */
		$limit = (int) apply_filters( 'px_company_rate_limit', 30 );

		if ( $hits >= $limit ) {
			return true;
		}

		set_transient( $key, $hits + 1, 5 * MINUTE_IN_SECONDS );

		return false;
	}

	/**
	 * @param string $message What happened.
	 */
	private static function log( $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->info( $message, array( 'source' => 'px-shop-core' ) );
	}
}

/* ------------------------------- Helpers ------------------------------- */

/**
 * Company details of an order, ready for a template. Empty values are left
 * out, so a theme can print the whole array without checking anything.
 *
 * @param WC_Order|int $order Order or its id.
 * @return array Label => value.
 */
function px_company_order_details( $order ) {
	$order = ( $order instanceof WC_Order ) ? $order : wc_get_order( $order );

	if ( ! $order ) {
		return array();
	}

	$details = array(
		__( 'Company ID', 'px-shop-core' ) => $order->get_meta( '_billing_ic' ),
		__( 'Tax ID', 'px-shop-core' )     => $order->get_meta( '_billing_dic' ),
		__( 'VAT ID', 'px-shop-core' )     => $order->get_meta( '_billing_dic_dph' ),
	);

	return array_filter( $details );
}

/**
 * Why the order was or was not taxed: domestic, standard, export,
 * reverse_charge or unverified.
 *
 * @param WC_Order|int $order Order or its id.
 * @return string
 */
function px_company_vat_reason( $order ) {
	$order = ( $order instanceof WC_Order ) ? $order : wc_get_order( $order );

	return $order ? (string) $order->get_meta( PX_Company_Fields::REASON_META ) : '';
}

/**
 * @return bool
 */
function px_company_checkbox_on() {
	return 'yes' === get_option( 'px_company_checkbox', 'yes' );
}

/**
 * @return bool
 */
function px_company_move_fields() {
	return 'yes' === get_option( 'px_company_move_fields', 'yes' );
}

/**
 * @return bool
 */
function px_company_required_company() {
	return 'yes' === get_option( 'px_company_required_company', 'yes' );
}

/**
 * @return string One of if_checkbox, if_company, never.
 */
function px_company_required_ic() {
	return (string) get_option( 'px_company_required_ic', 'if_checkbox' );
}

/**
 * @return bool
 */
function px_company_validate_format() {
	return 'yes' === get_option( 'px_company_validate_format', 'yes' );
}

/**
 * @return bool
 */
function px_company_autofill_on() {
	return 'yes' === get_option( 'px_company_autofill', 'no' );
}

/**
 * @return bool
 */
function px_company_verify_ic() {
	return 'yes' === get_option( 'px_company_verify_ic', 'no' );
}

/**
 * @return string One of off, soft, hard.
 */
function px_company_vies_mode() {
	return (string) get_option( 'px_company_vies', 'off' );
}

/**
 * @return bool
 */
function px_company_reverse_charge() {
	return 'yes' === get_option( 'px_company_reverse_charge', 'no' );
}

/**
 * @return bool
 */
function px_company_export_exempt() {
	return 'yes' === get_option( 'px_company_export_exempt', 'no' );
}

/**
 * @return bool
 */
function px_company_exempt_requires_vies() {
	return 'yes' === get_option( 'px_company_exempt_requires_vies', 'yes' );
}

/**
 * Is the module allowed to change what an order is taxed?
 *
 * @return bool
 */
function px_company_vat_rules_on() {
	return px_company_reverse_charge() || px_company_export_exempt();
}
