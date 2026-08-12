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
	}

	public static function register_route() {
		register_rest_route( 'pixeler/v1', '/search', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'term' => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );
	}

	public static function handle( $request ) {
		$term = trim( $request->get_param( 'term' ) );

		if ( mb_strlen( $term ) < 2 ) {
			return rest_ensure_response( array(
				'items' => array(),
				'total' => 0,
			) );
		}

		$limit = (int) apply_filters( 'px_shop_core_search_limit', 8 );

		// Same visibility rules the shop archive runs on - otherwise the
		// suggester offers products the search page then drops, and its
		// "show all N results" promises a number the page cannot deliver.
		$hidden = array( 'exclude-from-search' );

		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			$hidden[] = 'outofstock';
		}

		$query = new WP_Query( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			's'              => $term,
			'posts_per_page' => $limit,
			'tax_query'      => array(
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => $hidden,
					'operator' => 'NOT IN',
				),
			),
		) );

		$items = array();
		foreach ( $query->posts as $result_post ) {
			$product = wc_get_product( $result_post );
			if ( ! $product ) {
				continue;
			}

			$image_id = $product->get_image_id();

			$items[] = array(
				'type'     => 'product',
				'id'       => $product->get_id(),
				'title'    => html_entity_decode( wp_strip_all_tags( $product->get_name() ), ENT_QUOTES, 'UTF-8' ),
				'url'      => get_permalink( $result_post ),
				'price'    => $product->get_price_html(),
				// Only ever false on shops that keep sold-out products listed -
				// the client marks those rows so they do not read as an offer.
				'in_stock' => $product->is_in_stock(),
				'image'    => $image_id
					? wp_get_attachment_image_url( $image_id, 'woocommerce_gallery_thumbnail' )
					: wc_placeholder_img_src( 'woocommerce_gallery_thumbnail' ),
			);
		}

		// Matching product categories - returned separately so the client can set
		// them apart from products (own section + icon), and so the product
		// "total"/"viewAll" paging stays about products only.
		$categories = array();
		$cat_limit  = (int) apply_filters( 'px_shop_core_search_cat_limit', 4 );
		// Fetched with room to spare: WooCommerce swaps in its own term counts
		// (visible products, children included) after the query runs, so
		// 'hide_empty' does not catch every empty branch - those are dropped
		// below and the limit has to survive it.
		$cat_terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'number'     => $cat_limit * 3,
			'name__like' => $term,
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
			'viewAll'    => esc_url_raw( add_query_arg(
				array(
					's'         => $term,
					'post_type' => 'product',
				),
				home_url( '/' )
			) ),
		);

		/**
		 * Filter the search response before it is returned. Lets the active theme
		 * enrich results (e.g. add a localised category product-count label)
		 * without this plugin taking on the theme's text domain.
		 *
		 * @param array  $response
		 * @param string $term
		 */
		return rest_ensure_response( apply_filters( 'px_shop_core_search_response', $response, $term ) );
	}
}
