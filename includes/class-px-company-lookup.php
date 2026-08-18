<?php
/**
 * Company registers behind one interface: RPO (SK), ARES (CZ), VIES (EU VAT).
 *
 * All three are plain JSON over HTTPS, so this needs no composer package and
 * no SOAP client - wp_remote_get() is enough. The Vies SOAP service and the
 * old ARES XML endpoint are deliberately not used; both are slower and the
 * SOAP one drags in a dependency we would have to keep updated.
 *
 * Everything is cached. The checkout asks the same question on every cart
 * recalculation, and the registers are slow: VIES regularly takes seconds and
 * goes down for member-state maintenance. A cache miss on a dead register must
 * never be the reason an order cannot be placed, so a transport failure is
 * reported as "unknown" (null), never as "invalid" - the caller decides what
 * an unknown answer means for it.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Company_Lookup {

	/** Register of legal entities, Statistical Office of the Slovak Republic. */
	const RPO_URL = 'https://api.statistics.sk/rpo/v1/search?identifier=%s';

	/** ARES REST v3, Czech Ministry of Finance. */
	const ARES_URL = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/%s';

	/** VIES REST, European Commission. */
	const VIES_URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';

	/** Company data barely changes; a week off the register is plenty. */
	const COMPANY_TTL = WEEK_IN_SECONDS;

	/** A VAT id that VIES confirms stays confirmed for a day. */
	const VAT_VALID_TTL = DAY_IN_SECONDS;

	/**
	 * A rejected VAT id is cached much shorter: a freshly registered payer
	 * appears in VIES within days and a customer who fixed a typo must not
	 * wait a day for the answer to change.
	 */
	const VAT_INVALID_TTL = HOUR_IN_SECONDS;

	const TIMEOUT = 6;

	/* ------------------------------ Registers ----------------------------- */

	/**
	 * Countries whose register this can query.
	 *
	 * @return array
	 */
	public static function registers() {
		/**
		 * Filters the countries a register lookup is offered for.
		 *
		 * @param array $countries Country codes.
		 */
		return (array) apply_filters( 'px_company_register_countries', array( 'SK', 'CZ' ) );
	}

	/**
	 * Company details for an identification number.
	 *
	 * @param string $ic      Identification number (IČO), digits only.
	 * @param string $country Billing country code; picks the register.
	 * @return array|WP_Error Details on success, WP_Error 'px_company_not_found'
	 *                        when the register does not know the number and
	 *                        'px_company_unavailable' when it did not answer.
	 */
	public static function company( $ic, $country = 'SK' ) {
		$ic      = preg_replace( '~\D~', '', (string) $ic );
		$country = strtoupper( substr( (string) $country, 0, 2 ) );

		if ( '' === $ic ) {
			return new WP_Error( 'px_company_not_found', __( 'Enter an identification number first.', 'px-shop-core' ) );
		}

		// Only two countries publish a register we can ask. Anywhere else the
		// number is whatever the customer typed, and saying so is better than
		// quietly searching the Slovak register for a German company.
		if ( ! in_array( $country, self::registers(), true ) ) {
			return new WP_Error( 'px_company_unsupported', __( 'There is no register available for this country.', 'px-shop-core' ) );
		}

		$cache = 'px_company_' . $country . '_' . $ic;
		$hit   = get_transient( $cache );

		// A cached miss is stored as the string 'none' - false already means
		// "nothing cached" to the transient API, so it cannot be reused here.
		if ( 'none' === $hit ) {
			return new WP_Error( 'px_company_not_found', self::not_found_message( $country ) );
		}

		if ( is_array( $hit ) ) {
			return $hit;
		}

		$details = ( 'CZ' === $country ) ? self::ares( $ic ) : self::rpo( $ic );

		if ( is_wp_error( $details ) ) {
			// Only a definitive "not here" is worth remembering; an outage
			// would otherwise lock the shop out of the register for a week.
			if ( 'px_company_not_found' === $details->get_error_code() ) {
				set_transient( $cache, 'none', HOUR_IN_SECONDS );
			}

			return $details;
		}

		set_transient( $cache, $details, self::COMPANY_TTL );

		/**
		 * Filters company details loaded from a public register.
		 *
		 * @param array  $details Details keyed like the checkout fields.
		 * @param string $ic      Identification number that was looked up.
		 * @param string $country Country whose register answered.
		 */
		return apply_filters( 'px_company_details', $details, $ic, $country );
	}

	/**
	 * RPO (SK). Returns every historical name and address of the entity, so
	 * the current one has to be picked: records that ended have a validTo,
	 * the one in force does not.
	 *
	 * @param string $ic Identification number.
	 * @return array|WP_Error
	 */
	private static function rpo( $ic ) {
		$body = self::request( sprintf( self::RPO_URL, rawurlencode( $ic ) ), 'RPO' );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$results = isset( $body['results'] ) && is_array( $body['results'] ) ? $body['results'] : array();

		if ( empty( $results ) ) {
			return new WP_Error( 'px_company_not_found', self::not_found_message( 'SK' ) );
		}

		$record     = null;
		$terminated = false;

		// The endpoint searches rather than looks up, so a result is not
		// automatically the entity that was asked for. Filling a checkout with
		// somebody else's company is worse than not filling it at all.
		//
		// RPO also keeps every organisation that ever existed, and the number
		// of a company wound up in 1993 is a plausible typo. A wound-up entity
		// counts as "not there" - a customer cannot be buying as one.
		foreach ( $results as $candidate ) {
			if ( ! isset( $candidate['identifiers'] ) || ! is_array( $candidate['identifiers'] ) ) {
				continue;
			}

			foreach ( $candidate['identifiers'] as $identifier ) {
				if ( ! isset( $identifier['value'] ) || (string) $identifier['value'] !== $ic ) {
					continue;
				}

				if ( ! empty( $candidate['termination'] ) ) {
					$terminated = true;
					continue 2;
				}

				$record = $candidate;
				break 2;
			}
		}

		if ( null === $record ) {
			return new WP_Error(
				'px_company_not_found',
				$terminated
					? __( 'This company is no longer registered in RPO.', 'px-shop-core' )
					: self::not_found_message( 'SK' )
			);
		}

		$name    = self::current_value( isset( $record['fullNames'] ) ? $record['fullNames'] : array() );
		$address = self::current_record( isset( $record['addresses'] ) ? $record['addresses'] : array() );

		if ( '' === $name ) {
			return new WP_Error( 'px_company_not_found', self::not_found_message( 'SK' ) );
		}

		$street = isset( $address['street'] ) ? $address['street'] : '';
		$number = isset( $address['buildingNumber'] ) ? $address['buildingNumber'] : '';

		// Sole traders often have no street, only a registration number
		// within the village; without it the address line would be empty.
		if ( '' === $number && ! empty( $address['regNumber'] ) ) {
			$number = (string) $address['regNumber'];
		}

		$postcode = '';
		if ( ! empty( $address['postalCodes'][0] ) ) {
			$postcode = self::format_postcode( $address['postalCodes'][0] );
		}

		return array(
			'company'   => $name,
			'ic'        => $ic,
			'dic'       => '', // RPO does not publish tax numbers.
			'dic_dph'   => '',
			'address_1' => trim( $street . ' ' . $number ),
			'city'      => isset( $address['municipality']['value'] ) ? $address['municipality']['value'] : '',
			'postcode'  => $postcode,
			'country'   => 'SK',
		);
	}

	/**
	 * ARES (CZ). One entity per request, current state only, and unlike RPO
	 * it also returns the tax number.
	 *
	 * @param string $ic Identification number.
	 * @return array|WP_Error
	 */
	private static function ares( $ic ) {
		$body = self::request( sprintf( self::ARES_URL, rawurlencode( $ic ) ), 'ARES' );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( empty( $body['obchodniJmeno'] ) ) {
			return new WP_Error( 'px_company_not_found', self::not_found_message( 'CZ' ) );
		}

		// A company that has been wound up is not somebody's billing address.
		if ( ! empty( $body['datumZaniku'] ) ) {
			return new WP_Error( 'px_company_not_found', __( 'This company is no longer registered in ARES.', 'px-shop-core' ) );
		}

		$seat   = isset( $body['sidlo'] ) ? $body['sidlo'] : array();
		$street = isset( $seat['nazevUlice'] ) ? $seat['nazevUlice'] : '';

		// Czech addresses carry two house numbers: the descriptive one and the
		// orientation one, written "1522/53" when both exist.
		$number = isset( $seat['cisloDomovni'] ) ? (string) $seat['cisloDomovni'] : '';
		if ( ! empty( $seat['cisloOrientacni'] ) ) {
			$number = trim( $number . '/' . $seat['cisloOrientacni'], '/' );
		}

		// Villages without named streets put the town name in the street slot.
		if ( '' === $street ) {
			$street = isset( $seat['nazevCastiObce'] ) ? $seat['nazevCastiObce'] : '';
		}

		return array(
			'company'   => $body['obchodniJmeno'],
			'ic'        => $ic,
			'dic'       => isset( $body['dic'] ) ? $body['dic'] : '',
			'dic_dph'   => '',
			'address_1' => trim( $street . ' ' . $number ),
			'city'      => isset( $seat['nazevObce'] ) ? $seat['nazevObce'] : '',
			'postcode'  => isset( $seat['psc'] ) ? self::format_postcode( $seat['psc'] ) : '',
			'country'   => 'CZ',
		);
	}

	/* -------------------------------- VIES -------------------------------- */

	/**
	 * Verifies an EU VAT identification number.
	 *
	 * @param string $vat_id VAT id with the country prefix, e.g. SK2020273893.
	 * @return array {
	 *     @type bool|null $valid   True, false, or null when VIES did not answer.
	 *     @type string    $name    Registered name, when the member state shares it.
	 *     @type string    $address Registered address, when shared.
	 * }
	 */
	public static function vat( $vat_id ) {
		$vat_id = self::normalize_vat( $vat_id );

		if ( ! preg_match( '~^([A-Z]{2})([0-9A-Z]{2,12})$~', $vat_id, $parts ) ) {
			return array(
				'valid'   => false,
				'name'    => '',
				'address' => '',
			);
		}

		$cache = 'px_vat_' . $vat_id;
		$hit   = get_transient( $cache );

		if ( is_array( $hit ) ) {
			return $hit;
		}

		$body = self::request( sprintf( self::VIES_URL, $parts[1], $parts[2] ), 'VIES' );

		if ( is_wp_error( $body ) || ! isset( $body['isValid'] ) ) {
			// Unknown, not invalid. Caching this would turn a five-minute
			// outage at the Commission into a day of wrong answers.
			return array(
				'valid'   => null,
				'name'    => '',
				'address' => '',
			);
		}

		$result = array(
			'valid' => (bool) $body['isValid'],
			// Member states that do not share the name send "---".
			'name'    => ( isset( $body['name'] ) && '---' !== $body['name'] ) ? $body['name'] : '',
			'address' => ( isset( $body['address'] ) && '---' !== $body['address'] ) ? $body['address'] : '',
		);

		set_transient( $cache, $result, $result['valid'] ? self::VAT_VALID_TTL : self::VAT_INVALID_TTL );

		return $result;
	}

	/* ------------------------------- Helpers ------------------------------ */

	/**
	 * Uppercase, no spaces, no punctuation. Customers paste VAT ids with all
	 * three.
	 *
	 * @param string $vat_id Raw input.
	 * @return string
	 */
	public static function normalize_vat( $vat_id ) {
		return strtoupper( preg_replace( '~[^0-9A-Za-z]~', '', (string) $vat_id ) );
	}

	/**
	 * One GET, decoded JSON.
	 *
	 * @param string $url     Endpoint.
	 * @param string $service Name used in the log.
	 * @return array|WP_Error Decoded body, or 'px_company_not_found' on a 404
	 *                        and 'px_company_unavailable' on anything else.
	 */
	private static function request( $url, $service ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::log( $service . ' unreachable: ' . $response->get_error_message() );

			return new WP_Error( 'px_company_unavailable', self::unavailable_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 404 === $code ) {
			return new WP_Error( 'px_company_not_found', __( 'Not found in the register.', 'px-shop-core' ) );
		}

		if ( 200 !== $code ) {
			self::log( $service . ' answered HTTP ' . $code );

			return new WP_Error( 'px_company_unavailable', self::unavailable_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			self::log( $service . ' sent a body that is not JSON' );

			return new WP_Error( 'px_company_unavailable', self::unavailable_message() );
		}

		return $body;
	}

	/**
	 * The entry in force out of a validFrom/validTo history: the one that has
	 * not ended, or failing that the one that started last.
	 *
	 * @param array $entries History as the register returns it.
	 * @return array
	 */
	private static function current_record( $entries ) {
		if ( ! is_array( $entries ) || empty( $entries ) ) {
			return array();
		}

		$best = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( empty( $entry['validTo'] ) ) {
				return $entry;
			}

			$from      = isset( $entry['validFrom'] ) ? $entry['validFrom'] : '';
			$best_from = isset( $best['validFrom'] ) ? $best['validFrom'] : '';

			if ( '' === $best_from || $from > $best_from ) {
				$best = $entry;
			}
		}

		return $best;
	}

	/**
	 * current_record() for histories whose entries are plain values.
	 *
	 * @param array $entries History as the register returns it.
	 * @return string
	 */
	private static function current_value( $entries ) {
		$record = self::current_record( $entries );

		return isset( $record['value'] ) ? (string) $record['value'] : '';
	}

	/**
	 * SK and CZ postcodes are written in three-plus-two form. Registers send
	 * them packed, customers type them spaced; store the readable one.
	 *
	 * @param string|int $postcode Raw postcode.
	 * @return string
	 */
	private static function format_postcode( $postcode ) {
		$digits = preg_replace( '~\D~', '', (string) $postcode );

		// Leading zeros are lost when a register sends the postcode as a
		// number (ARES does), and every SK/CZ postcode has five digits.
		$digits = str_pad( $digits, 5, '0', STR_PAD_LEFT );

		return 5 === strlen( $digits ) ? substr( $digits, 0, 3 ) . ' ' . substr( $digits, 3 ) : $digits;
	}

	/**
	 * @param string $country Country whose register was asked.
	 * @return string
	 */
	private static function not_found_message( $country ) {
		return ( 'CZ' === $country )
			? __( 'This identification number is not in the ARES register.', 'px-shop-core' )
			: __( 'This identification number is not in the RPO register.', 'px-shop-core' );
	}

	/**
	 * @return string
	 */
	private static function unavailable_message() {
		return __( 'The register did not answer. Please fill the details in by hand.', 'px-shop-core' );
	}

	/**
	 * Register trouble goes to the WooCommerce log, never to the customer -
	 * it says nothing they could act on.
	 *
	 * @param string $message What happened.
	 */
	private static function log( $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->warning( $message, array( 'source' => 'px-shop-core' ) );
	}
}
