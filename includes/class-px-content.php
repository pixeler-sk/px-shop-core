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

	// Background video and whole-banner link (1.10.0). Named after the banner,
	// not the CPT: they only mean something to banner layouts.
	const META_VIDEO_URL    = '_px_banner_video_url';
	const META_VIDEO_ID     = '_px_banner_video_id';
	const META_VIDEO_MOBILE = '_px_banner_video_mobile';
	const META_LINK_URL     = '_px_banner_link_url';

	/**
	 * Video files the library picker accepts. Other formats either do not
	 * play everywhere (ogv, mov) or are far too heavy for a background.
	 */
	const VIDEO_MIMES = array( 'video/mp4', 'video/webm' );

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

		foreach ( array( self::META_BTN_URL, self::META_BTN2_URL, self::META_VIDEO_URL, self::META_LINK_URL ) as $key ) {
			register_post_meta( self::POST_TYPE, $key, array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => array( __CLASS__, 'can_edit' ),
			) );
		}

		foreach ( array( self::META_OVERLAY, self::META_VIDEO_ID ) as $key ) {
			register_post_meta( self::POST_TYPE, $key, array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( __CLASS__, 'can_edit' ),
			) );
		}

		register_post_meta( self::POST_TYPE, self::META_VIDEO_MOBILE, array(
			'type'              => 'boolean',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
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
	 *              buttons, video, link; fields a layout does not support are
	 *              hidden in the editor and ignored when rendering, so the
	 *              screen only ever shows what matters. `video` and `link`
	 *              need the template to print the parts banner-video.php and
	 *              banner-link.php - a project layout opts in explicitly.
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
				'supports' => array( 'image', 'eyebrow', 'align', 'buttons', 'video', 'link' ),
			),
			'media-left'  => array(
				'label'    => __( 'Image left, text right', 'px-shop-core' ),
				'template' => 'content/banner-split.php',
				'supports' => array( 'image', 'eyebrow', 'align', 'buttons', 'video', 'link' ),
			),
			'background'  => array(
				'label'    => __( 'Image background, text over it', 'px-shop-core' ),
				'template' => 'content/banner-background.php',
				'supports' => array( 'image', 'eyebrow', 'align', 'overlay', 'buttons', 'video', 'link' ),
			),
			// No video: there is no surface to play it on.
			'plain'       => array(
				'label'    => __( 'Text and buttons only', 'px-shop-core' ),
				'template' => 'content/banner.php',
				'supports' => array( 'eyebrow', 'align', 'buttons', 'link' ),
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
	 * @param string $field  image|eyebrow|align|overlay|buttons|video|link.
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

		// Media modal for the background video picker.
		wp_enqueue_media();

		wp_enqueue_style( 'px-content-admin', $base . 'admin-content.css', array(), PX_SHOP_CORE_VERSION );
		wp_enqueue_script( 'px-content-admin', $base . 'admin-content.js', array(), PX_SHOP_CORE_VERSION, true );

		wp_localize_script( 'px-content-admin', 'pxContentVideo', array(
			'title'  => __( 'Background video', 'px-shop-core' ),
			'button' => __( 'Use this video', 'px-shop-core' ),
			'mimes'  => self::VIDEO_MIMES,
		) );

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

			<?php self::render_video_fields( $post ); ?>

			<div class="px-content-grid" data-px-field="link">

				<p class="px-content-field">
					<label for="px_banner_link_url"><strong><?php esc_html_e( 'Whole banner link', 'px-shop-core' ); ?></strong></label>
					<input type="url" id="px_banner_link_url" name="px_banner_link_url" class="widefat" placeholder="https://" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, self::META_LINK_URL, true ) ); ?>" />
					<span class="description"><?php esc_html_e( 'Optional. The whole banner area becomes clickable; the buttons keep their own links.', 'px-shop-core' ); ?></span>
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
	 * Background video fields of the banner box.
	 *
	 * @param WP_Post $post Content item.
	 */
	private static function render_video_fields( $post ) {
		$url      = (string) get_post_meta( $post->ID, self::META_VIDEO_URL, true );
		$file_id  = (int) get_post_meta( $post->ID, self::META_VIDEO_ID, true );
		$file_url = $file_id ? (string) wp_get_attachment_url( $file_id ) : '';
		$mobile   = (bool) get_post_meta( $post->ID, self::META_VIDEO_MOBILE, true );
		?>
		<div class="px-content-grid px-content-video" data-px-field="video">

			<p class="px-content-field">
				<label for="px_banner_video_url"><strong><?php esc_html_e( 'Background video (YouTube / Vimeo)', 'px-shop-core' ); ?></strong></label>
				<input type="url" id="px_banner_video_url" name="px_banner_video_url" class="widefat" placeholder="https://www.youtube.com/watch?v=" value="<?php echo esc_attr( $url ); ?>" />
				<span class="description"><?php esc_html_e( 'Plays muted in a loop behind the text; the banner image is shown until it starts and whenever it cannot play.', 'px-shop-core' ); ?></span>
				<?php if ( '' !== $url && ! self::parse_video_url( $url ) ) : ?>
					<span class="description px-content-warning"><?php esc_html_e( 'This address is not a YouTube or Vimeo video - the banner shows only the image.', 'px-shop-core' ); ?></span>
				<?php endif; ?>
			</p>

			<div class="px-content-field">
				<span class="px-content-label"><strong><?php esc_html_e( 'Or a video file (MP4 / WebM)', 'px-shop-core' ); ?></strong></span>
				<input type="hidden" id="px_banner_video_id" name="px_banner_video_id" value="<?php echo esc_attr( $file_id ? (string) $file_id : '' ); ?>" />
				<span class="px-content-video__file" data-px-video-file><?php echo esc_html( $file_url ? wp_basename( $file_url ) : '' ); ?></span>
				<span class="px-content-video__actions">
					<button type="button" class="button" data-px-video-pick><?php esc_html_e( 'Choose video', 'px-shop-core' ); ?></button>
					<button type="button" class="button-link button-link-delete" data-px-video-clear<?php echo $file_id ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'px-shop-core' ); ?></button>
				</span>
				<span class="description"><?php esc_html_e( 'From the media library; needs no cookie consent. When both are filled, the file is used.', 'px-shop-core' ); ?></span>
			</div>

			<p class="px-content-field">
				<label>
					<input type="checkbox" name="px_banner_video_mobile" value="1" <?php checked( $mobile ); ?> />
					<?php esc_html_e( 'Play on mobile too', 'px-shop-core' ); ?>
				</label>
				<span class="description"><?php esc_html_e( 'Off: phones show only the image (saves data and battery).', 'px-shop-core' ); ?></span>
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

		// Video URL and banner link. An unrecognised video URL is kept (the
		// box warns about it) - silently dropping what the editor typed
		// would look like a save that did not happen.
		$urls = array(
			self::META_VIDEO_URL => 'px_banner_video_url',
			self::META_LINK_URL  => 'px_banner_link_url',
		);

		foreach ( $urls as $meta_key => $field ) {
			$value = isset( $_POST[ $field ] ) ? esc_url_raw( trim( wp_unslash( $_POST[ $field ] ) ) ) : '';

			$value ? update_post_meta( $post_id, $meta_key, $value ) : delete_post_meta( $post_id, $meta_key );
		}

		// Only a video attachment in a format every browser plays.
		$video_id = isset( $_POST['px_banner_video_id'] ) ? absint( $_POST['px_banner_video_id'] ) : 0;

		if ( $video_id && self::is_video_attachment( $video_id ) ) {
			update_post_meta( $post_id, self::META_VIDEO_ID, $video_id );
		} else {
			delete_post_meta( $post_id, self::META_VIDEO_ID );
		}

		if ( ! empty( $_POST['px_banner_video_mobile'] ) ) {
			update_post_meta( $post_id, self::META_VIDEO_MOBILE, true );
		} else {
			delete_post_meta( $post_id, self::META_VIDEO_MOBILE );
		}
	}

	/**
	 * Is the attachment a video file the banner can play?
	 *
	 * @param int $attachment_id Attachment id.
	 * @return bool
	 */
	public static function is_video_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		return $attachment_id
			&& 'attachment' === get_post_type( $attachment_id )
			&& in_array( get_post_mime_type( $attachment_id ), self::VIDEO_MIMES, true );
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
			'video'         => self::item_video( $post ),
			'link'          => (string) get_post_meta( $post->ID, self::META_LINK_URL, true ),
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
	 * Background video of an item.
	 *
	 * A library file wins over a URL: it needs no consent, no third-party
	 * request and no player chrome to hide.
	 *
	 * @param int|WP_Post $post Content item.
	 * @return array Empty when the item has no playable video, otherwise:
	 *     type   string youtube|vimeo|file
	 *     id     string YouTube / Vimeo id ('' for a file)
	 *     hash   string Vimeo privacy hash of an unlisted video ('' otherwise)
	 *     src    string File URL ('' for YouTube / Vimeo)
	 *     mime   string File MIME type ('' for YouTube / Vimeo)
	 *     mobile bool   Play on small screens too
	 */
	public static function item_video( $post ) {
		$post = get_post( $post );

		if ( ! $post ) {
			return array();
		}

		$video   = array();
		$file_id = (int) get_post_meta( $post->ID, self::META_VIDEO_ID, true );

		if ( $file_id && self::is_video_attachment( $file_id ) ) {
			$src = (string) wp_get_attachment_url( $file_id );

			if ( '' !== $src ) {
				$video = array(
					'type' => 'file',
					'id'   => '',
					'hash' => '',
					'src'  => $src,
					'mime' => (string) get_post_mime_type( $file_id ),
				);
			}
		}

		if ( ! $video ) {
			$parsed = self::parse_video_url( (string) get_post_meta( $post->ID, self::META_VIDEO_URL, true ) );

			if ( $parsed ) {
				$video = array_merge( $parsed, array(
					'src'  => '',
					'mime' => '',
				) );
			}
		}

		if ( ! $video ) {
			return array();
		}

		$video['mobile'] = (bool) get_post_meta( $post->ID, self::META_VIDEO_MOBILE, true );

		return $video;
	}

	/**
	 * YouTube or Vimeo id from any address an editor is likely to paste.
	 *
	 * YouTube: watch?v=, youtu.be/, embed/, shorts/, live/, v/, the nocookie
	 * and mobile hosts, with or without extra parameters (?si=, &t=, &list=).
	 * Vimeo: vimeo.com/ID, vimeo.com/ID/HASH (unlisted), channels/.../ID,
	 * groups/.../videos/ID, player.vimeo.com/video/ID?h=HASH.
	 *
	 * @param string $url Pasted address.
	 * @return array Empty when it is neither, otherwise type, id, hash.
	 */
	public static function parse_video_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return array();
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}

		$parts = wp_parse_url( $url );
		$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$path  = isset( $parts['path'] ) ? $parts['path'] : '';
		$query = array();

		if ( ! empty( $parts['query'] ) ) {
			wp_parse_str( $parts['query'], $query );
		}

		$host = preg_replace( '/^(www\.|m\.|music\.)/', '', $host );

		if ( in_array( $host, array( 'youtube.com', 'youtube-nocookie.com', 'youtu.be' ), true ) ) {
			$id = '';

			if ( 'youtu.be' === $host ) {
				$id = trim( $path, '/' );
			} elseif ( ! empty( $query['v'] ) && is_string( $query['v'] ) ) {
				$id = $query['v'];
			} elseif ( preg_match( '#^/(?:embed|shorts|live|v|e)/([^/?]+)#', $path, $m ) ) {
				$id = $m[1];
			}

			return preg_match( '/^[A-Za-z0-9_-]{11}$/', $id )
				? array( 'type' => 'youtube', 'id' => $id, 'hash' => '' )
				: array();
		}

		if ( in_array( $host, array( 'vimeo.com', 'player.vimeo.com' ), true ) ) {
			// `/video/ID` or `/videos/ID` (player, groups) first, then the
			// first all-digit segment; a hex segment right after the id is
			// the privacy hash of an unlisted video.
			if ( ! preg_match( '#/videos?/(\d{3,12})(?:/([0-9a-f]{6,20}))?(?:/|$)#i', $path, $m )
				&& ! preg_match( '#/(\d{3,12})(?:/([0-9a-f]{6,20}))?(?:/|$)#i', $path, $m ) ) {
				return array();
			}

			$hash = isset( $m[2] ) ? $m[2] : '';

			if ( '' === $hash && ! empty( $query['h'] ) && is_string( $query['h'] ) && preg_match( '/^[0-9a-f]{6,20}$/i', $query['h'] ) ) {
				$hash = $query['h'];
			}

			return array( 'type' => 'vimeo', 'id' => $m[1], 'hash' => strtolower( $hash ) );
		}

		return array();
	}

	/**
	 * Consent a background video waits for.
	 *
	 * Order: this plugin's consent module when it runs the site; otherwise
	 * CookieYes when it is detected (plugin cookie-law-info, or a site that
	 * loads CookieYes through GTM says so with `px_content_video_cmp`);
	 * otherwise nothing and the video starts right away. A library file is
	 * served from the site itself and never waits.
	 *
	 * @param array $video Video data (see item_video()).
	 * @return array Empty, or:
	 *     cmp      string px|cookieyes (who answers in the browser)
	 *     category string Consent category (px: category of the service,
	 *                     cookieyes: category slug, default advertisement)
	 *     service  string px only - service id (youtube / vimeo)
	 */
	public static function video_consent( $video ) {
		$consent = array();

		if ( ! empty( $video['type'] ) && 'file' !== $video['type'] ) {
			$service = 'youtube' === $video['type'] ? 'youtube' : 'vimeo';

			if ( class_exists( 'PX_Consent' ) && PX_Consent::active() ) {
				$def     = class_exists( 'PX_Consent_Services' ) ? PX_Consent_Services::get( $service ) : array();
				$consent = array(
					'cmp'      => 'px',
					'service'  => $service,
					'category' => ! empty( $def['category'] ) ? (string) $def['category'] : 'marketing',
				);
			} else {
				/**
				 * Filters the external consent tool the video waits for when
				 * this plugin's consent module is off.
				 *
				 * Detected: CookieYes as a WordPress plugin (cookie-law-info).
				 * A site that loads CookieYes from GTM returns 'cookieyes' here.
				 *
				 * @param string $cmp   'cookieyes' or '' (none).
				 * @param array  $video Video data.
				 */
				$cmp = (string) apply_filters( 'px_content_video_cmp', defined( 'CLI_VERSION' ) ? 'cookieyes' : '', $video );

				if ( 'cookieyes' === $cmp ) {
					/**
					 * Filters the CookieYes category a background video waits for.
					 *
					 * @param string $category Category slug, default 'advertisement'.
					 * @param array  $video    Video data.
					 */
					$consent = array(
						'cmp'      => 'cookieyes',
						'service'  => $service,
						'category' => (string) apply_filters( 'px_content_video_cmp_category', 'advertisement', $video ),
					);
				}
			}
		}

		/**
		 * Filters the consent a background video waits for.
		 *
		 * Return an empty array to start the video without asking.
		 *
		 * @param array $consent Empty, or 'cmp', 'category' and 'service'.
		 * @param array $video   Video data.
		 */
		return (array) apply_filters( 'px_content_video_consent', $consent, $video );
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
	 *     image_sizes  string `sizes` attribute of the image ('' = WordPress
	 *                         default; a full-bleed banner wants '100vw').
	 *     eager        bool   Banner above the fold: no lazy loading and
	 *                         fetchpriority=high on the image (LCP).
	 *     priority     bool   fetchpriority=high (default: same as eager).
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
			'image_sizes' => '',
			'eager'       => false,
			'priority'    => null,
		) );

		// fetchpriority=high follows `eager` unless said otherwise (a second
		// carousel slide is eager but must not compete with the first).
		$args['priority'] = null === $args['priority'] ? (bool) $args['eager'] : (bool) $args['priority'];

		$layout = $args['layout'] && isset( self::layouts()[ $args['layout'] ] ) ? $args['layout'] : $banner['layout'];
		$def    = self::layout( $layout );

		$banner['layout'] = $def['id'];

		// A layout without image support must not draw one even when the
		// item has a featured image (a project may reuse the same item).
		if ( ! in_array( 'image', (array) $def['supports'], true ) ) {
			$banner['image_id'] = 0;
		}

		/**
		 * Filters the handle of the script that plays background videos.
		 *
		 * Same contract as the stylesheet: the theme registers the file,
		 * the plugin asks for it only on pages that have a video banner.
		 *
		 * @param string $handle Script handle ('' = none).
		 */
		$video_handle = (string) apply_filters( 'px_content_video_script_handle', 'px-banner-video' );

		// No video without a theme that can play it (px-shop-theme < 0.6.0
		// has no px-banner-video): an empty wrapper would be dead markup.
		if ( ! in_array( 'video', (array) $def['supports'], true )
			|| '' === $video_handle || ! wp_script_is( $video_handle, 'registered' ) ) {
			$banner['video'] = array();
		}

		if ( ! in_array( 'link', (array) $def['supports'], true ) ) {
			$banner['link'] = '';
		}

		if ( ! empty( $banner['video'] ) ) {
			$banner['video']['consent'] = self::video_consent( $banner['video'] );
		}

		$args['layout']    = $def['id'];
		$args['supports']  = (array) $def['supports'];
		$args['classes']   = self::classes( $banner, $args );
		$args['text_html'] = self::content_html( $banner['text'] );

		$args['heading_tag'] = preg_match( '/^h[1-6]$/', (string) $args['heading_tag'] ) ? $args['heading_tag'] : 'h2';
		$args['image_attr']  = self::image_attr( $args );

		self::enqueue_style();

		if ( ! empty( $banner['video'] ) ) {
			self::enqueue_handle( $video_handle );
		}

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
	 * Attributes of the banner image, shared by every layout template.
	 *
	 * @param array $args Rendering arguments.
	 * @return array
	 */
	private static function image_attr( $args ) {
		$attr = array(
			'loading'  => $args['eager'] ? 'eager' : 'lazy',
			'decoding' => 'async',
			'alt'      => '',
		);

		// The first banner of a page is usually its LCP element.
		if ( $args['eager'] && ! empty( $args['priority'] ) ) {
			$attr['fetchpriority'] = 'high';
		}

		if ( '' !== trim( (string) $args['image_sizes'] ) ) {
			$attr['sizes'] = (string) $args['image_sizes'];
		}

		return $attr;
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

		if ( ! empty( $banner['video'] ) ) {
			$classes[] = 'px-banner--has-video';
		}

		if ( ! empty( $banner['link'] ) ) {
			$classes[] = 'px-banner--linked';
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
	 *     carousel bool   Several items as slides of one carousel (Swiper
	 *                     markup + controls). One item stays a plain banner.
	 *     label    string Accessible name of the carousel region.
	 *     group_class string Extra classes on the .px-banners wrapper
	 *                     (`class` goes on every banner).
	 *
	 * Only the first banner keeps an h1 (the rest get h2) - a page has one
	 * main heading. In a carousel only the first slide is loaded eagerly.
	 *
	 * @return string
	 */
	public static function render_group( $args = array() ) {
		$args = wp_parse_args( $args, array(
			'item'     => 0,
			'category' => '',
			'limit'    => -1,
			'columns'  => 0,
			'wrap'     => true,
			'carousel'    => false,
			'label'       => '',
			'group_class' => '',
		) );

		$items = $args['item']
			? array_filter( array( self::get_item( $args['item'] ) ) )
			: self::get_items( $args['category'], (int) $args['limit'] );

		if ( ! $items ) {
			return '';
		}

		/**
		 * Filters the handle of the carousel assets (style and script).
		 *
		 * @param string $handle Handle ('' = none).
		 */
		$carousel_handle = (string) apply_filters( 'px_content_carousel_handle', 'px-banner-carousel' );

		$items    = array_values( $items );
		// Without a theme that starts the carousel (px-shop-theme < 0.6.0)
		// the group stays a plain stack of banners, not a dead slider.
		$carousel = ! empty( $args['carousel'] ) && count( $items ) > 1
			&& '' !== $carousel_handle && wp_script_is( $carousel_handle, 'registered' );
		$banners  = array();

		foreach ( $items as $index => $item ) {
			$item_args = $args;

			if ( $index > 0 ) {
				if ( isset( $item_args['heading_tag'] ) && 'h1' === $item_args['heading_tag'] ) {
					$item_args['heading_tag'] = 'h2';
				}

				// Only the first banner is the LCP candidate. The second slide
				// of a carousel is still loaded right away (without priority),
				// so the first transition does not show an empty banner.
				$item_args['eager']    = $carousel && 1 === $index && ! empty( $args['eager'] );
				$item_args['priority'] = false;
			}

			$html = self::render( $item, $item_args );

			if ( '' !== trim( $html ) ) {
				$banners[] = $html;
			}
		}

		if ( ! $banners ) {
			return '';
		}

		// Items that rendered nothing (missing template) do not count.
		if ( $carousel && count( $banners ) > 1 ) {
			return self::carousel_html( $banners, $args, $carousel_handle );
		}

		$html    = implode( '', $banners );
		$columns = max( 0, (int) $args['columns'] );

		if ( ! $args['wrap'] ) {
			return $html;
		}

		$classes = 'px-banners' . self::group_class( $args );

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
	 * Extra wrapper classes, with a leading space ('' when none).
	 *
	 * @param array $args Group arguments.
	 * @return string
	 */
	private static function group_class( $args ) {
		$classes = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( (string) $args['group_class'] ) ) ) );

		return $classes ? ' ' . implode( ' ', $classes ) : '';
	}

	/**
	 * Carousel markup around rendered banners.
	 *
	 * Swiper classes are in the markup, so the theme only has to start it.
	 * Without JavaScript the first slide is what the visitor sees (the rest
	 * sit beside it, clipped by overflow) and the controls stay hidden.
	 *
	 * @param string[] $banners Rendered banners.
	 * @param array    $args    Group arguments.
	 * @param string   $handle  Carousel asset handle.
	 * @return string
	 */
	private static function carousel_html( $banners, $args, $handle ) {
		$defaults = array(
			'region' => '' !== (string) $args['label'] ? (string) $args['label'] : __( 'Banners', 'px-shop-core' ),
			'role'   => __( 'carousel', 'px-shop-core' ),
			'slide'  => __( 'slide', 'px-shop-core' ),
			'prev'   => __( 'Previous slide', 'px-shop-core' ),
			'next'   => __( 'Next slide', 'px-shop-core' ),
			'pause'  => __( 'Pause slide show', 'px-shop-core' ),
			'play'   => __( 'Play slide show', 'px-shop-core' ),
			'dots'   => __( 'Choose slide', 'px-shop-core' ),
		);

		// A filter that returns only the keys it changes keeps the rest.
		/**
		 * Filters the carousel texts (control labels for screen readers).
		 *
		 * @param array $labels Label texts.
		 * @param array $args   Group arguments.
		 */
		$labels = wp_parse_args( (array) apply_filters( 'px_content_carousel_labels', $defaults, $args ), $defaults );

		self::enqueue_handle( $handle );

		$slides = '';
		$total  = count( $banners );

		foreach ( array_values( $banners ) as $index => $banner ) {
			$slides .= sprintf(
				'<div class="px-banners__slide swiper-slide" role="group" aria-roledescription="%1$s" aria-label="%2$s">%3$s</div>',
				esc_attr( $labels['slide'] ),
				/* translators: 1: slide number, 2: number of slides */
				esc_attr( sprintf( __( '%1$d of %2$d', 'px-shop-core' ), $index + 1, $total ) ),
				$banner
			);
		}

		$arrow = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="%s"/></svg>';

		// Controls come first in the DOM: the pause button should be reachable
		// before the moving content (WCAG 2.2.2, technique G4).
		$controls = sprintf(
			'<div class="px-banners__controls" hidden>'
			. '<button type="button" class="px-banners__btn px-banners__prev" aria-label="%1$s">%5$s</button>'
			. '<div class="px-banners__dots" role="group" aria-label="%3$s"></div>'
			. '<button type="button" class="px-banners__btn px-banners__next" aria-label="%2$s">%6$s</button>'
			. '<button type="button" class="px-banners__btn px-banners__toggle" aria-label="%4$s" data-label-pause="%4$s" data-label-play="%7$s">'
			. '<svg class="px-banners__icon-pause" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>'
			. '<svg class="px-banners__icon-play" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M8 5.5v13a1 1 0 0 0 1.52.85l10.4-6.5a1 1 0 0 0 0-1.7L9.52 4.65A1 1 0 0 0 8 5.5z"/></svg>'
			. '</button>'
			. '</div>',
			esc_attr( $labels['prev'] ),
			esc_attr( $labels['next'] ),
			esc_attr( $labels['dots'] ),
			esc_attr( $labels['pause'] ),
			sprintf( $arrow, 'M15 18l-6-6 6-6' ),
			sprintf( $arrow, 'M9 18l6-6-6-6' ),
			esc_attr( $labels['play'] )
		);

		$classes = 'px-banners px-banners--carousel swiper' . self::group_class( $args );

		return sprintf(
			'<div class="%1$s" data-px-banners-carousel role="region" aria-roledescription="%2$s" aria-label="%3$s">%4$s<div class="px-banners__track swiper-wrapper">%5$s</div></div>',
			esc_attr( $classes ),
			esc_attr( $labels['role'] ),
			esc_attr( $labels['region'] ),
			$controls,
			$slides
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
		self::enqueue_handle( apply_filters( 'px_content_style_handle', 'px-banner' ) );
	}

	/**
	 * Enqueues a style and/or script the theme registered under a handle.
	 *
	 * Banners are rendered after wp_head, so a style asked for here lands in
	 * the footer - a theme that knows a page has banners enqueues the handle
	 * itself, and this call then does nothing.
	 *
	 * @param string $handle Handle ('' = nothing).
	 */
	private static function enqueue_handle( $handle ) {
		$handle = (string) $handle;

		if ( '' === $handle ) {
			return;
		}

		if ( wp_style_is( $handle, 'registered' ) && ! wp_style_is( $handle, 'enqueued' ) ) {
			wp_enqueue_style( $handle );
		}

		if ( wp_script_is( $handle, 'registered' ) && ! wp_script_is( $handle, 'enqueued' ) ) {
			wp_enqueue_script( $handle );
		}
	}

	/* ------------------------------ Shortcode ---------------------------- */

	/**
	 * [px_banner id="12"], [px_banner slug="spring-sale"],
	 * [px_banner category="homepage" columns="3" layout="background"],
	 * [px_banner category="homepage" carousel="1"]
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
			'carousel' => '',
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
			'carousel'    => in_array( strtolower( (string) $atts['carousel'] ), array( '1', 'yes', 'true' ), true ),
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
