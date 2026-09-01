<?php
/**
 * Page cache - dve funkcie, ktoré potrebuje viac modulov naraz.
 *
 * Plugin nemá vlastnú cache, ale kreslí veci, ktoré sa cachovať nesmú
 * (zoznamy postavené na cookie návštevníka), a mení obsah, o ktorom page
 * cache nevie (bannery a veľkostné tabuľky sú neverejné post typy, takže
 * ich uloženie nič nepurguje).
 *
 * Cache pluginy sa tu volajú cez `function_exists()`, nie cez predpoklad,
 * že sú nainštalované: bez WP Rocketu je oboje tichý no-op a web beží ďalej.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vyňatie práve renderovanej stránky z page cache.
 *
 * `DONOTCACHEPAGE` číta WP Rocket (aj LiteSpeed a W3TC) až pri zápise
 * výstupného buffra, takže definícia počas renderu shortcodu alebo hooku
 * v šablóne je stále dosť skoro.
 *
 * `nocache_headers()` je druhá polovica: konštanta chráni len pred cache
 * vnútri WordPressu, nie pred Cloudflare, Varnishom ani prehliadačom.
 * Funkcia si sama stráži `headers_sent()`, takže volanie uprostred výstupu
 * (shortcode býva až v `the_content`) je bezpečné - vtedy len ticho nič
 * neurobí a stránka je vyňatá aspoň z page cache.
 *
 * @return void
 */
function px_shop_core_no_page_cache() {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}

	nocache_headers();
}

/**
 * Objedná vyprázdnenie page cache celej domény na konci requestu.
 *
 * Jemnejší purge nemá zmysel: banner ani veľkostná tabuľka nemajú vlastnú
 * URL a objaviť sa môžu na úvodke, v kategórii aj na detaile produktu.
 *
 * Purge sa odkladá na `shutdown` a spraví sa raz - hromadný import alebo
 * WP-CLI slučka cez sto položiek nesmie znamenať sto mazaní adresára
 * s cache, a purge na začiatku dlhého behu by cache stihol znova naplniť
 * ešte pred poslednou zmenou.
 *
 * @return void
 */
function px_shop_core_purge_page_cache() {
	static $queued = false;

	if ( $queued ) {
		return;
	}

	$queued = true;

	add_action( 'shutdown', 'px_shop_core_do_purge_page_cache', 100 );
}

/**
 * Samotný purge. Volá sa cez px_shop_core_purge_page_cache(), priamo len
 * z WP-CLI alebo z testu.
 *
 * @return void
 */
function px_shop_core_do_purge_page_cache() {
	// WP Rocket: `rocket_clean_domain()` zmaže cache celej domény vrátane
	// jazykových mutácií. Used CSS sa zámerne nemaže - regeneruje sa pre
	// celý web a text bannera oň nezavadí.
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}

	/**
	 * Miesto pre ostatné cache webu (LiteSpeed, Varnish, Cloudflare).
	 *
	 * Plugin sám pozná len WP Rocket; čokoľvek ďalšie si web dovesí sem
	 * v site plugine namiesto vlastného sledovania našich post typov.
	 */
	do_action( 'px_shop_core_purge_page_cache' );
}
