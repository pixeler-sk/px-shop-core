/**
 * Company details on the checkout.
 *
 * Three jobs, all of them optional and driven by the settings handed over in
 * pxCompany: fold the company block behind a checkbox, fill it from the public
 * register, and tell the customer whether their VAT id checks out before they
 * find out from a failed order.
 *
 * No styling here - the script only toggles the `hidden` attribute and sets
 * px-* state classes, the theme decides what any of it looks like.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.pxCompany === 'undefined' ) {
		return;
	}

	var settings = window.pxCompany;
	var i18n = settings.i18n || {};

	var rows = {
		company: '#billing_company_field',
		ic: '#billing_ic_field',
		dic: '#billing_dic_field',
		dicDph: '#billing_dic_dph_field'
	};

	/**
	 * Shows or hides a form row. A hidden row must not keep its required
	 * attribute, or the browser refuses to submit a form the customer cannot
	 * even see.
	 */
	function setRowVisible( selector, visible ) {
		var $row = $( selector );

		if ( ! $row.length ) {
			return;
		}

		$row.prop( 'hidden', ! visible );
		$row.toggleClass( 'px-company-hidden', ! visible );

		var $input = $row.find( 'input, select' );

		if ( ! visible ) {
			$input.prop( 'required', false );
		}
	}

	/** The message line under a field, created on first use. */
	function message( selector, text, state ) {
		var $row = $( selector );

		if ( ! $row.length ) {
			return;
		}

		var $msg = $row.find( '.px-company-msg' );

		if ( ! $msg.length ) {
			$msg = $( '<span class="px-company-msg" />' ).appendTo( $row );
		}

		$msg.attr( 'class', 'px-company-msg' + ( state ? ' px-company-msg--' + state : '' ) );
		$msg.text( text || '' );
	}

	function billingCountry() {
		return ( $( '#billing_country' ).val() || '' ).toUpperCase();
	}

	/** WooCommerce recalculates the totals from the fields we just changed. */
	function refreshTotals() {
		$( document.body ).trigger( 'update_checkout' );
	}

	/* ------------------------------ Fold ------------------------------- */

	var $toggle = $( '#company_details' );

	function applyToggle() {
		if ( ! settings.toggle || ! $toggle.length ) {
			return;
		}

		var open = $toggle.is( ':checked' );

		setRowVisible( rows.company, open );
		setRowVisible( rows.ic, open );
		setRowVisible( rows.dic, open );
		setRowVisible( rows.dicDph, open && 'SK' === billingCountry() );
	}

	$toggle.on( 'change', function () {
		applyToggle();

		// Anything left behind in a collapsed block would still be posted: the
		// numbers would still decide the VAT and the company name would still
		// end up on a private customer's invoice. Closing the block clears it.
		if ( ! $toggle.is( ':checked' ) ) {
			$( '#billing_company, #billing_ic, #billing_dic, #billing_dic_dph' ).val( '' );
			message( rows.dic, '' );
			message( rows.dicDph, '' );
			refreshTotals();
		}
	} );

	/* ---------------------------- Country ------------------------------ */

	/** Only Slovakia keeps the tax number and the VAT number apart. */
	function applyCountry() {
		if ( settings.toggle && $toggle.length ) {
			applyToggle();
		} else {
			setRowVisible( rows.dicDph, 'SK' === billingCountry() );
		}
	}

	// Deferred: WooCommerce reorders and re-shows address rows on the same
	// event, and whatever runs last decides what the customer sees.
	$( document.body ).on( 'change', '#billing_country', function () {
		window.setTimeout( applyCountry, 0 );
	} );

	$( document.body ).on( 'country_to_state_changed', function () {
		window.setTimeout( applyCountry, 0 );
	} );

	/* --------------------------- Register ------------------------------ */

	function request( path, params ) {
		return $.ajax( {
			url: settings.root + path,
			method: 'GET',
			data: params,
			beforeSend: function ( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', settings.nonce );
			}
		} );
	}

	/** Only Slovakia and Czechia publish a register we can ask. */
	function hasRegister() {
		var supported = settings.registers || [];

		return -1 !== $.inArray( billingCountry(), supported );
	}

	if ( settings.autofill ) {
		var $button = $( '<button type="button" class="px-company-lookup button" />' ).text( i18n.load );

		$( '#billing_ic' ).after( $button );

		$button.prop( 'hidden', ! hasRegister() );

		$( document.body ).on( 'change', '#billing_country', function () {
			$button.prop( 'hidden', ! hasRegister() );
		} );

		$button.on( 'click', function () {
			var ic = ( $( '#billing_ic' ).val() || '' ).replace( /\s/g, '' );

			if ( ! ic ) {
				return;
			}

			$button.prop( 'disabled', true ).text( i18n.loading );
			message( rows.ic, '' );

			request( 'lookup', { ic: ic, country: billingCountry() || 'SK' } )
				.done( function ( details ) {
					// Only fill what the register knows; an empty answer must
					// not wipe an address the customer already typed.
					if ( details.company ) {
						$( '#billing_company' ).val( details.company );
					}

					if ( details.address_1 ) {
						$( '#billing_address_1' ).val( details.address_1 );
					}

					if ( details.city ) {
						$( '#billing_city' ).val( details.city );
					}

					if ( details.postcode ) {
						$( '#billing_postcode' ).val( details.postcode );
					}

					if ( details.dic ) {
						$( '#billing_dic' ).val( details.dic ).trigger( 'change' );
					}

					message( rows.ic, i18n.filled, 'ok' );
					refreshTotals();
				} )
				.fail( function ( xhr ) {
					var text = ( xhr.responseJSON && xhr.responseJSON.message ) ? xhr.responseJSON.message : i18n.error;

					message( rows.ic, text, 'error' );
				} )
				.always( function () {
					$button.prop( 'disabled', false ).text( i18n.load );
				} );
		} );
	}

	/* ------------------------------ VIES ------------------------------- */

	if ( 'off' !== settings.vies ) {
		var lastChecked = '';

		$( document.body ).on( 'change', '#billing_dic, #billing_dic_dph', function () {
			var isSk = 'SK' === billingCountry();
			var row = isSk ? rows.dicDph : rows.dic;
			var raw = isSk
				? ( $( '#billing_dic_dph' ).val() || $( '#billing_dic' ).val() || '' )
				: ( $( '#billing_dic' ).val() || '' );

			var vat = raw.replace( /[^0-9A-Za-z]/g, '' ).toUpperCase();

			if ( isSk && /^\d{10}$/.test( vat ) ) {
				vat = 'SK' + vat;
			}

			if ( ! vat || vat === lastChecked ) {
				return;
			}

			lastChecked = vat;
			message( row, i18n.loading, 'pending' );

			request( 'vat', { vat: vat } )
				.done( function ( result ) {
					if ( true === result.valid ) {
						message( row, i18n.vatValid, 'ok' );
					} else if ( false === result.valid ) {
						message( row, i18n.vatFailed, 'error' );
					} else {
						message( row, i18n.vatUnkown, 'warn' );
					}

					// The answer can change the VAT, so the totals follow it.
					refreshTotals();
				} )
				.fail( function () {
					message( row, i18n.vatUnkown, 'warn' );
				} );
		} );
	}

	applyToggle();
	if ( ! settings.toggle || ! $toggle.length ) {
		setRowVisible( rows.dicDph, 'SK' === billingCountry() );
	}
} )( jQuery );
