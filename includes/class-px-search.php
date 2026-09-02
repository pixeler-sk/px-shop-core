<?php
/**
 * Live product search - REST endpoint consumed by the theme's JS.
 *
 * GET /wp-json/pixeler/v1/search?term=...
 *
 * Route kept under the pixeler/v1 namespace for backwards compatibility
 * with themes that registered it themselves before this plugin existed.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Search {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );

		// Standard search results page (?s=...&post_type=product). Without this
		// the suggester would find a product by its code and the "show all
		// results" link behind it would land on an empty page.
		add_filter( 'posts_search', array( __CLASS__, 'widen_search' ), 10, 2 );
	}

	public static function register_route() {
		register_rest_route( 'pixeler/v1', '/search', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'term' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					// Nothing a person types into a search box is longer than
					// this. The endpoint is public and uncapped, and the phrase
					// ends up in a cache key and in LIKE patterns - both are
					// cheaper with a ceiling on them.
					'validate_callback' => array( __CLASS__, 'validate_term' ),
				),
			),
		) );
	}

	/** Ako dlho drží odpoveď šepkávača v transiente aj v prehliadači. */
	const CACHE_TTL = 60;

	/** Koľko zhôd na kód najviac pritiahne stránka výsledkov. */
	const CODE_LIMIT = 50;

	/** Najdlhšia prijatá fráza - dlhšie do vyhľadávacieho poľa nikto nepíše. */
	const MAX_TERM = 64;

	/**
	 * Overenie dĺžky frázy pre REST.
	 *
	 * @param mixed $value Hodnota z requestu.
	 * @return bool|WP_Error
	 */
	public static function validate_term( $value ) {
		if ( is_string( $value ) && mb_strlen( $value ) <= self::MAX_TERM ) {
			return true;
		}

		return new WP_Error(
			'rest_invalid_param',
			sprintf(
				/* translators: %d: maximum number of characters. */
				__( 'The search phrase must be a string of at most %d characters.', 'px-shop-core' ),
				self::MAX_TERM
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Fráza zúžená na to, čo naozaj rozhoduje o výsledku.
	 *
	 * „Prilba", „prilba" a „prilba  " sú pre vyhľadávanie tá istá otázka, takže
	 * si nezaslúžia tri záznamy v cache ani tri kolá dotazov.
	 *
	 * @param string $term Fráza tak, ako prišla.
	 * @return string
	 */
	private static function normalise( $term ) {
		$term = preg_replace( '/\s+/u', ' ', (string) $term );

		return trim( function_exists( 'mb_strtolower' ) ? mb_strtolower( $term, 'UTF-8' ) : strtolower( $term ) );
	}

	/**
	 * Kľúč transientu.
	 *
	 * Odpoveď závisí od frázy a od jazyka - preklady majú vlastné produkty aj
	 * názvy kategórií, takže spoločný kľúč by servíroval české výsledky na
	 * slovenskej mutácii. Do kľúča patrí aj všetko, od čoho závisí `price_html`:
	 * mena, nastavenie „zobrazovať ceny s daňou / bez dane" a oslobodenie od DPH
	 * (to prepína aj tento plugin v module firemných údajov). Bez toho by prvý
	 * návštevník s iným daňovým kontextom otrávil ceny všetkým ostatným.
	 *
	 * @param string $term Normalizovaná fráza.
	 * @return string
	 */
	private static function cache_key( $term ) {
		$lang = defined( 'ICL_LANGUAGE_CODE' ) ? ICL_LANGUAGE_CODE : determine_locale();

		$parts = array(
			$term,
			$lang,
			(string) get_option( 'woocommerce_tax_display_shop' ),
			function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			( function_exists( 'WC' ) && WC()->customer ) ? (string) (int) WC()->customer->get_is_vat_exempt() : '',
		);

		return 'px_search_' . md5( implode( '|', $parts ) );
	}

	/**
	 * Smie sa táto odpoveď vôbec cachovať?
	 *
	 * Prihlásený zákazník môže mať vlastnú cenovú hladinu alebo oslobodenie od
	 * DPH, ktoré sa do kľúča nezmestí - pre neho sa nečíta ani nezapisuje.
	 * A zápis do transientu bez perzistentnej object cache znamená dva riadky
	 * v `wp_options` na každú neznámu frázu; verejný endpoint bez rate limitu
	 * by tak crawler nafúkol na státisíce riadkov. S object cache je to len
	 * záznam v pamäti, ktorý sám vyprší.
	 *
	 * Na webe bez object cache tak serverová cache nie je vôbec - zostáva
	 * hlavička `Cache-Control: public, max-age=60`, ktorá pokryje opakované
	 * písmená v prehliadači aj na CDN. Kto chce cache aj na serveri, zapne
	 * Redis; nafukovanie wp_options je horšia cena než pár dotazov navyše.
	 *
	 * @return bool
	 */
	private static function cacheable() {
		return ! is_user_logged_in() && wp_using_ext_object_cache();
	}

	/**
	 * Odkaz na stránku výsledkov s frázou tak, ako ju návštevník napísal.
	 *
	 * @param string $raw Pôvodná fráza.
	 * @return string
	 */
	private static function view_all_url( $raw ) {
		return esc_url_raw( add_query_arg(
			array(
				's'         => $raw,
				'post_type' => 'product',
			),
			home_url( '/' )
		) );
	}

	/** Odpoveď so 60 s cache tam, kde je verejná (viď cache_control()). */
	private static function respond( $data ) {
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', self::cache_control() );

		// To isté, čím sa líši `cache_key()`, musí vidieť aj zdieľaná proxy.
		// Jazyk beží spravidla cez URL (tam sa cache delí sama), ale meno
		// jazyka aj meny môže sedieť v cookie a daňový kontext hosťa
		// v session WooCommerce - `Cookie` teda pokrýva to podstatné.
		// Cenou je, že návštevník s akoukoľvek cookie sa na CDN netrafí do
		// spoločného záznamu; podať mu cudziu cenu je horšie.
		$response->header( 'Vary', 'Accept-Language, Cookie' );

		return $response;
	}

	/**
	 * Hlavička Cache-Control podľa toho, komu odpoveď patrí.
	 *
	 * Odpoveď nesie cenu ako hotové HTML, a tá nie je pre každého rovnaká:
	 * prihlásený zákazník môže mať vlastnú cenovú hladinu, hosť v košíku
	 * môže mať oslobodenie od DPH. `cacheable()` ich vylučuje zo serverovej
	 * cache, ale hlavička je to, čo počuje Cloudflare alebo iná zdieľaná
	 * proxy - s `public` by jej stačilo raz uložiť odpoveď prihláseného
	 * a servírovať jeho ceny anonymným návštevníkom. Takáto odpoveď preto
	 * nesmie skončiť nikde inde než u toho, kto si ju vypýtal.
	 *
	 * @return string
	 */
	private static function cache_control() {
		$vat_exempt = function_exists( 'WC' ) && WC()->customer && WC()->customer->get_is_vat_exempt();

		if ( is_user_logged_in() || $vat_exempt ) {
			return 'private, no-store, max-age=0';
		}

		return 'public, max-age=' . self::CACHE_TTL;
	}

	public static function handle( $request ) {
		// Raw phrase je to, čo návštevník napísal - ostáva len pre odkaz
		// „Zobraziť všetky výsledky", aby sa mu na stránke výsledkov vrátilo
		// jeho vlastné písanie. Všetko ostatné beží nad normalizovanou frázou.
		$raw  = trim( (string) $request->get_param( 'term' ) );
		$term = self::normalise( $raw );

		if ( mb_strlen( $raw ) > self::MAX_TERM || mb_strlen( $term ) < 2 ) {
			return self::respond( array(
				'items' => array(),
				'total' => 0,
			) );
		}

		// Endpoint je verejný a bez rate limitu - kým sa fráza opakuje (a pri
		// šepkávači sa opakuje: každé písmeno posiela request a návštevníci
		// hľadajú to isté), nemá zmysel púšťať WP_Query a get_terms() znova.
		// Cachuje sa stav pred filtrom px_shop_core_search_response, aby si
		// téma vedela dopísať vlastné (jazykovo závislé) popisky aj nad
		// cachovanými dátami. viewAll sa do cache nedáva - závisí od pôvodného
		// písania frázy, nie od výsledkov.
		$cacheable = self::cacheable();
		$cache_key = self::cache_key( $term );
		$cached    = $cacheable ? get_transient( $cache_key ) : false;

		if ( is_array( $cached ) ) {
			$cached['viewAll'] = self::view_all_url( $raw );

			return self::respond( apply_filters( 'px_shop_core_search_response', $cached, $term ) );
		}

		$limit = (int) apply_filters( 'px_shop_core_search_limit', 8 );

		// Same visibility rules the shop archive runs on - otherwise the
		// suggester offers products the search page then drops, and its
		// "show all N results" promises a number the page cannot deliver.
		$hidden = array( 'exclude-from-search' );

		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			$hidden[] = 'outofstock';
		}

		// Codes first: a customer pasting a part number and a shop assistant
		// scanning an EAN both want that one product, not whatever the phrase
		// happens to resemble. Resolved from the phrase as typed - a site plugin
		// may rewrite 's' below, the code the visitor pasted does not change.
		// The IDs also ride along in the query args, where widen_search() adds
		// them to the WHERE - that keeps found_posts, and with it the "show all
		// N results" promise, in step with the results page.
		$code_ids = self::product_ids_matching_code( $term, $limit );

		$visibility = array(
			array(
				'taxonomy' => 'product_visibility',
				'field'    => 'name',
				'terms'    => $hidden,
				'operator' => 'NOT IN',
			),
		);

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			's'              => $term,
			'posts_per_page' => $limit,
			'px_code_ids'    => $code_ids,
			'tax_query'      => $visibility,
		);

		/**
		 * Filter the WP_Query args behind the suggester. Lets a site plugin
		 * translate parts of the phrase into attribute filters - a shop selling
		 * sized goods wants "mattress 90x200" to become a pa_size tax_query with
		 * "mattress" left to the fulltext, which a plain LIKE on post_title can
		 * never do. The site plugin owns those attribute names; this plugin does
		 * not guess them.
		 *
		 * A site that shortens $args['s'] should leave it trimmed - the category
		 * lookup below reuses it, so the tokens it consumed do not leak into the
		 * category name match either.
		 *
		 * @param array  $args WP_Query arguments.
		 * @param string $term Raw search phrase as typed.
		 */
		$args = apply_filters( 'px_shop_core_search_query_args', $args, $term );

		// Ak si web frázu zobral celú na atribútový filter, nezostalo nič, čo by
		// WordPress hľadal - widen_search() sa potom nemá kam pripojiť a total by
		// zhody na kód nezapočítal. Radšej ich zahodiť aj z položiek, než ukázať
		// v šepkávači produkt, ktorý sa do počtu ani na stránku výsledkov
		// nedostane.
		if ( '' === trim( (string) ( isset( $args['s'] ) ? $args['s'] : '' ) ) ) {
			$code_ids = array();
		}

		$query = new WP_Query( $args );

		$posts = $query->posts;

		if ( ! empty( $code_ids ) ) {
			// The query above is ordered by WordPress' title relevance, where a
			// code match with none of the words in its title lands far down -
			// often past the eight rows the suggester shows at all. Reordering
			// what came back would not help; the matches have to be fetched on
			// their own. It is a primary key lookup, and it only runs when a
			// code actually matched.
			//
			// This query deliberately runs outside px_shop_core_search_query_args:
			// visibility comes from the rules built above, so a site plugin
			// turning part of the phrase into an attribute filter cannot knock
			// out the very product whose code was pasted. What that filter is
			// for - reading the phrase - has already happened; the codes were
			// resolved before it ran.
			$code_query = new WP_Query( array(
				'post_type'           => 'product',
				'post_status'         => 'publish',
				'post__in'            => $code_ids,
				'orderby'             => 'post__in',
				'posts_per_page'      => $limit,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'tax_query'           => $visibility,
			) );

			$seen  = array();
			$posts = array();

			foreach ( array_merge( $code_query->posts, $query->posts ) as $result_post ) {
				$post_id = is_object( $result_post ) ? (int) $result_post->ID : (int) $result_post;

				if ( isset( $seen[ $post_id ] ) ) {
					continue;
				}

				$seen[ $post_id ] = true;
				$posts[]          = $result_post;
			}

			$posts = array_slice( $posts, 0, $limit );
		}

		$items = array();
		foreach ( $posts as $result_post ) {
			$product = wc_get_product( $result_post );
			if ( ! $product ) {
				continue;
			}

			$image_id = $product->get_image_id();

			$items[] = array(
				'type'       => 'product',
				'id'         => $product->get_id(),
				'title'      => html_entity_decode( wp_strip_all_tags( $product->get_name() ), ENT_QUOTES, 'UTF-8' ),
				'url'        => get_permalink( $result_post ),
				'price'      => $product->get_price_html(),
				// The unit price belongs wherever the selling price is shown
				// (108/2024 § 6) - the suggestion list is an offer like any other.
				// Empty without the module, without data on the product, or when
				// prices are hidden from the visitor (px_unit_price_visible).
				'unit_price' => class_exists( 'PX_Unit_Price' ) ? PX_Unit_Price::get_html( $product ) : '',
				// Only ever false on shops that keep sold-out products listed -
				// the client marks those rows so they do not read as an offer.
				'in_stock'   => $product->is_in_stock(),
				'image'      => $image_id
					? wp_get_attachment_image_url( $image_id, 'woocommerce_gallery_thumbnail' )
					: wc_placeholder_img_src( 'woocommerce_gallery_thumbnail' ),
			);
		}

		// Matching product categories - returned separately so the client can set
		// them apart from products (own section + icon), and so the product
		// "total"/"viewAll" paging stays about products only.
		$categories = array();
		$cat_limit  = (int) apply_filters( 'px_shop_core_search_cat_limit', 4 );

		// Match on whatever the query args ended up searching for, not on the
		// raw phrase - once a site plugin has pulled "90x200" out into a
		// tax_query, the leftover "mattress" is what can still name a category.
		$cat_search = isset( $args['s'] ) ? trim( (string) $args['s'] ) : $term;

		// Fetched with room to spare: WooCommerce swaps in its own term counts
		// (visible products, children included) after the query runs, so
		// 'hide_empty' does not catch every empty branch - those are dropped
		// below and the limit has to survive it.
		// An empty needle would match every category, so skip the lookup.
		$cat_terms = ( '' === $cat_search ) ? array() : get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'number'     => $cat_limit * 3,
			'name__like' => $cat_search,
		) );
		if ( ! is_wp_error( $cat_terms ) ) {
			foreach ( $cat_terms as $cat_term ) {
				if ( count( $categories ) >= $cat_limit ) {
					break;
				}

				// A suggestion leading to an empty archive is a dead end.
				if ( (int) $cat_term->count < 1 ) {
					continue;
				}

				$link = get_term_link( $cat_term );
				if ( is_wp_error( $link ) ) {
					continue;
				}
				$categories[] = array(
					'type'  => 'category',
					'id'    => $cat_term->term_id,
					'title' => html_entity_decode( $cat_term->name, ENT_QUOTES, 'UTF-8' ),
					'url'   => $link,
					'count' => (int) $cat_term->count,
				);
			}
		}

		$response = array(
			'categories' => $categories,
			'items'      => $items,
			'total'      => (int) $query->found_posts,
		);

		if ( $cacheable ) {
			set_transient( $cache_key, $response, self::CACHE_TTL );
		}

		$response['viewAll'] = self::view_all_url( $raw );

		/**
		 * Filter the search response before it is returned. Lets the active theme
		 * enrich results (e.g. add a localised category product-count label)
		 * without this plugin taking on the theme's text domain.
		 *
		 * Runs on cached hits too - keep it cheap and free of per-user data.
		 *
		 * @param array  $response
		 * @param string $term
		 */
		return self::respond( apply_filters( 'px_shop_core_search_response', $response, $term ) );
	}

	/**
	 * Widens a product search with the products whose SKU or EAN matches.
	 *
	 * WordPress searches post_title, post_excerpt and post_content only, so a
	 * part number or a scanned barcode returns nothing at all - the one case
	 * where the visitor knows exactly what they want.
	 *
	 * @param string   $search WHERE fragment built from the search terms.
	 * @param WP_Query $query  Query being built.
	 * @return string
	 */
	public static function widen_search( $search, $query ) {
		global $wpdb;

		// No search terms means no clause to widen - an empty ?s= must keep
		// returning what it returns today.
		if ( '' === trim( (string) $search ) ) {
			return $search;
		}

		$ids = self::search_code_ids( $query );

		if ( empty( $ids ) ) {
			return $search;
		}

		// WordPress hands over " AND (<terms>) " and, for logged out visitors,
		// " AND (post_password = '') " on top. Only the terms may get the OR -
		// a matching code must never unlock a password protected post.
		$password = '/\s*AND\s*\(\s*' . preg_quote( $wpdb->posts, '/' ) . '\.post_password\s*=\s*\'\'\s*\)\s*$/i';
		$tail     = '';

		if ( preg_match( $password, $search, $matched ) ) {
			$tail   = $matched[0];
			$search = (string) preg_replace( $password, '', $search );
		}

		// Any mention of post_password left in what we are about to OR means we
		// did not understand the fragment - another filter reformatted it, or
		// core changed it. Widening it anyway would drop the condition, so the
		// query is left exactly as it came.
		if ( false !== stripos( $search, 'post_password' ) ) {
			return $search . $tail;
		}

		// Same rule for the shape itself: either it is the " AND (<terms>) "
		// core builds, or this filter keeps its hands off. No best effort - a
		// half understood WHERE is how a search starts returning drafts.
		if ( ! preg_match( '/^\s*AND\s*\((.*)\)\s*$/s', $search, $parsed ) ) {
			return $search . $tail;
		}

		$terms = trim( $parsed[1] );

		if ( '' === $terms ) {
			return $search . $tail;
		}

		// IDs went through absint(), and post_type/post_status stay in the
		// query's own WHERE - a variation parent that is not a published
		// product drops out there.
		$in = implode( ',', $ids );

		return " AND ( ({$terms}) OR {$wpdb->posts}.ID IN ({$in}) )" . $tail;
	}

	/**
	 * Which code matches, if any, belong to this query.
	 *
	 * @param WP_Query $query Query being built.
	 * @return int[]
	 */
	private static function search_code_ids( $query ) {
		if ( ! $query instanceof WP_Query ) {
			return array();
		}

		// The suggester resolved its own IDs from the phrase as typed, before a
		// site plugin got the chance to rewrite 's'.
		$own = $query->get( 'px_code_ids' );

		if ( is_array( $own ) ) {
			return array_values( array_filter( array_map( 'absint', $own ) ) );
		}

		// Admin has WooCommerce's own SKU search; widgets, related products and
		// every other secondary query stay untouched.
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return array();
		}

		$post_types = (array) $query->get( 'post_type' );

		if ( ! in_array( 'product', $post_types, true ) && ! in_array( 'any', $post_types, true ) ) {
			return array();
		}

		$term = trim( (string) $query->get( 's' ) );

		if ( '' === $term ) {
			return array();
		}

		/**
		 * How many code matches the results page pulls in at most. They are
		 * added to the fulltext results, so the ceiling keeps a short SKU prefix
		 * from flooding the page.
		 *
		 * @param int $limit
		 */
		$limit = (int) apply_filters( 'px_shop_core_search_code_limit', self::CODE_LIMIT );

		return self::product_ids_matching_code( $term, $limit );
	}

	/**
	 * Products whose SKU or EAN/GTIN matches the phrase.
	 *
	 * Variations are mapped onto their parent - a variation has no page of its
	 * own, and its SKU is what tends to be printed on the box.
	 *
	 * Data comes from wc_product_meta_lookup: one row per product, sku indexed,
	 * and the same source WooCommerce's own admin search runs on. That table is
	 * a cache WooCommerce rebuilds when a product is saved, so an importer
	 * writing _sku straight into postmeta can leave it behind - hence the
	 * postmeta fallback, kept to code shaped phrases so ordinary words never pay
	 * for the unindexed scan.
	 *
	 * @param string $term  Phrase as typed.
	 * @param int    $limit Maximum number of products; falls back to CODE_LIMIT.
	 * @return int[] Product IDs, exact matches first.
	 */
	public static function product_ids_matching_code( $term, $limit = 0 ) {
		static $memo = array();

		$term  = trim( (string) $term );
		$limit = $limit > 0 ? (int) $limit : self::CODE_LIMIT;

		if ( mb_strlen( $term ) < 2 ) {
			return array();
		}

		$memo_key = $term . '|' . $limit;

		if ( isset( $memo[ $memo_key ] ) ) {
			return $memo[ $memo_key ];
		}

		$ids = self::code_ids_from_lookup( $term, $limit );

		// Postmeta is authoritative but unindexed - a scan of every _sku and
		// _global_unique_id row on the site. That is a diagnostic, not something
		// a public search box may trigger: a crawler pasting made up codes would
		// run it on every request. Under WP-CLI it stays available, so a stale
		// lookup table can be proven from the shell.
		if ( empty( $ids ) && defined( 'WP_CLI' ) && WP_CLI && self::looks_like_code( $term ) ) {
			$ids = self::code_ids_from_meta( $term, $limit );
		}

		/**
		 * Filter the products matched by code. A shop keeping its part numbers
		 * somewhere else (own meta key, supplier table) plugs it in here instead
		 * of reimplementing the search.
		 *
		 * @param int[]  $ids   Product IDs, exact matches first.
		 * @param string $term  Phrase as typed.
		 * @param int    $limit Maximum number of products.
		 */
		$ids = apply_filters( 'px_shop_core_search_code_ids', $ids, $term, $limit );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );

		$memo[ $memo_key ] = $ids;

		return $ids;
	}

	/**
	 * Code matches from the WooCommerce product lookup table.
	 *
	 * SKU and EAN are asked for separately on purpose. Only `sku` carries an
	 * index; putting `global_unique_id` in the same OR would cost the optimiser
	 * that index and turn every numeric phrase - including plain numeric SKUs -
	 * into a full scan of the lookup table. So the indexed question is asked
	 * first, and the unindexed one only when it found nothing.
	 *
	 * @param string $term  Phrase as typed.
	 * @param int    $limit Maximum number of products.
	 * @return int[]
	 */
	private static function code_ids_from_lookup( $term, $limit ) {
		global $wpdb;

		$match  = array( 'l.sku = %s' );
		$params = array( $term, $term );

		// SKU prefixes do carry meaning - a variation SKU extends the parent
		// one - but only from four characters up, so "RAM" does not drag in
		// half the catalogue.
		if ( mb_strlen( $term ) >= 4 ) {
			$match[]  = 'l.sku LIKE %s';
			$params[] = $wpdb->esc_like( $term ) . '%';
		}

		$ids = self::lookup_ids( 'l.sku = %s', $match, $params, $limit );

		if ( ! empty( $ids ) ) {
			return $ids;
		}

		if ( ! self::looks_like_gtin( $term ) || ! self::has_gtin_column() ) {
			return array();
		}

		// GTINs have fixed lengths, so only an exact hit means anything.
		return self::lookup_ids( 'l.global_unique_id = %s', array( 'l.global_unique_id = %s' ), array( $term, $term ), $limit );
	}

	/**
	 * Runs one lookup-table question and maps the rows onto product IDs.
	 *
	 * @param string   $exact_sql Condition marking an exact hit; one placeholder.
	 * @param string[] $match     WHERE alternatives, ORed together.
	 * @param array    $params    Values for $exact_sql followed by $match.
	 * @param int      $limit     Maximum number of products.
	 * @return int[]
	 */
	private static function lookup_ids( $exact_sql, $match, $params, $limit ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_product_meta_lookup';

		// Alias deliberately not called product_id: MySQL resolves GROUP BY
		// against the FROM columns first, so it would group by the lookup row
		// (the variation) instead of the parent it was mapped onto.
		$sql = "SELECT CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE p.ID END AS px_product_id,
				MAX( CASE WHEN {$exact_sql} THEN 1 ELSE 0 END ) AS px_exact,
				MIN( CHAR_LENGTH( l.sku ) ) AS px_len
			FROM {$table} l
			INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
			WHERE ( " . implode( ' OR ', $match ) . " )
				AND p.post_status = 'publish'
				AND p.post_type IN ( 'product', 'product_variation' )
			GROUP BY px_product_id
			ORDER BY px_exact DESC, px_len ASC, px_product_id ASC
			LIMIT %d";

		$params[] = (int) $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every value is a placeholder, only table names are interpolated.
		return self::rows_to_ids( $wpdb->get_results( $wpdb->prepare( $sql, $params ) ), $limit );
	}

	/**
	 * Code matches straight from postmeta - authoritative, but unindexed.
	 *
	 * Reachable from WP-CLI only; see product_ids_matching_code().
	 *
	 * @param string $term  Phrase as typed.
	 * @param int    $limit Maximum number of products.
	 * @return int[]
	 */
	private static function code_ids_from_meta( $term, $limit ) {
		global $wpdb;

		$match  = array( 'm.meta_value = %s' );
		$params = array( $term, $term );

		// Prefix matching belongs to SKU only - the same rule the lookup branch
		// follows, so the shell does not answer differently from the front end.
		if ( mb_strlen( $term ) >= 4 ) {
			$match[]  = "( m.meta_key = '_sku' AND m.meta_value LIKE %s )";
			$params[] = $wpdb->esc_like( $term ) . '%';
		}

		$sql = "SELECT CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE p.ID END AS px_product_id,
				MAX( CASE WHEN m.meta_value = %s THEN 1 ELSE 0 END ) AS px_exact,
				MIN( CHAR_LENGTH( m.meta_value ) ) AS px_len
			FROM {$wpdb->postmeta} m
			INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			WHERE m.meta_key IN ( '_sku', '_global_unique_id' )
				AND ( " . implode( ' OR ', $match ) . " )
				AND p.post_status = 'publish'
				AND p.post_type IN ( 'product', 'product_variation' )
			GROUP BY px_product_id
			ORDER BY px_exact DESC, px_len ASC, px_product_id ASC
			LIMIT %d";

		$params[] = (int) $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every value is a placeholder, only table names are interpolated.
		return self::rows_to_ids( $wpdb->get_results( $wpdb->prepare( $sql, $params ) ), $limit );
	}

	/**
	 * Turns matched rows into product IDs.
	 *
	 * An exact code is an answer, not a hint: once a product actually carries
	 * the pasted code, the family whose SKUs merely start with the same
	 * characters is noise - and on the results page, where WordPress orders by
	 * its own title relevance, that noise could push the right product down.
	 * Prefix matches therefore only count when nothing matched exactly.
	 *
	 * Prefix matches are kept even when they fill the limit, but the cut is not
	 * arbitrary: the query orders them by SKU length, so what survives is the
	 * codes closest to what was typed rather than the oldest products that
	 * happen to start the same way. A phrase short enough to overflow the limit
	 * is a fragment, and the fulltext results stand next to it either way.
	 *
	 * @param array $rows  Rows with px_product_id and px_exact.
	 * @param int   $limit Maximum number of products.
	 * @return int[]
	 */
	private static function rows_to_ids( $rows, $limit ) {
		$exact  = array();
		$prefix = array();

		foreach ( (array) $rows as $row ) {
			$product_id = absint( $row->px_product_id );

			if ( ! $product_id ) {
				continue;
			}

			if ( (int) $row->px_exact ) {
				$exact[] = $product_id;
			} else {
				$prefix[] = $product_id;
			}
		}

		if ( ! empty( $exact ) ) {
			return array_slice( $exact, 0, $limit );
		}

		return array_slice( $prefix, 0, $limit );
	}

	/**
	 * Is the phrase shaped like a code at all?
	 *
	 * Guards the postmeta fallback: no whitespace and at least one digit, which
	 * "helmet" or "brake pads" never satisfy, so a word search never triggers
	 * the unindexed scan.
	 *
	 * @param string $term Phrase as typed.
	 * @return bool
	 */
	private static function looks_like_code( $term ) {
		return mb_strlen( $term ) >= 3
			&& ! preg_match( '/\s/u', $term )
			&& (bool) preg_match( '/\d/u', $term );
	}

	/**
	 * Is the phrase a GTIN at all?
	 *
	 * GTIN-8, UPC-12, EAN-13 and GTIN-14 - nothing between, nothing longer.
	 * The column has no index, so anything looser turns an ordinary numeric SKU
	 * into a full scan of the lookup table.
	 *
	 * @param string $term Phrase as typed.
	 * @return bool
	 */
	private static function looks_like_gtin( $term ) {
		return (bool) preg_match( '/^\d{8}$|^\d{12,14}$/', $term );
	}

	/**
	 * Does the lookup table know about EAN/GTIN? (WooCommerce 9.1 and up.)
	 *
	 * Cached for a week under a key carrying the WooCommerce version, so the
	 * answer is re-asked exactly when the schema could have moved - and not once
	 * per request.
	 *
	 * @return bool
	 */
	private static function has_gtin_column() {
		static $has = null;

		if ( null !== $has ) {
			return $has;
		}

		global $wpdb;

		$key    = 'px_search_gtin_' . ( defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown' );
		$cached = get_transient( $key );

		if ( false !== $cached ) {
			$has = ( '1' === $cached );

			return $has;
		}

		$table = $wpdb->prefix . 'wc_product_meta_lookup';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
		$has = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'global_unique_id' ) );

		set_transient( $key, $has ? '1' : '0', WEEK_IN_SECONDS );

		return $has;
	}
}
