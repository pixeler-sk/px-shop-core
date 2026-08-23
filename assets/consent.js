/**
 * Cookie consent - the browser side of the module.
 *
 * Everything the visitor decides happens here: the banner is shown only when
 * there is no valid answer, the answer goes into the px_consent cookie, and
 * the page reacts to it - blocked scripts are turned into real ones, blocked
 * embeds are put back, cookies of a refused service are deleted and the
 * px:consent event tells everyone else (Consent Mode, the theme, GTM).
 *
 * No dependencies: the banner has to work on a page where jQuery is not
 * loaded, and it must not wait for anything - it is the first thing a
 * visitor sees.
 *
 * @package PxShopCore
 */
(function () {
	'use strict';

	var cfg = window.pxConsentConfig || {};

	// The config is printed by PHP, but a site plugin can rewrite it and a
	// stale cache can serve an older shape - a wrong type must not turn into
	// "everything allowed", so each value is taken only when it is what it
	// claims to be.
	var COOKIE = typeof cfg.cookie === 'string' && cfg.cookie ? cfg.cookie : 'px_consent';
	var VERSION = String(cfg.version || '1');
	var DAYS = parseInt(cfg.days, 10) || 182;
	var LOCKED = asList(cfg.locked);
	var CATEGORIES = asList(cfg.categories);
	var SERVICES = asObject(cfg.services);
	var CLEAR = asObject(cfg.clear);

	var banner = null;
	var modal = null;
	var dialog = null;
	var opener = null;
	var inerted = [];

	function has(object, key) {
		return Object.prototype.hasOwnProperty.call(object, key);
	}

	function isObject(value) {
		return !!value && 'object' === typeof value && !Array.isArray(value);
	}

	function asList(value) {
		return Array.isArray(value) ? value : [];
	}

	function asObject(value) {
		return isObject(value) ? value : {};
	}

	/**
	 * Keeps the boolean entries and drops everything else.
	 *
	 * The cookie is untrusted input - anything that is not a plain true/false
	 * is thrown away, and a category that is thrown away counts as denied.
	 */
	function booleans(value) {
		var out = {};

		if (!isObject(value)) {
			return out;
		}

		Object.keys(value).forEach(function (key) {
			if ('boolean' === typeof value[key]) {
				out[key] = value[key];
			}
		});

		return out;
	}

	/* ------------------------------- Storage ------------------------------ */

	/**
	 * Every value of a cookie of this name.
	 *
	 * There can be more than one: a cookie set on `.example.com` and another
	 * on `www.example.com` both arrive, and the browser does not say which is
	 * which. They are all read and the strictest one wins.
	 */
	function readCookies(name) {
		var parts = document.cookie ? document.cookie.split(';') : [];
		var values = [];

		for (var i = 0; i < parts.length; i++) {
			var pair = parts[i].trim();

			if (pair.indexOf(name + '=') === 0) {
				values.push(pair.slice(name.length + 1));
			}
		}

		return values;
	}

	/**
	 * One cookie value turned into an answer, or null when it is not one.
	 *
	 * An answer given under an older version of the policy is not an answer
	 * to today's question and has to be asked again.
	 */
	function parseState(raw) {
		var data;

		try {
			data = JSON.parse(decodeURIComponent(raw));
		} catch (e) {
			return null;
		}

		if (!isObject(data) || String(data.v) !== VERSION || !isObject(data.c)) {
			return null;
		}

		return {
			v: VERSION,
			t: 'number' === typeof data.t ? data.t : 0,
			id: 'string' === typeof data.id ? data.id : '',
			c: booleans(data.c),
			s: booleans(data.s)
		};
	}

	/**
	 * Two answers become the stricter of the two: allowed only where both
	 * say so. A permission missing from one of them counts as denied.
	 */
	function strictest(a, b) {
		var out = {
			v: VERSION,
			t: Math.max(a.t, b.t),
			id: a.id || b.id,
			c: {},
			s: {}
		};

		['c', 's'].forEach(function (field) {
			Object.keys(a[field]).concat(Object.keys(b[field])).forEach(function (key) {
				out[field][key] = !!(a[field][key] && b[field][key]);
			});
		});

		return out;
	}

	/**
	 * The stored answer, or null when there is none.
	 */
	function readState() {
		var states = [];

		readCookies(COOKIE).forEach(function (raw) {
			var state = parseState(raw);

			if (state) {
				states.push(state);
			}
		});

		if (!states.length) {
			return null;
		}

		return states.reduce(strictest);
	}

	function writeState(state) {
		var value = COOKIE + '=' + encodeURIComponent(JSON.stringify(state));

		value += '; path=/; max-age=' + DAYS * 86400 + '; samesite=lax';

		if (location.protocol === 'https:') {
			value += '; secure';
		}

		document.cookie = value;
	}

	function uuid() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID();
		}

		return 'px-' + Date.now().toString(16) + '-' + Math.random().toString(16).slice(2, 10);
	}

	function makeState(categories, services, previous) {
		return {
			v: VERSION,
			t: Math.floor(Date.now() / 1000),
			// The id stays with the visitor across changes of mind, so one
			// person is one record - without anything identifying them.
			id: (previous && previous.id) || uuid(),
			c: categories,
			s: services
		};
	}

	/**
	 * Is this category / service allowed by the stored answer?
	 *
	 * A per-service entry wins over its category in both directions: that is
	 * how "allow this one video" works without opening all of marketing.
	 */
	function allowed(state, category, service) {
		if (!state) {
			return false;
		}

		if (service && state.s && has(state.s, service)) {
			return !!state.s[service];
		}

		if (!category) {
			return false;
		}

		if (LOCKED.indexOf(category) !== -1) {
			return true;
		}

		return !!(state.c && state.c[category]);
	}

	function grantedCategories(state) {
		var granted = [];

		CATEGORIES.forEach(function (category) {
			if (allowed(state, category, '')) {
				granted.push(category);
			}
		});

		return granted;
	}

	/* ------------------------------ Decisions ----------------------------- */

	function everything(value) {
		var categories = {};
		var services = {};

		CATEGORIES.forEach(function (category) {
			categories[category] = LOCKED.indexOf(category) !== -1 ? true : value;
		});

		Object.keys(SERVICES).forEach(function (id) {
			services[id] = LOCKED.indexOf(SERVICES[id].category) !== -1 ? true : value;
		});

		return { c: categories, s: services };
	}

	function fromModal() {
		var categories = {};
		var services = {};

		CATEGORIES.forEach(function (category) {
			var input = modal && modal.querySelector('[data-px-consent-category="' + category + '"]');

			categories[category] = LOCKED.indexOf(category) !== -1 ? true : !!(input && input.checked);
		});

		Object.keys(SERVICES).forEach(function (id) {
			var input = modal && modal.querySelector('[data-px-consent-service="' + id + '"]');
			var category = SERVICES[id].category;

			if (LOCKED.indexOf(category) !== -1) {
				services[id] = true;

				return;
			}

			// A service with no switch on the page (a blocked embed inside an
			// article, say) follows its category.
			services[id] = input ? !!input.checked : !!categories[category];
		});

		return { c: categories, s: services };
	}

	/* ------------------------------ Applying ------------------------------ */

	/**
	 * Turns the parked scripts into real ones.
	 *
	 * The node is replaced rather than mutated: a script element that is
	 * already in the document does not run when its type changes.
	 */
	function activateScripts(state) {
		var nodes = document.querySelectorAll('script[type="text/plain"][data-px-consent]');

		Array.prototype.forEach.call(nodes, function (node) {
			var category = node.getAttribute('data-px-consent');
			var service = node.getAttribute('data-px-service') || '';

			if (!allowed(state, category, service)) {
				return;
			}

			var script = document.createElement('script');

			Array.prototype.forEach.call(node.attributes, function (attribute) {
				if (['type', 'data-px-consent', 'data-px-service'].indexOf(attribute.name) === -1) {
					script.setAttribute(attribute.name, attribute.value);
				}
			});

			if (!node.getAttribute('src')) {
				script.text = node.textContent;
			}

			node.parentNode.replaceChild(script, node);
		});
	}

	/**
	 * Puts an allowed embed back in place of its placeholder.
	 */
	function revealEmbeds(state) {
		var nodes = document.querySelectorAll('.px-consent-embed[data-px-consent-embed]');

		Array.prototype.forEach.call(nodes, function (node) {
			var service = node.getAttribute('data-px-consent-embed');
			var category = SERVICES[service] ? SERVICES[service].category : '';

			if (!allowed(state, category, service)) {
				return;
			}

			var template = node.querySelector('template');

			if (!template) {
				return;
			}

			var frame = document.createElement('div');

			frame.className = 'px-consent-embed-frame';
			// The service travels with the frame: a theme that gives the
			// placeholder the 16:9 of a video needs the same ratio on what
			// replaces it, otherwise the page jumps at the moment of consent.
			frame.setAttribute('data-px-consent-embed', service);
			frame.innerHTML = template.innerHTML;

			node.parentNode.replaceChild(frame, node);
		});
	}

	/**
	 * Meta's own consent API, for a pixel that is already running.
	 */
	function metaPixel(state) {
		if (typeof window.fbq !== 'function') {
			return;
		}

		window.fbq('consent', allowed(state, 'marketing', 'meta_pixel') ? 'grant' : 'revoke');
	}

	/**
	 * Deletes the cookies of services that just lost consent.
	 *
	 * Only first-party cookies can be reached from here; third-party ones
	 * (doubleclick.net, facebook.com) are the reason the page is reloaded -
	 * the service then never runs again in this browser.
	 */
	function clearCookies(services) {
		var names = (document.cookie || '').split(';').map(function (pair) {
			return pair.split('=')[0].trim();
		});

		var hosts = [''];
		var parts = location.hostname.split('.');

		for (var i = 0; i < parts.length - 1; i++) {
			hosts.push(parts.slice(i).join('.'));
			hosts.push('.' + parts.slice(i).join('.'));
		}

		// A cookie can only be deleted from the path it was set on, and a
		// script that ran on /kosik/ may well have set it there. The current
		// path and every parent of it are tried alongside the root.
		var paths = ['/'];
		var segments = location.pathname.split('/').filter(Boolean);
		var walked = '';

		for (var s = 0; s < segments.length; s++) {
			walked += '/' + segments[s];
			paths.push(walked, walked + '/');
		}

		services.forEach(function (service) {
			(CLEAR[service] || []).forEach(function (pattern) {
				var escaped = pattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\\\*/g, '.*');
				var test = new RegExp('^' + escaped + '$');

				names.forEach(function (name) {
					if (!name || !test.test(name)) {
						return;
					}

					hosts.forEach(function (host) {
						paths.forEach(function (path) {
							document.cookie = name + '=; path=' + path + '; expires=Thu, 01 Jan 1970 00:00:01 GMT' + (host ? '; domain=' + host : '');
						});
					});
				});
			});
		});
	}

	function revokedServices(previous, next) {
		var list = [];

		if (!previous) {
			return list;
		}

		Object.keys(SERVICES).forEach(function (id) {
			var category = SERVICES[id].category;

			if (allowed(previous, category, id) && !allowed(next, category, id)) {
				list.push(id);
			}
		});

		return list;
	}

	/**
	 * Tells the page what was allowed.
	 *
	 * Without the consent id: the event ends up in dataLayer and from there
	 * in whatever a container sends onwards, and a stable identifier of a
	 * visitor who refused everything is exactly what must not leave the
	 * browser. The id stays in the cookie, where the record of consent will
	 * read it.
	 */
	function dispatch(state) {
		var detail = {
			version: state.v,
			categories: grantedCategories(state),
			services: state.s || {}
		};

		document.dispatchEvent(new CustomEvent('px:consent', { detail: detail }));
	}

	/**
	 * @param {Object} state    The answer to apply.
	 * @param {Object} previous The answer that was in force before, if any.
	 */
	function apply(state, previous) {
		var revoked = revokedServices(previous, state);

		activateScripts(state);
		revealEmbeds(state);
		metaPixel(state);
		dispatch(state);

		if (revoked.length) {
			clearCookies(revoked);

			// A script cannot be unloaded once it runs; only a fresh page view
			// is really free of it.
			if (cfg.reload !== false) {
				location.reload();
			}
		}
	}

	function save(choice) {
		var previous = readState();
		var state = makeState(choice.c, choice.s, previous);

		writeState(state);
		hideBanner();
		closeModal();
		apply(state, previous);
		focusPage();
	}

	/**
	 * Where focus goes once the banner and the modal are gone.
	 *
	 * The button that was pressed no longer exists, and focus left on a
	 * hidden element means the next Tab starts from the top of the document -
	 * a keyboard visitor would have to walk the whole header again.
	 */
	function focusPage() {
		var target = document.querySelector('main, [role="main"], #main, #content, h1');

		if (!target) {
			return;
		}

		if (!target.hasAttribute('tabindex')) {
			target.setAttribute('tabindex', '-1');
		}

		target.focus({ preventScroll: true });
	}

	/* -------------------------------- View -------------------------------- */

	function showBanner() {
		if (!banner) {
			return;
		}

		banner.hidden = false;
		banner.focus();
	}

	function hideBanner() {
		if (banner) {
			banner.hidden = true;
		}
	}

	function focusable(root) {
		return Array.prototype.filter.call(
			root.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'),
			function (node) {
				return node.offsetWidth > 0 || node.offsetHeight > 0 || node === document.activeElement;
			}
		);
	}

	function trap(event) {
		if (!modal || modal.hidden) {
			return;
		}

		if (event.key === 'Escape' || event.key === 'Esc') {
			closeModal();

			return;
		}

		if (event.key !== 'Tab') {
			return;
		}

		var items = focusable(dialog);

		if (!items.length) {
			return;
		}

		var first = items[0];
		var last = items[items.length - 1];

		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	}

	/**
	 * Takes the rest of the page out of the accessibility tree while the
	 * modal is open, so a screen reader cannot wander behind the overlay.
	 * `inert` does it properly; older browsers get aria-hidden, and only on
	 * elements that did not carry it already.
	 */
	function backgroundInert(on) {
		if (!document.body || !modal) {
			return;
		}

		if (!on) {
			inerted.forEach(function (node) {
				if ('inert' in node) {
					node.inert = false;
				} else {
					node.removeAttribute('aria-hidden');
				}
			});

			inerted = [];

			return;
		}

		inerted = [];

		Array.prototype.forEach.call(document.body.children, function (node) {
			if (node === modal || node.contains(modal) || 'SCRIPT' === node.tagName || 'STYLE' === node.tagName) {
				return;
			}

			if ('inert' in node) {
				node.inert = true;
			} else if (node.hasAttribute('aria-hidden')) {
				return;
			} else {
				node.setAttribute('aria-hidden', 'true');
			}

			inerted.push(node);
		});
	}

	function openModal(trigger) {
		if (!modal) {
			return;
		}

		opener = trigger || document.activeElement;

		syncModal(readState());

		modal.hidden = false;
		document.documentElement.classList.add('px-consent-open');
		document.addEventListener('keydown', trap);
		backgroundInert(true);

		var items = focusable(dialog);

		(items.length ? items[0] : dialog).focus();
	}

	function closeModal() {
		if (!modal || modal.hidden) {
			return;
		}

		modal.hidden = true;
		document.documentElement.classList.remove('px-consent-open');
		document.removeEventListener('keydown', trap);

		// Before the focus goes back: an inert element cannot take it.
		backgroundInert(false);

		// Only back to the button that opened this, and only while it is still
		// on the page and visible - after "Save choice" the banner is gone.
		if (opener && document.contains(opener) && opener.offsetParent !== null) {
			opener.focus();
		} else if (banner && !banner.hidden) {
			banner.focus();
		}

		opener = null;
	}

	/**
	 * Puts the stored answer into the switches. Nothing is pre-ticked when
	 * there is no answer yet - a pre-ticked box is not consent (C-673/17).
	 */
	function syncModal(state) {
		if (!modal) {
			return;
		}

		Array.prototype.forEach.call(modal.querySelectorAll('[data-px-consent-category]'), function (input) {
			if (input.disabled) {
				return;
			}

			input.checked = allowed(state, input.getAttribute('data-px-consent-category'), '');
		});

		Array.prototype.forEach.call(modal.querySelectorAll('[data-px-consent-service]'), function (input) {
			if (input.disabled) {
				return;
			}

			var id = input.getAttribute('data-px-consent-service');

			input.checked = allowed(state, input.getAttribute('data-px-consent-service-category'), id);
		});

		CATEGORIES.forEach(syncCategory);
	}

	/**
	 * The category switch says what its services say: all, none, or a mix
	 * (shown as indeterminate).
	 */
	function syncCategory(category) {
		var box = modal.querySelector('[data-px-consent-category="' + category + '"]');
		var services = modal.querySelectorAll('[data-px-consent-service-category="' + category + '"]');

		if (!box || box.disabled || !services.length) {
			return;
		}

		var on = 0;

		Array.prototype.forEach.call(services, function (input) {
			on += input.checked ? 1 : 0;
		});

		box.checked = on > 0;
		box.indeterminate = on > 0 && on < services.length;
	}

	function toggleExpand(button) {
		var target = document.getElementById(button.getAttribute('aria-controls'));

		if (!target) {
			return;
		}

		var open = button.getAttribute('aria-expanded') === 'true';

		button.setAttribute('aria-expanded', open ? 'false' : 'true');
		target.hidden = open;
	}

	/* ------------------------------- Wiring ------------------------------- */

	function onClick(event) {
		var target = event.target;

		if (!target || typeof target.closest !== 'function') {
			return;
		}

		var accept = target.closest('[data-px-consent-accept]');
		var reject = target.closest('[data-px-consent-reject]');
		var settings = target.closest('[data-px-consent-settings]');
		var saveButton = target.closest('[data-px-consent-save]');
		var close = target.closest('[data-px-consent-close]');
		var expand = target.closest('[data-px-consent-expand]');
		var allow = target.closest('[data-px-consent-allow]');

		if (accept) {
			save(everything(true));
		} else if (reject) {
			save(everything(false));
		} else if (saveButton) {
			save(fromModal());
		} else if (settings) {
			event.preventDefault();
			openModal(settings);
		} else if (close) {
			closeModal();
		} else if (expand) {
			toggleExpand(expand);
		} else if (allow) {
			allowService(allow.getAttribute('data-px-consent-allow'));
		}
	}

	/**
	 * "Show this video" - consent for one service, nothing else.
	 */
	function allowService(id) {
		if (!id) {
			return;
		}

		var previous = readState();
		var categories = {};
		var services = {};

		CATEGORIES.forEach(function (category) {
			categories[category] = LOCKED.indexOf(category) !== -1 ? true : allowed(previous, category, '');
		});

		Object.keys(SERVICES).forEach(function (service) {
			services[service] = allowed(previous, SERVICES[service].category, service);
		});

		services[id] = true;

		var state = makeState(categories, services, previous);

		writeState(state);
		hideBanner();
		apply(state, previous);

		// The button that was just pressed is gone with the placeholder; focus
		// moves to what took its place, so the visitor lands on the content
		// they asked for instead of at the top of the document.
		var frame = document.querySelector('.px-consent-embed-frame[data-px-consent-embed="' + id + '"]');

		if (frame) {
			if (!frame.hasAttribute('tabindex')) {
				frame.setAttribute('tabindex', '-1');
			}

			frame.focus({ preventScroll: true });
		}
	}

	function onChange(event) {
		var input = event.target;

		if (!modal || !input || !input.hasAttribute) {
			return;
		}

		if (input.hasAttribute('data-px-consent-category')) {
			var category = input.getAttribute('data-px-consent-category');

			Array.prototype.forEach.call(modal.querySelectorAll('[data-px-consent-service-category="' + category + '"]'), function (service) {
				if (!service.disabled) {
					service.checked = input.checked;
				}
			});

			input.indeterminate = false;
		} else if (input.hasAttribute('data-px-consent-service-category')) {
			syncCategory(input.getAttribute('data-px-consent-service-category'));
		}
	}

	function start() {
		banner = document.getElementById('px-consent');
		modal = document.getElementById('px-consent-modal');
		dialog = modal ? modal.querySelector('.px-consent-modal__dialog') : null;

		document.addEventListener('click', onClick);
		document.addEventListener('change', onChange);

		var state = readState();

		if (state) {
			// A returning visitor: no banner, but everything they allowed has
			// to be switched on and the signals sent.
			apply(state, null);

			return;
		}

		showBanner();
	}

	// The banner is printed before the scripts, but a theme that moves the
	// script into <head> should not break it.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}

	/**
	 * Small API for themes: px-shop-theme opens the settings from the footer
	 * link, a site plugin can ask what was allowed.
	 */
	window.pxConsent = {
		open: function () {
			openModal(null);
		},
		state: readState,
		allowed: function (category, service) {
			return allowed(readState(), category, service || '');
		},
		acceptAll: function () {
			save(everything(true));
		},
		rejectAll: function () {
			save(everything(false));
		}
	};
})();
