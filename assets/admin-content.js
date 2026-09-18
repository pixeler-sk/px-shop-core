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

/**
 * Background video: pick a file from the media library.
 *
 * Only MP4 / WebM are offered (the list the PHP side accepts, so the saved
 * value never gets dropped on save).
 */
( function () {
	'use strict';

	var input = document.getElementById( 'px_banner_video_id' );

	if ( ! input || ! window.wp || ! window.wp.media ) {
		return;
	}

	var box   = input.closest( '.px-content-field' );
	var name  = box.querySelector( '[data-px-video-file]' );
	var pick  = box.querySelector( '[data-px-video-pick]' );
	var clear = box.querySelector( '[data-px-video-clear]' );
	var cfg   = window.pxContentVideo || {};
	var frame = null;

	pick.addEventListener( 'click', function () {
		if ( ! frame ) {
			frame = window.wp.media( {
				title: cfg.title || '',
				button: { text: cfg.button || '' },
				library: { type: cfg.mimes || [ 'video/mp4', 'video/webm' ] },
				multiple: false
			} );

			frame.on( 'select', function () {
				var file = frame.state().get( 'selection' ).first().toJSON();

				input.value = file.id;
				name.textContent = file.filename || file.title || '';
				clear.hidden = false;
			} );
		}

		frame.open();
	} );

	clear.addEventListener( 'click', function () {
		input.value = '';
		name.textContent = '';
		clear.hidden = true;
	} );
} )();
