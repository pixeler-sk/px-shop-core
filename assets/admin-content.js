/**
 * Banner box: show only the fields the chosen layout actually uses.
 *
 * The supports map comes from the layout registry (PHP), so a layout added
 * by a project behaves the same without touching this file.
 */
( function () {
	'use strict';

	var select = document.getElementById( 'px_content_layout' );

	if ( ! select || typeof window.pxContentLayouts === 'undefined' ) {
		return;
	}

	var box    = select.closest( '.px-content-box' ) || document;
	var fields = box.querySelectorAll( '[data-px-field]' );
	// The featured image is a core box, but for a text-only layout it is
	// just a field that does nothing.
	var thumb  = document.getElementById( 'postimagediv' );

	function apply() {
		var supports = window.pxContentLayouts[ select.value ] || [];

		fields.forEach( function ( field ) {
			var used = supports.indexOf( field.getAttribute( 'data-px-field' ) ) !== -1;

			field.hidden = ! used;
		} );

		if ( thumb ) {
			thumb.hidden = supports.indexOf( 'image' ) === -1;
		}
	}

	select.addEventListener( 'change', apply );
	apply();
} )();
