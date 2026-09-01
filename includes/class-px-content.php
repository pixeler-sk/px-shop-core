<?php
/**
 * Non-public "Content" CPT and the banner renderer on top of it.
 *
 * Admin-managed content blocks (banners, promo strips, USP boxes, ...)
 * that themes render wherever they need. Items are never queryable on
 * the front end - the theme pulls them explicitly via PX_Content::get_items()
 * / px_get_content_items(). Grouping is done with the px_content_category
 * taxonomy (e.g. "homepage-banners"), ordering with menu_order.
 *
 * Text is edited in the normal editor; how a banner LOOKS is a layout -
 * a template file plus the classes the theme styles. Layouts live in the
 * registry (filter px_content_layouts), their markup in templates/content/.
 * A project overrides any of them by dropping a file of the same name into
 * `yourtheme/px-shop-core/content/`, or registers its own layout with the
 * filter and ships only that one file. This plugin therefore never carries
 * project design - only neutral markup that works (plainly) on a bare theme.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Content {

	const POST_TYPE = 'px_content';
	const TAXONOMY  = 'px_content_category';

	const META_LAYOUT     = '_px_content_layout';
	const META_EYEBROW    = '_px_content_eyebrow';
	const META_ALIGN      = '_px_content_align';
	const META_OVERLAY    = '_px_content_overlay';
	const META_BTN_LABEL  = '_px_content_btn_label';
	const META_BTN_URL    = '_px_content_btn_url';
	const META_BTN2_LABEL = '_px_content_btn2_label';
	const META_BTN2_URL   = '_px_content_btn2_url';

	/**
	 * Guards against a banner rendering itself through a [px_banner] in its
	 * own text.
	 *
	 * @var int
	 */
	private static $depth = 0;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_meta' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );

		// Banners are invisible to page cache plugins: the post type is not
		// public, so WP Rocket's rocket_clean_post() bails on it and the
		// edited banner never shows up on the front end - not even on the
		// homepage. A banner has no URL of its own and can sit on the
		// homepage, in a category and on a product page at once, so the only
		// honest purge is the whole domain.
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'purge_cache' ), 20, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'purge_cache_for_post' ) );
		add_action( 'trashed_post', array( __CLASS__, 'purge_cache_for_post' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'purge_cache_for_post' ) );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'admin_column' ), 10, 2 );

		add_shortcode( 'px_banner', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Full page cache purge after a banner changed.
	 *
	 * Runs on save_post, so it also covers publishing, unpublishing and
	 * quick edit of menu_order. Autosaves, revisions and the empty
	 * auto-draft WordPress creates when "Add new" is opened change nothing
	 * on the front end and must not throw the cache away.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object.
	 */
	public static function purge_cache( $post_id, $post = null ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$status = $post instanceof WP_Post ? $post->post_status : get_post_status( $post_id );

		if ( 'auto-draft' === $status ) {
			return;
		}

		px_shop_core_purge_page_cache();
	}

	/**
	 * Same purge for hooks that fire for every post type. before_delete_post
	 * runs while the post still exists, so the type is still readable.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function purge_cache_for_post( $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		px_shop_core_purge_page_cache();
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

		self::register_meta();
	}

	/**
	 * Banner fields as post meta, so REST and WP-CLI can write them too
	 * (deployments seed banners with `wp post meta set`).
	 */
	private static function register_meta() {
		$strings = array(
			self::META_LAYOUT,
			self::META_EYEBROW,
			self::META_ALIGN,
			self::META_BTN_LABEL,
			self::META_BTN2_LABEL,
		);

		foreach ( $strings as $key ) {
			register_post_meta( self::POST_TYPE, $key, array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( __CLASS__, 'can_edit' ),
			) );
		}

		foreach ( array( self::META_BTN_URL, self::META_BTN2_URL ) as $key ) {
			register_post_meta( self::POST_TYPE, $key, array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => array( __CLASS__, 'can_edit' ),
			) );
		}

		register_post_meta( self::POST_TYPE, self::META_OVERLAY, array(
			'type'              => 'integer',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
			'auth_callback'     => array( __CLASS__, 'can_edit' ),
		) );
	}

	/**
	 * @return bool
	 */
	public static function can_edit() {
		return current_user_can( 'edit_posts' );
	}

	/* ------------------------------- Layouts ----------------------------- */

	/**
	 * Registered banner layouts, keyed by layout id.
	 *
	 * Definition keys:
	 *   label    - name in the layout select (required)
	 *   template - template relative to templates/ (default: content/banner-{id}.php)
	 *   supports - fields the layout uses: image, eyebrow, align, overlay,
	 *              buttons; fields a layout does not support are hidden in
	 *              the editor, so the screen only ever shows what matters
	 *
	 * A project adds its own layout here and ships one template file:
	 *
	 *   add_filter( 'px_content_layouts', function ( $layouts ) {
	 *       $layouts['sale-strip'] = array(
	 *           'label'    => 'Akciovy pas',
	 *           'template' => 'content/banner-sale-strip.php', // in the theme
	 *           'supports' => array( 'eyebrow', 'buttons' ),
	 *       );
	 *       return $layouts;
	 *   } );
	 *
	 * @return array
	 */
	public static function layouts() {
		$layouts = array(
			'media-right' => array(
				'label'    => __( 'Text left, image right', 'px-shop-core' ),
				'template' => 'content/banner-split.php',
				'supports' => array( 'image', 'eyebrow', 'align', 'buttons' ),
			),
			'media-left'  => array(
				'label'    => __( 'Image left, text right', 'px-shop-core' ),
				'template' => 'content/banner-split.php',
				'supports' => array( 'image', 'eyebrow', 'align', 'buttons' ),
			),
			'background'  => array(
				'label'    => __( 'Image background, text over it', 'px-shop-core' ),
				'template' => 'content/banner-background.php',
				'supports' => array( 'image', 'eyebrow', 'align', 'overlay', 'buttons' ),
			),
			'plain'       => array(
				'label'    => __( 'Text and buttons only', 'px-shop-core' ),
				'template' => 'content/banner.php',
				'supports' => array( 'eyebrow', 'align', 'buttons' ),
			),
		);

		/**
		 * Filters the banner layout registry.
		 *
		 * @param array $layouts Layout definitions keyed by layout id.
		 */
		return apply_filters( 'px_content_layouts', $layouts );
	}

	/**
	 * Layout id used when an item has none (or an unknown one).
	 *
	 * @return string
	 */
	public static function default_layout() {
		$layouts = self::layouts();
		$default = apply_filters( 'px_content_default_layout', 'media-right' );

		if ( isset( $layouts[ $default ] ) ) {
			return $default;
		}

		$keys = array_keys( $layouts );

		return $keys ? $keys[0] : '';
	}

	/**
	 * Definition of one layout, with defaults filled in.
	 *
	 * @param string $layout Layout id.
	 * @return array
	 */
	public static function layout( $layout ) {
		$layouts = self::layouts();

		if ( ! isset( $layouts[ $layout ] ) ) {
			$layout = self::default_layout();
		}

		$def = isset( $layouts[ $layout ] ) ? (array) $layouts[ $layout ] : array();

		return array_merge( array(
			'id'       => $layout,
			'label'    => $layout,
			'template' => 'content/banner-' . $layout . '.php',
			'supports' => array( 'image', 'eyebrow', 'align', 'overlay', 'buttons' ),
		), $def );
	}

	/**
	 * Does a layout use a given field?
	 *
	 * @param string $layout Layout id.
	 * @param string $field  image|eyebrow|align|overlay|buttons.
	 * @return bool
	 */
	public static function layout_supports( $layout, $field ) {
		$def = self::layout( $layout );

		return in_array( $field, (array) $def['supports'], true );
	}

	/* ------------------------------ Meta box ----------------------------- */

	public static function add_meta_box() {
		add_meta_box(
			'px-content-banner',
			__( 'Banner display', 'px-shop-core' ),
			array( __CLASS__, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Admin CSS/JS for the banner box (only on this post type's screens).
	 *
	 * @param string $hook Current admin page.
	 */
	public static function admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$base = plugins_url( 'assets/', PX_SHOP_CORE_FILE );

		wp_enqueue_style( 'px-content-admin', $base . 'admin-content.css', array(), PX_SHOP_CORE_VERSION );
		wp_enqueue_script( 'px-content-admin', $base . 'admin-content.js', array(), PX_SHOP_CORE_VERSION, true );

		$supports = array();

		foreach ( array_keys( self::layouts() ) as $layout ) {
			$supports[ $layout ] = self::layout( $layout )['supports'];
		}

		wp_localize_script( 'px-content-admin', 'pxContentLayouts', $supports );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'px_content_meta', 'px_content_meta_nonce' );

		$layout  = self::item_layout( $post );
		$eyebrow = (string) get_post_meta( $post->ID, self::META_EYEBROW, true );
		$align   = self::item_align( $post );
		$overlay = get_post_meta( $post->ID, self::META_OVERLAY, true );
		$overlay = '' === $overlay ? 45 : (int) $overlay;
		?>
		<div class="px-content-box">

			<p class="px-content-box__intro description">
				<?php esc_html_e( 'Title = heading, Excerpt = intro text above the buttons, editor = free text, Featured image = banner image. Empty fields are simply not rendered.', 'px-shop-core' ); ?>
			</p>

			<div class="px-content-grid">

				<p class="px-content-field">
					<label for="px_content_layout"><strong><?php esc_html_e( 'Layout', 'px-shop-core' ); ?></strong></label>
					<select id="px_content_layout" name="px_content_layout">
						<?php foreach ( self::layouts() as $key => $def ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $layout, $key ); ?>>
								<?php echo esc_html( isset( $def['label'] ) ? $def['label'] : $key ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<p class="px-content-field" data-px-field="eyebrow">
					<label for="px_content_eyebrow"><strong><?php esc_html_e( 'Eyebrow', 'px-shop-core' ); ?></strong></label>
					<input type="text" id="px_content_eyebrow" name="px_content_eyebrow" class="widefat" value="<?php echo esc_attr( $eyebrow ); ?>" />
					<span class="description"><?php esc_html_e( 'Small line above the heading.', 'px-shop-core' ); ?></span>
				</p>

				<p class="px-content-field" data-px-field="align">
					<label for="px_content_align"><strong><?php esc_html_e( 'Text alignment', 'px-shop-core' ); ?></strong></label>
					<select id="px_content_align" name="px_content_align">
						<?php foreach ( self::alignments() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $align, $key ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<p class="px-content-field" data-px-field="overlay">
					<label for="px_content_overlay"><strong><?php esc_html_e( 'Image dimming (%)', 'px-shop-core' ); ?></strong></label>
					<input type="number" id="px_content_overlay" name="px_content_overlay" min="0" max="90" step="5" value="<?php echo esc_attr( (string) $overlay ); ?>" />
					<span class="description"><?php esc_html_e( 'Dark layer between image and text, so the text stays readable.', 'px-shop-core' ); ?></span>
				</p>

			</div>

			<div class="px-content-grid" data-px-field="buttons">

				<p class="px-content-field">
					<label for="px_content_btn_label"><strong><?php esc_html_e( 'Button text', 'px-shop-core' ); ?></strong></label>
					<input type="text" id="px_content_btn_label" name="px_content_btn_label" class="widefat" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, self::META_BTN_LABEL, true ) ); ?>" />
				</p>

				<p class="px-content-field">
					<label for="px_content_btn_url"><strong><?php esc_html_e( 'Button link', 'px-shop-core' ); ?></strong></label>
					<input type="url" id="px_content_btn_url" name="px_content_btn_url" class="widefat" placeholder="https://" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, self::META_BTN_URL, true ) ); ?>" />
				</p>

				<p class="px-content-field">
					<label for="px_content_btn2_label"><strong><?php esc_html_e( 'Second button text', 'px-shop-core' ); ?></strong></label>
					<input type="text" id="px_content_btn2_label" name="px_content_btn2_label" class="widefat" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, self::META_BTN2_LABEL, true ) ); ?>" />
				</p>

				<p class="px-content-field">
					<label for="px_content_btn2_url"><strong><?php esc_html_e( 'Second button link', 'px-shop-core' ); ?></strong></label>
					<input type="url" id="px_content_btn2_url" name="px_content_btn2_url" class="widefat" placeholder="https://" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, self::META_BTN2_URL, true ) ); ?>" />
				</p>

			</div>

			<p class="description">
				<?php
				printf(
					/* translators: %s: shortcode example */
					esc_html__( 'A button shows only when both its fields are filled. Place the banner anywhere with %s.', 'px-shop-core' ),
					'<code>[px_banner id="' . (int) $post->ID . '"]</code>'
				);
				?>
			</p>

		</div>
		<?php
	}

	/**
	 * Text alignment options.
	 *
	 * @return array
	 */
	public static function alignments() {
		return array(
			'left'   => __( 'Left', 'px-shop-core' ),
			'center' => __( 'Center', 'px-shop-core' ),
			'right'  => __( 'Right', 'px-shop-core' ),
		);
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

		$text = array(
			self::META_EYEBROW    => 'px_content_eyebrow',
			self::META_BTN_LABEL  => 'px_content_btn_label',
			self::META_BTN2_LABEL => 'px_content_btn2_label',
		);

		foreach ( $text as $meta_key => $field ) {
			$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';

			$value ? update_post_meta( $post_id, $meta_key, $value ) : delete_post_meta( $post_id, $meta_key );
		}

		$urls = array(
			self::META_BTN_URL  => 'px_content_btn_url',
			self::META_BTN2_URL => 'px_content_btn2_url',
		);

		foreach ( $urls as $meta_key => $field ) {
			$value = isset( $_POST[ $field ] ) ? esc_url_raw( wp_unslash( $_POST[ $field ] ) ) : '';

			$value ? update_post_meta( $post_id, $meta_key, $value ) : delete_post_meta( $post_id, $meta_key );
		}

		// Unknown layout / alignment would silently fall back on every render;
		// storing only known values keeps the data honest.
		$layout = isset( $_POST['px_content_layout'] ) ? sanitize_key( wp_unslash( $_POST['px_content_layout'] ) ) : '';
		$layout = isset( self::layouts()[ $layout ] ) ? $layout : self::default_layout();
		update_post_meta( $post_id, self::META_LAYOUT, $layout );

		$align = isset( $_POST['px_content_align'] ) ? sanitize_key( wp_unslash( $_POST['px_content_align'] ) ) : '';
		$align = isset( self::alignments()[ $align ] ) ? $align : 'left';
		update_post_meta( $post_id, self::META_ALIGN, $align );

		$overlay = isset( $_POST['px_content_overlay'] ) ? (int) $_POST['px_content_overlay'] : 45;
		update_post_meta( $post_id, self::META_OVERLAY, max( 0, min( 90, $overlay ) ) );
	}

	/* ---------------------------- Admin columns -------------------------- */

	/**
	 * @param array $columns List table columns.
	 * @return array
	 */
	public static function admin_columns( $columns ) {
		$out = array();

		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$out['px_thumb'] = __( 'Image', 'px-shop-core' );
			}

			$out[ $key ] = $label;

			if ( 'title' === $key ) {
				$out['px_layout'] = __( 'Layout', 'px-shop-core' );
			}
		}

		return $out;
	}

	/**
	 * @param string $column  Column key.
	 * @param int    $post_id Item id.
	 */
	public static function admin_column( $column, $post_id ) {
		if ( 'px_thumb' === $column ) {
			echo has_post_thumbnail( $post_id )
				? get_the_post_thumbnail( $post_id, array( 60, 60 ) ) // phpcs:ignore WordPress.Security.EscapeOutput
				: '&mdash;';

			return;
		}

		if ( 'px_layout' === $column ) {
			$def = self::layout( self::item_layout( $post_id ) );

			echo esc_html( $def['label'] );
		}
	}

	/* ------------------------------- Data -------------------------------- */

	/**
	 * Layout of an item, always an id that exists in the registry.
	 *
	 * @param int|WP_Post $post Content item.
	 * @return string
	 */
	public static function item_layout( $post ) {
		$post   = get_post( $post );
		$layout = $post ? (string) get_post_meta( $post->ID, self::META_LAYOUT, true ) : '';

		return isset( self::layouts()[ $layout ] ) ? $layout : self::default_layout();
	}

	/**
	 * @param int|WP_Post $post Content item.
	 * @return string left|center|right
	 */
	public static function item_align( $post ) {
		$post  = get_post( $post );
		$align = $post ? (string) get_post_meta( $post->ID, self::META_ALIGN, true ) : '';

		return isset( self::alignments()[ $align ] ) ? $align : 'left';
	}

	/**
	 * Normalized banner data for a content item.
	 *
	 * @param int|WP_Post $post Content item.
	 * @return array Empty when the item does not exist.
	 */
	public static function get_banner( $post ) {
		$post = get_post( $post );

		if ( ! $post ) {
			return array();
		}

		$image_id = get_post_thumbnail_id( $post );
		$overlay  = get_post_meta( $post->ID, self::META_OVERLAY, true );

		$banner = array(
			'id'            => $post->ID,
			'layout'        => self::item_layout( $post ),
			'align'         => self::item_align( $post ),
			'overlay'       => '' === $overlay ? 45 : max( 0, min( 90, (int) $overlay ) ),
			'eyebrow'       => (string) get_post_meta( $post->ID, self::META_EYEBROW, true ),
			'heading'       => get_the_title( $post ),
			'perex'         => $post->post_excerpt,
			'text'          => $post->post_content,
			'button_label'  => (string) get_post_meta( $post->ID, self::META_BTN_LABEL, true ),
			'button_url'    => (string) get_post_meta( $post->ID, self::META_BTN_URL, true ),
			'button2_label' => (string) get_post_meta( $post->ID, self::META_BTN2_LABEL, true ),
			'button2_url'   => (string) get_post_meta( $post->ID, self::META_BTN2_URL, true ),
			'image_id'      => $image_id,
			'image_url'     => $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '',
		);

		/**
		 * Filters the banner data of one item.
		 *
		 * @param array   $banner Banner data.
		 * @param WP_Post $post   Content item.
		 */
		return apply_filters( 'px_content_banner_data', $banner, $post );
	}

	/**
	 * One content item by id, slug or object.
	 *
	 * @param int|string|WP_Post $ref Id, post_name or object.
	 * @return WP_Post|null
	 */
	public static function get_item( $ref ) {
		if ( $ref instanceof WP_Post ) {
			return $ref;
		}

		if ( is_numeric( $ref ) ) {
			$post = get_post( (int) $ref );

			return ( $post && self::POST_TYPE === $post->post_type ) ? $post : null;
		}

		$ref = sanitize_title( (string) $ref );

		if ( '' === $ref ) {
			return null;
		}

		$found = get_posts( array(
			'post_type'      => self::POST_TYPE,
			'name'           => $ref,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		) );

		return $found ? $found[0] : null;
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
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'DESC' ),
			'no_found_rows'  => true,
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

	/* ----------------------------- Templates ----------------------------- */

	/**
	 * Absolute path of a template, theme override first.
	 *
	 * Override by copying the file to `yourtheme/px-shop-core/<name>`
	 * (child theme wins over parent - locate_template checks both).
	 *
	 * @param string $name Path relative to templates/, e.g. content/banner.php.
	 * @return string Empty when neither theme nor plugin has the file.
	 */
	public static function locate_template( $name ) {
		$name  = ltrim( (string) $name, '/' );
		$found = locate_template( array( 'px-shop-core/' . $name ) );

		if ( ! $found ) {
			$file = PX_SHOP_CORE_DIR . 'templates/' . $name;

			$found = file_exists( $file ) ? $file : '';
		}

		/**
		 * Filters the resolved template path.
		 *
		 * @param string $found Absolute path ('' when nothing was found).
		 * @param string $name  Template name relative to templates/.
		 */
		return (string) apply_filters( 'px_content_locate_template', $found, $name );
	}

	/**
	 * Renders a template to string.
	 *
	 * @param string $name Path relative to templates/.
	 * @param array  $args Variables extracted into the template.
	 * @return string
	 */
	public static function get_template_html( $name, $args = array() ) {
		$file = self::locate_template( $name );

		if ( ! $file ) {
			return '';
		}

		if ( $args ) {
			extract( $args ); // phpcs:ignore WordPress.PHP.DontExtract
		}

		ob_start();
		include $file;

		return (string) ob_get_clean();
	}

	/* ------------------------------ Rendering ---------------------------- */

	/**
	 * Editor content of a banner, run through the usual content filters.
	 *
	 * Not `the_content`: that filter is owned by the page being displayed
	 * and plugins hook sharing buttons, related posts and the like onto it.
	 *
	 * @param string $raw Raw post_content.
	 * @return string
	 */
	public static function content_html( $raw ) {
		$raw = (string) $raw;

		if ( '' === trim( $raw ) ) {
			return '';
		}

		$html = do_blocks( $raw );
		$html = wptexturize( $html );
		$html = convert_smilies( $html );
		$html = wpautop( $html );
		$html = shortcode_unautop( $html );
		$html = do_shortcode( $html );

		/**
		 * Filters the rendered banner text.
		 *
		 * @param string $html Rendered HTML.
		 * @param string $raw  Raw post_content.
		 */
		return apply_filters( 'px_content_text_html', $html, $raw );
	}

	/**
	 * Renders one banner.
	 *
	 * @param int|string|WP_Post $item Content item (id, slug or object).
	 * @param array              $args Rendering arguments:
	 *     layout       string Force a layout ('' = the item's own).
	 *     class        string Extra classes on the wrapper.
	 *     heading_tag  string h1..h6, default h2.
	 *     image_size   string Image size, default 'large'.
	 *     eager        bool   Skip lazy loading (banner above the fold).
	 * @return string Empty when the item does not exist or the layout has
	 *                no template.
	 */
	public static function render( $item, $args = array() ) {
		// A banner whose text contains [px_banner] pointing back at itself
		// would recurse until the request dies.
		if ( self::$depth > 2 ) {
			return '';
		}

		$post   = self::get_item( $item );
		$banner = $post ? self::get_banner( $post ) : array();

		if ( ! $banner ) {
			return '';
		}

		$args = wp_parse_args( $args, array(
			'layout'      => '',
			'class'       => '',
			'heading_tag' => 'h2',
			'image_size'  => 'large',
			'eager'       => false,
		) );

		$layout = $args['layout'] && isset( self::layouts()[ $args['layout'] ] ) ? $args['layout'] : $banner['layout'];
		$def    = self::layout( $layout );

		$banner['layout'] = $def['id'];

		// A layout without image support must not draw one even when the
		// item has a featured image (a project may reuse the same item).
		if ( ! in_array( 'image', (array) $def['supports'], true ) ) {
			$banner['image_id'] = 0;
		}

		$args['layout']    = $def['id'];
		$args['supports']  = (array) $def['supports'];
		$args['classes']   = self::classes( $banner, $args );
		$args['text_html'] = self::content_html( $banner['text'] );

		$args['heading_tag'] = preg_match( '/^h[1-6]$/', (string) $args['heading_tag'] ) ? $args['heading_tag'] : 'h2';

		self::enqueue_style();

		self::$depth++;
		$html = self::get_template_html( $def['template'], array(
			'banner' => $banner,
			'args'   => $args,
		) );
		self::$depth--;

		/**
		 * Filters the rendered banner.
		 *
		 * @param string $html   Banner HTML.
		 * @param array  $banner Banner data.
		 * @param array  $args   Rendering arguments.
		 */
		return apply_filters( 'px_content_banner_html', $html, $banner, $args );
	}

	/**
	 * Wrapper classes of a banner.
	 *
	 * @param array $banner Banner data.
	 * @param array $args   Rendering arguments.
	 * @return string
	 */
	private static function classes( $banner, $args ) {
		$classes = array(
			'px-banner',
			'px-banner--' . $banner['layout'],
			'px-banner--align-' . $banner['align'],
		);

		if ( empty( $banner['image_id'] ) ) {
			$classes[] = 'px-banner--no-image';
		}

		if ( ! empty( $args['class'] ) ) {
			$classes = array_merge( $classes, preg_split( '/\s+/', trim( (string) $args['class'] ) ) );
		}

		/**
		 * Filters the wrapper classes of a banner.
		 *
		 * @param array $classes Class names.
		 * @param array $banner  Banner data.
		 * @param array $args    Rendering arguments.
		 */
		$classes = (array) apply_filters( 'px_content_banner_classes', $classes, $banner, $args );

		return implode( ' ', array_unique( array_filter( array_map( 'sanitize_html_class', $classes ) ) ) );
	}

	/**
	 * Renders every banner of a category (or a single one) as one block.
	 *
	 * @param array $args Group arguments, on top of render() arguments:
	 *     item     mixed  One item (id, slug, WP_Post) instead of a category.
	 *     category string Content category slug.
	 *     limit    int    Max items, default -1.
	 *     columns  int    Items side by side (0/1 = stacked).
	 *     wrap     bool   Draw the .px-banners wrapper, default true.
	 * @return string
	 */
	public static function render_group( $args = array() ) {
		$args = wp_parse_args( $args, array(
			'item'     => 0,
			'category' => '',
			'limit'    => -1,
			'columns'  => 0,
			'wrap'     => true,
		) );

		$items = $args['item']
			? array_filter( array( self::get_item( $args['item'] ) ) )
			: self::get_items( $args['category'], (int) $args['limit'] );

		if ( ! $items ) {
			return '';
		}

		$html = '';

		foreach ( $items as $item ) {
			$html .= self::render( $item, $args );
		}

		if ( '' === trim( $html ) ) {
			return '';
		}

		$columns = max( 0, (int) $args['columns'] );

		if ( ! $args['wrap'] ) {
			return $html;
		}

		$classes = 'px-banners';

		if ( $columns > 1 ) {
			$classes .= ' px-banners--cols-' . $columns;
		}

		return sprintf(
			'<div class="%1$s"%2$s>%3$s</div>',
			esc_attr( $classes ),
			$columns > 1 ? ' style="--px-banners-cols:' . (int) $columns . '"' : '',
			$html
		);
	}

	/**
	 * Theme stylesheet for banners, when the theme registered one.
	 *
	 * Same contract as the other core modules: the plugin ships markup and
	 * asks for the handle, the theme owns the file. Themes that enqueue
	 * `px-banner` themselves (no flash of unstyled banner) are untouched.
	 */
	private static function enqueue_style() {
		$handle = apply_filters( 'px_content_style_handle', 'px-banner' );

		if ( $handle && wp_style_is( $handle, 'registered' ) && ! wp_style_is( $handle, 'enqueued' ) ) {
			wp_enqueue_style( $handle );
		}
	}

	/* ------------------------------ Shortcode ---------------------------- */

	/**
	 * [px_banner id="12"], [px_banner slug="spring-sale"],
	 * [px_banner category="homepage" columns="3" layout="background"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		$atts = shortcode_atts( array(
			'id'       => '',
			'slug'     => '',
			'category' => '',
			'limit'    => -1,
			'columns'  => 0,
			'layout'   => '',
			'class'    => '',
			'heading'  => 'h2',
		), $atts, 'px_banner' );

		$item = $atts['id'] ? $atts['id'] : $atts['slug'];

		if ( ! $item && ! $atts['category'] ) {
			return '';
		}

		return self::render_group( array(
			'item'        => $item,
			'category'    => $atts['category'],
			'limit'       => (int) $atts['limit'],
			'columns'     => (int) $atts['columns'],
			'layout'      => $atts['layout'],
			'class'       => $atts['class'],
			'heading_tag' => $atts['heading'],
		) );
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

/**
 * Rendered banner.
 *
 * @param int|string|WP_Post $item Content item (id, slug or object).
 * @param array              $args See PX_Content::render().
 * @return string
 */
function px_get_banner_html( $item, $args = array() ) {
	return PX_Content::render( $item, $args );
}

/**
 * Prints one banner.
 *
 * @param int|string|WP_Post $item Content item (id, slug or object).
 * @param array              $args See PX_Content::render().
 */
function px_banner( $item, $args = array() ) {
	echo PX_Content::render( $item, $args ); // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * Rendered banners of a content category.
 *
 * @param array $args See PX_Content::render_group().
 * @return string
 */
function px_get_banners_html( $args = array() ) {
	return PX_Content::render_group( $args );
}

/**
 * Prints the banners of a content category.
 *
 * @param array|string $args Group arguments, or a category slug.
 */
function px_banners( $args = array() ) {
	if ( is_string( $args ) ) {
		$args = array( 'category' => $args );
	}

	echo PX_Content::render_group( $args ); // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * Prints a banner template part (used inside banner templates).
 *
 * @param string $name Path relative to templates/.
 * @param array  $args Variables for the template.
 */
function px_content_template( $name, $args = array() ) {
	echo PX_Content::get_template_html( $name, $args ); // phpcs:ignore WordPress.Security.EscapeOutput
}
