<?php
/**
 * Non-public "Content" CPT.
 *
 * Admin-managed content blocks (banners, promo strips, USP boxes, ...)
 * that themes render wherever they need. Items are never queryable on
 * the front end - the theme pulls them explicitly via PX_Content::get_items()
 * / px_get_content_items(). Grouping is done with the px_content_category
 * taxonomy (e.g. "homepage-banners"), ordering with menu_order.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Content {

	const POST_TYPE = 'px_content';
	const TAXONOMY  = 'px_content_category';

	const META_BTN_LABEL = '_px_content_btn_label';
	const META_BTN_URL   = '_px_content_btn_url';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_meta' ), 10, 2 );
	}

	public static function register() {
		register_post_type( self::POST_TYPE, array(
			'labels'              => array(
				'name'          => __( 'Content', 'px-shop-core' ),
				'singular_name' => __( 'Content item', 'px-shop-core' ),
				'add_new_item'  => __( 'Add content item', 'px-shop-core' ),
				'edit_item'     => __( 'Edit content item', 'px-shop-core' ),
				'search_items'  => __( 'Search content', 'px-shop-core' ),
				'not_found'     => __( 'No content items found.', 'px-shop-core' ),
			),
			'description'         => __( 'Reusable content blocks (banners etc.) rendered by the theme.', 'px-shop-core' ),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => true, // Gutenberg editor.
			'menu_position'       => 21,
			'menu_icon'           => 'dashicons-images-alt2',
			'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ),
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
		) );

		register_taxonomy( self::TAXONOMY, self::POST_TYPE, array(
			'labels'            => array(
				'name'          => __( 'Content categories', 'px-shop-core' ),
				'singular_name' => __( 'Content category', 'px-shop-core' ),
				'add_new_item'  => __( 'Add content category', 'px-shop-core' ),
				'edit_item'     => __( 'Edit content category', 'px-shop-core' ),
				'search_items'  => __( 'Search content categories', 'px-shop-core' ),
			),
			'public'            => false,
			'hierarchical'      => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => false,
			'query_var'         => false,
		) );
	}

	/* ------------------------------ Meta box ----------------------------- */

	public static function add_meta_box() {
		add_meta_box(
			'px-content-button',
			__( 'Call to action', 'px-shop-core' ),
			array( __CLASS__, 'render_meta_box' ),
			self::POST_TYPE,
			'side'
		);
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'px_content_meta', 'px_content_meta_nonce' );
		$label = get_post_meta( $post->ID, self::META_BTN_LABEL, true );
		$url   = get_post_meta( $post->ID, self::META_BTN_URL, true );
		?>
		<p>
			<label for="px_content_btn_label"><strong><?php esc_html_e( 'Button text', 'px-shop-core' ); ?></strong></label>
			<input type="text" id="px_content_btn_label" name="px_content_btn_label" class="widefat" value="<?php echo esc_attr( $label ); ?>" />
		</p>
		<p>
			<label for="px_content_btn_url"><strong><?php esc_html_e( 'Button link', 'px-shop-core' ); ?></strong></label>
			<input type="url" id="px_content_btn_url" name="px_content_btn_url" class="widefat" placeholder="https://" value="<?php echo esc_attr( $url ); ?>" />
		</p>
		<p class="description">
			<?php esc_html_e( 'Title = heading, Excerpt = intro text, Featured image = banner image. The button shows only when both fields above are filled.', 'px-shop-core' ); ?>
		</p>
		<?php
	}

	public static function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['px_content_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['px_content_meta_nonce'] ), 'px_content_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$label = isset( $_POST['px_content_btn_label'] ) ? sanitize_text_field( wp_unslash( $_POST['px_content_btn_label'] ) ) : '';
		$url   = isset( $_POST['px_content_btn_url'] ) ? esc_url_raw( wp_unslash( $_POST['px_content_btn_url'] ) ) : '';

		$label ? update_post_meta( $post_id, self::META_BTN_LABEL, $label ) : delete_post_meta( $post_id, self::META_BTN_LABEL );
		$url ? update_post_meta( $post_id, self::META_BTN_URL, $url ) : delete_post_meta( $post_id, self::META_BTN_URL );
	}

	/**
	 * Normalized banner data for a content item.
	 *
	 * @param int|WP_Post $post Content item.
	 * @return array heading, perex, text, button_label, button_url, image_id, image_url
	 */
	public static function get_banner( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return array();
		}

		$image_id = get_post_thumbnail_id( $post );

		return array(
			'id'           => $post->ID,
			'heading'      => get_the_title( $post ),
			'perex'        => $post->post_excerpt,
			'text'         => $post->post_content,
			'button_label' => (string) get_post_meta( $post->ID, self::META_BTN_LABEL, true ),
			'button_url'   => (string) get_post_meta( $post->ID, self::META_BTN_URL, true ),
			'image_id'     => $image_id,
			'image_url'    => $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '',
		);
	}

	/**
	 * Published content items, optionally limited to a category.
	 *
	 * @param string $category Category slug ('' = all).
	 * @param int    $limit    Max items (-1 = all).
	 * @return WP_Post[] Ordered by menu_order, then date DESC.
	 */
	public static function get_items( $category = '', $limit = -1 ) {
		$args = array(
			'post_type'              => self::POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'orderby'                => array( 'menu_order' => 'ASC', 'date' => 'DESC' ),
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
		);

		if ( '' !== $category ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => self::TAXONOMY,
					'field'    => 'slug',
					'terms'    => $category,
				),
			);
		}

		return get_posts( $args );
	}
}

/**
 * Theme-facing helper.
 *
 * @param string $category Category slug ('' = all).
 * @param int    $limit    Max items (-1 = all).
 * @return WP_Post[]
 */
function px_get_content_items( $category = '', $limit = -1 ) {
	return PX_Content::get_items( $category, $limit );
}

/**
 * Normalized banner data for a content item.
 *
 * @param int|WP_Post $post Content item.
 * @return array See PX_Content::get_banner().
 */
function px_get_banner( $post ) {
	return PX_Content::get_banner( $post );
}
