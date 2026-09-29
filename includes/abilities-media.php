<?php
/**
 * WordPress Abilities API registration for product media.
 *
 * Lets an MCP client read a product's featured image and gallery, replace or
 * extend them (from existing attachments or from a URL that is sideloaded into
 * the media library), and fix the alt text of a single attachment.
 *
 * Deliberate boundaries:
 *   - Nothing here deletes an attachment or detaches a file. "replace" only
 *     rewrites the product's image IDs; the old attachments stay in the library.
 *   - Every input is resolved to an attachment ID *before* the product is
 *     touched. A call that fails halfway leaves the product exactly as it was
 *     and reports which inputs resolved and which did not.
 *   - A URL is only fetched when the caller has `upload_files`; it is checked
 *     for a supported extension, then HEAD-checked (MIME + size) before the
 *     download and size-checked again afterwards.
 *   - Sideloaded attachments record their source in PX_SHOP_CORE_SOURCE_URL_META,
 *     which makes a repeated call with the same URL reuse the existing
 *     attachment instead of piling up duplicates.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_categories_init', 'px_shop_core_register_ability_categories' );
add_action( 'wp_abilities_api_init', 'px_shop_core_register_abilities' );

/**
 * Register the product media ability category.
 */
function px_shop_core_register_ability_categories() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}
	if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'px-shop-media' ) ) {
		return;
	}

	wp_register_ability_category(
		'px-shop-media',
		array(
			'label'       => __( 'PX Shop - media produktu', 'px-shop-core' ),
			'description' => __( 'Abilities for WooCommerce product media: featured image, gallery and attachment alt texts.', 'px-shop-core' ),
		)
	);
}

/* ---------------------------------------------------------------------------
 * Schema fragments
 * ------------------------------------------------------------------------ */

/**
 * Schema of a single image payload (see px_shop_core_ability_image_payload()).
 *
 * @return array<string,mixed>
 */
function px_shop_core_ability_image_properties() {
	return array(
		'id'        => array(
			'type'        => 'integer',
			'description' => 'Attachment ID. 0 means "not set".',
		),
		'url'       => array( 'type' => 'string' ),
		'thumbnail' => array(
			'type'        => 'string',
			'description' => 'URL of the woocommerce_thumbnail size, falls back to the full URL.',
		),
		'alt'       => array(
			'type'        => 'string',
			'description' => 'Alt text (meta _wp_attachment_image_alt).',
		),
		'title'     => array( 'type' => 'string' ),
		'caption'   => array( 'type' => 'string' ),
		'mime_type' => array( 'type' => 'string' ),
		'width'     => array( 'type' => 'integer' ),
		'height'    => array( 'type' => 'integer' ),
	);
}

/**
 * Image payload for an attachment ID.
 *
 * @param int $attachment_id Attachment ID, 0 for an empty placeholder.
 * @return array<string,mixed>
 */
function px_shop_core_ability_image_payload( $attachment_id ) {
	$attachment_id = absint( $attachment_id );
	$empty         = array(
		'id'        => 0,
		'url'       => '',
		'thumbnail' => '',
		'alt'       => '',
		'title'     => '',
		'caption'   => '',
		'mime_type' => '',
		'width'     => 0,
		'height'    => 0,
	);

	if ( ! $attachment_id ) {
		return $empty;
	}

	$post = get_post( $attachment_id );
	if ( ! $post || 'attachment' !== $post->post_type ) {
		return $empty;
	}

	$url   = wp_get_attachment_url( $attachment_id );
	$thumb = wp_get_attachment_image_url( $attachment_id, 'woocommerce_thumbnail' );
	$alt   = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
	$meta  = wp_get_attachment_metadata( $attachment_id );
	$meta  = is_array( $meta ) ? $meta : array();

	return array(
		'id'        => (int) $attachment_id,
		'url'       => $url ? (string) $url : '',
		'thumbnail' => $thumb ? (string) $thumb : ( $url ? (string) $url : '' ),
		'alt'       => is_string( $alt ) ? $alt : '',
		'title'     => (string) $post->post_title,
		'caption'   => (string) $post->post_excerpt,
		'mime_type' => (string) $post->post_mime_type,
		'width'     => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
		'height'    => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
	);
}

/**
 * Full media payload of a product: featured image + gallery.
 *
 * @param WC_Product $product Product.
 * @return array<string,mixed>
 */
function px_shop_core_ability_product_images_payload( $product ) {
	$gallery = array();
	foreach ( $product->get_gallery_image_ids() as $image_id ) {
		$gallery[] = px_shop_core_ability_image_payload( $image_id );
	}

	return array(
		'product_id'    => (int) $product->get_id(),
		'product_title' => (string) $product->get_name(),
		'featured'      => px_shop_core_ability_image_payload( $product->get_image_id() ),
		'gallery'       => $gallery,
		'gallery_count' => count( $gallery ),
	);
}

/* ---------------------------------------------------------------------------
 * Input helpers
 * ------------------------------------------------------------------------ */

/**
 * Load and validate the product from ability input.
 *
 * @param mixed $raw Raw product_id input.
 * @return WC_Product|WP_Error
 */
function px_shop_core_ability_get_product( $raw ) {
	$product_id = absint( $raw );
	$product    = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;

	$not_found = new WP_Error(
		'px_shop_core_product_not_found',
		__( 'No product found for the given product_id.', 'px-shop-core' ),
		array( 'status' => 404 )
	);

	if ( ! $product ) {
		return $not_found;
	}

	// A variation carries its own image but no gallery, and its parent owns the
	// media the shop actually renders - editing it through here would be a trap.
	if ( $product->is_type( 'variation' ) ) {
		return new WP_Error(
			'px_shop_core_variation_unsupported',
			sprintf(
				/* translators: %d: parent product ID. */
				__( 'Product variations are not supported. Use the parent product (ID %d).', 'px-shop-core' ),
				(int) $product->get_parent_id()
			),
			array(
				'status'    => 400,
				'parent_id' => (int) $product->get_parent_id(),
			)
		);
	}

	// An unpublished product is a 404 for anyone who cannot read it.
	if ( 'publish' !== $product->get_status() && ! current_user_can( 'read_post', $product->get_id() ) ) {
		return $not_found;
	}

	return $product;
}

/**
 * File extensions accepted for sideloaded product images.
 *
 * Kept in sync with the MIME list below and pushed into core's
 * `image_sideload_extensions` filter, whose default list has no `avif`.
 *
 * @return string[]
 */
function px_shop_core_ability_allowed_extensions() {
	/**
	 * Filters the file extensions accepted by the product media abilities.
	 *
	 * @param string[] $extensions Allowed extensions, lowercase, without a dot.
	 */
	return (array) apply_filters(
		'px_shop_core_ability_allowed_extensions',
		array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif' )
	);
}

/**
 * Image MIME types accepted for product media. SVG is deliberately excluded.
 *
 * @return string[]
 */
function px_shop_core_ability_allowed_mimes() {
	/**
	 * Filters the MIME types accepted by the product media abilities.
	 *
	 * @param string[] $mimes Allowed MIME types.
	 */
	return (array) apply_filters(
		'px_shop_core_ability_allowed_mimes',
		array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' )
	);
}

/**
 * Maximum size of a sideloaded image in bytes.
 *
 * @return int
 */
function px_shop_core_ability_max_image_bytes() {
	/**
	 * Filters the maximum byte size of an image downloaded by the media abilities.
	 *
	 * @param int $bytes Maximum size in bytes.
	 */
	return (int) apply_filters( 'px_shop_core_ability_max_image_bytes', 10 * MB_IN_BYTES );
}

/**
 * Whether a raw image input is an upload request (a URL) rather than an ID.
 *
 * Intentionally permissive: it answers "does this input want a file fetched?",
 * which is the question the permission callback asks before requiring
 * `upload_files`. The strict check happens in the classifier below.
 *
 * @param mixed $value Raw value.
 * @return bool
 */
function px_shop_core_ability_is_url_input( $value ) {
	return is_string( $value ) && '' !== trim( $value ) && ! ctype_digit( trim( $value ) );
}

/**
 * Classify one image input as an attachment ID or a usable image URL.
 *
 * @param mixed $value Raw value.
 * @return string|WP_Error 'id', 'url', or an error explaining what is wrong.
 */
function px_shop_core_ability_classify_image_input( $value ) {
	if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( trim( (string) $value ) ) ) ) {
		return 'id';
	}

	if ( ! is_string( $value ) ) {
		return new WP_Error(
			'px_shop_core_invalid_image_input',
			__( 'An image must be given as an attachment ID or as an http(s) image URL.', 'px-shop-core' ),
			array( 'status' => 400 )
		);
	}

	$url   = trim( $value );
	$parts = wp_parse_url( $url );

	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return new WP_Error(
			'px_shop_core_invalid_url',
			sprintf(
				/* translators: %s: the rejected value. */
				__( '"%s" is neither an attachment ID nor an absolute http(s) URL. Relative paths and data URIs are not supported.', 'px-shop-core' ),
				$url
			),
			array( 'status' => 400 )
		);
	}

	$extensions = array_map( 'preg_quote', px_shop_core_ability_allowed_extensions() );
	if ( ! preg_match( '/[^\?]+\.(' . implode( '|', $extensions ) . ')\b/i', $url ) ) {
		return new WP_Error(
			'px_shop_core_unsupported_extension',
			sprintf(
				/* translators: 1: the rejected URL, 2: list of allowed extensions. */
				__( 'The URL %1$s has no supported image extension. Allowed: %2$s.', 'px-shop-core' ),
				$url,
				implode( ', ', px_shop_core_ability_allowed_extensions() )
			),
			array( 'status' => 400 )
		);
	}

	return 'url';
}

/**
 * Resolve one image input (attachment ID or URL) into an attachment ID.
 *
 * A URL is sideloaded into the media library and attached to the product. The
 * same URL is only downloaded once per site: subsequent calls reuse the
 * attachment recorded through PX_SHOP_CORE_SOURCE_URL_META.
 *
 * @param mixed  $value      Attachment ID or image URL.
 * @param int    $product_id Product the image is attached to.
 * @param string $alt        Alt text applied to newly downloaded images.
 * @param array  $created    Collects the IDs of attachments created here (by reference).
 * @return int|WP_Error Attachment ID.
 */
function px_shop_core_ability_resolve_image( $value, $product_id, $alt, array &$created ) {
	$kind = px_shop_core_ability_classify_image_input( $value );
	if ( is_wp_error( $kind ) ) {
		return $kind;
	}

	if ( 'id' === $kind ) {
		$attachment_id = absint( $value );
		$post          = $attachment_id ? get_post( $attachment_id ) : null;

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error(
				'px_shop_core_attachment_not_found',
				sprintf(
					/* translators: %d: attachment ID. */
					__( 'Attachment %d does not exist.', 'px-shop-core' ),
					$attachment_id
				),
				array( 'status' => 404 )
			);
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error(
				'px_shop_core_not_an_image',
				sprintf(
					/* translators: %d: attachment ID. */
					__( 'Attachment %d is not an image.', 'px-shop-core' ),
					$attachment_id
				),
				array( 'status' => 400 )
			);
		}

		return $attachment_id;
	}

	$url = esc_url_raw( trim( (string) $value ) );
	if ( ! $url || ! wp_http_validate_url( $url ) ) {
		return new WP_Error(
			'px_shop_core_invalid_url',
			__( 'The image URL is not a valid, publicly reachable http(s) URL.', 'px-shop-core' ),
			array( 'status' => 400 )
		);
	}

	// Already downloaded before? Reuse it - keeps repeated calls cheap and
	// stops a retry from filling the library with copies.
	$existing = get_posts(
		array(
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'meta_key'         => PX_SHOP_CORE_SOURCE_URL_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- exact lookup on a small meta set.
			'meta_value'       => $url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- exact match by design.
			'suppress_filters' => false,
		)
	);
	if ( $existing ) {
		return (int) $existing[0];
	}

	$precheck = px_shop_core_ability_precheck_remote_image( $url );
	if ( is_wp_error( $precheck ) ) {
		return $precheck;
	}

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	// Core's default sideload extension list has no avif; widen it for this
	// call only, so the rest of the site keeps its own policy.
	$widen = static function ( $extensions ) {
		return array_values( array_unique( array_merge( (array) $extensions, px_shop_core_ability_allowed_extensions() ) ) );
	};
	add_filter( 'image_sideload_extensions', $widen );
	$attachment_id = media_sideload_image( $url, $product_id, '' !== $alt ? $alt : null, 'id' );
	remove_filter( 'image_sideload_extensions', $widen );

	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	// Record provenance and ownership first: from here on the attachment exists,
	// and every later failure has to hand its ID back rather than orphan it.
	$attachment_id = (int) $attachment_id;
	update_post_meta( $attachment_id, PX_SHOP_CORE_SOURCE_URL_META, $url );
	$created[] = $attachment_id;

	if ( '' !== $alt ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
	}

	if ( ! wp_attachment_is_image( $attachment_id ) ) {
		return new WP_Error(
			'px_shop_core_not_an_image',
			__( 'The downloaded file is not an image.', 'px-shop-core' ),
			array(
				'status'        => 400,
				'attachment_id' => $attachment_id,
			)
		);
	}

	// A server that hides Content-Length gets caught here instead.
	$file = get_attached_file( $attachment_id );
	$max  = px_shop_core_ability_max_image_bytes();
	if ( $file && file_exists( $file ) ) {
		$size = (int) filesize( $file );
		if ( $size > $max ) {
			return new WP_Error(
				'px_shop_core_image_too_large',
				sprintf(
					/* translators: 1: file size, 2: maximum allowed size, 3: attachment ID. */
					__( 'The downloaded image is too large (%1$s, limit %2$s). It stays in the media library as attachment %3$d - delete it there if you do not want it.', 'px-shop-core' ),
					size_format( $size ),
					size_format( $max ),
					$attachment_id
				),
				array(
					'status'        => 400,
					'attachment_id' => $attachment_id,
				)
			);
		}
	}

	return $attachment_id;
}

/**
 * HEAD-check a remote image before downloading it: MIME type and size.
 *
 * Servers that do not answer HEAD, or omit the headers, pass this check - the
 * post-download size and type checks still apply.
 *
 * @param string $url Image URL.
 * @return true|WP_Error
 */
function px_shop_core_ability_precheck_remote_image( $url ) {
	$response = wp_safe_remote_head(
		$url,
		array(
			'timeout'     => 10,
			'redirection' => 3,
		)
	);

	if ( is_wp_error( $response ) ) {
		return true;
	}

	$type = strtok( (string) wp_remote_retrieve_header( $response, 'content-type' ), ';' );
	$type = strtolower( trim( (string) $type ) );
	if ( $type && ! in_array( $type, px_shop_core_ability_allowed_mimes(), true ) ) {
		return new WP_Error(
			'px_shop_core_unsupported_mime',
			sprintf(
				/* translators: 1: MIME type of the remote file, 2: list of allowed MIME types. */
				__( 'The URL returns %1$s. Allowed image types: %2$s.', 'px-shop-core' ),
				$type,
				implode( ', ', px_shop_core_ability_allowed_mimes() )
			),
			array( 'status' => 400 )
		);
	}

	$length = (int) wp_remote_retrieve_header( $response, 'content-length' );
	$max    = px_shop_core_ability_max_image_bytes();
	if ( $length > 0 && $length > $max ) {
		return new WP_Error(
			'px_shop_core_image_too_large',
			sprintf(
				/* translators: 1: file size, 2: maximum allowed size. */
				__( 'The image is too large (%1$s, limit %2$s).', 'px-shop-core' ),
				size_format( $length ),
				size_format( $max )
			),
			array( 'status' => 400 )
		);
	}

	return true;
}

/* ---------------------------------------------------------------------------
 * Registration
 * ------------------------------------------------------------------------ */

/**
 * Register the product media abilities.
 */
function px_shop_core_register_abilities() {
	if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wc_get_product' ) ) {
		return;
	}

	$image_schema = array(
		'type'       => 'object',
		'properties' => px_shop_core_ability_image_properties(),
	);

	$product_images_schema = array(
		'type'       => 'object',
		'properties' => array(
			'product_id'    => array( 'type' => 'integer' ),
			'product_title' => array( 'type' => 'string' ),
			'featured'      => $image_schema,
			'gallery'       => array(
				'type'  => 'array',
				'items' => $image_schema,
			),
			'gallery_count' => array( 'type' => 'integer' ),
		),
	);

	/* ------------------------- 1. get product images --------------------- */

	wp_register_ability(
		'px-shop-core/get-product-images',
		array(
			'label'               => __( 'Product images', 'px-shop-core' ),
			'description'         => __( 'Returns the featured image and the gallery of a WooCommerce product, including attachment ID, URL, alt text and title for every image. Product variations are not supported - ask for the parent product.', 'px-shop-core' ),
			'category'            => 'px-shop-media',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'product_id' => array(
						'type'        => 'integer',
						'description' => 'WooCommerce product ID (not a variation ID).',
						'minimum'     => 1,
					),
				),
				'required'   => array( 'product_id' ),
			),
			'output_schema'       => $product_images_schema,
			'permission_callback' => static function ( $input = array() ) {
				return current_user_can( 'read' );
			},
			'execute_callback'    => 'px_shop_core_ability_get_product_images',
			'meta'                => array(
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
					'type'   => 'tool',
				),
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	/* ------------------------- 2. set product images --------------------- */

	wp_register_ability(
		'px-shop-core/set-product-images',
		array(
			'label'               => __( 'Set product images', 'px-shop-core' ),
			'description'         => __( 'Sets the featured image and/or the gallery of a WooCommerce product. Each image is either an existing attachment ID or an image URL, which is downloaded into the media library and attached to the product. In "replace" mode the product stops pointing at its previous images - the attachments themselves are never deleted. All inputs are resolved before anything is written: if one fails, the product is left untouched.', 'px-shop-core' ),
			'category'            => 'px-shop-media',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'product_id' => array(
						'type'        => 'integer',
						'description' => 'WooCommerce product ID (not a variation ID).',
						'minimum'     => 1,
					),
					'featured'   => array(
						'type'        => array( 'integer', 'string' ),
						'description' => 'Featured image: attachment ID or image URL. Omit to keep the current one, pass 0 to unset it.',
					),
					'gallery'    => array(
						'type'        => 'array',
						'description' => 'Gallery images: attachment IDs or image URLs, in the desired order. Max 10 per call - run several calls with mode "append" for more.',
						'items'       => array( 'type' => array( 'integer', 'string' ) ),
						'maxItems'    => 10,
					),
					'mode'       => array(
						'type'        => 'string',
						'description' => 'How the gallery input is applied: replace = the given list becomes the gallery, append = the images are added after the current ones. The featured image is always replaced when provided.',
						'enum'        => array( 'replace', 'append' ),
						'default'     => 'replace',
					),
					'alt'        => array(
						'type'        => 'string',
						'description' => 'Alt text for images newly downloaded from a URL. WordPress also uses it as the attachment title, so write it as a human-readable image description. Existing attachments are not touched - use px-shop-core/set-attachment-alt for those.',
					),
				),
				'required'   => array( 'product_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array_merge(
					$product_images_schema['properties'],
					array(
						'mode'                   => array( 'type' => 'string' ),
						'created_attachment_ids' => array(
							'type'        => 'array',
							'description' => 'Attachments newly created by this call (downloaded from a URL).',
							'items'       => array( 'type' => 'integer' ),
						),
					)
				),
			),
			'permission_callback' => 'px_shop_core_ability_can_set_product_images',
			'execute_callback'    => 'px_shop_core_ability_set_product_images',
			'meta'                => array(
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
					'type'   => 'tool',
				),
				'annotations'  => array(
					'readonly'    => false,
					// "replace" drops the product's previous image references and
					// "append" grows the gallery on every run, so neither is a
					// safe automatic retry.
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	/* -------------------------- 3. set attachment alt -------------------- */

	wp_register_ability(
		'px-shop-core/set-attachment-alt',
		array(
			'label'               => __( 'Set attachment alt text', 'px-shop-core' ),
			'description'         => __( 'Sets the alt text (and optionally the title and caption) of a media library attachment. Used to make product images accessible (WCAG 1.1.1).', 'px-shop-core' ),
			'category'            => 'px-shop-media',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'attachment_id' => array(
						'type'        => 'integer',
						'description' => 'Attachment ID.',
						'minimum'     => 1,
					),
					'alt'           => array(
						'type'        => 'string',
						'description' => 'Alt text. An empty string is allowed for decorative images.',
						'maxLength'   => 500,
					),
					'title'         => array(
						'type'        => 'string',
						'description' => 'Attachment title (optional).',
					),
					'caption'       => array(
						'type'        => 'string',
						'description' => 'Attachment caption (optional).',
					),
				),
				'required'   => array( 'attachment_id', 'alt' ),
			),
			'output_schema'       => $image_schema,
			'permission_callback' => static function ( $input = array() ) {
				$attachment_id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
				return $attachment_id && current_user_can( 'edit_post', $attachment_id );
			},
			'execute_callback'    => 'px_shop_core_ability_set_attachment_alt',
			'meta'                => array(
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
					'type'   => 'tool',
				),
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);
}

/* ---------------------------------------------------------------------------
 * Callbacks
 * ------------------------------------------------------------------------ */

/**
 * Permission check for px-shop-core/set-product-images.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function px_shop_core_ability_can_set_product_images( $input = array() ) {
	$input      = is_array( $input ) ? $input : array();
	$product_id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;

	if ( ! $product_id || ! current_user_can( 'edit_post', $product_id ) ) {
		return false;
	}

	$values = array();
	if ( isset( $input['featured'] ) ) {
		$values[] = $input['featured'];
	}
	if ( isset( $input['gallery'] ) && is_array( $input['gallery'] ) ) {
		$values = array_merge( $values, $input['gallery'] );
	}

	foreach ( $values as $value ) {
		if ( px_shop_core_ability_is_url_input( $value ) ) {
			// Downloading a remote file into the library is an upload.
			return current_user_can( 'upload_files' );
		}
	}

	return true;
}

/**
 * px-shop-core/get-product-images
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|WP_Error
 */
function px_shop_core_ability_get_product_images( $input = array() ) {
	$input   = is_array( $input ) ? $input : array();
	$product = px_shop_core_ability_get_product( isset( $input['product_id'] ) ? $input['product_id'] : 0 );
	if ( is_wp_error( $product ) ) {
		return $product;
	}

	return px_shop_core_ability_product_images_payload( $product );
}

/**
 * px-shop-core/set-product-images
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|WP_Error
 */
function px_shop_core_ability_set_product_images( $input = array() ) {
	$input   = is_array( $input ) ? $input : array();
	$product = px_shop_core_ability_get_product( isset( $input['product_id'] ) ? $input['product_id'] : 0 );
	if ( is_wp_error( $product ) ) {
		return $product;
	}

	$has_featured = array_key_exists( 'featured', $input );
	$has_gallery  = array_key_exists( 'gallery', $input ) && is_array( $input['gallery'] );
	if ( ! $has_featured && ! $has_gallery ) {
		return new WP_Error(
			'px_shop_core_nothing_to_set',
			__( 'Provide at least "featured" or "gallery".', 'px-shop-core' ),
			array( 'status' => 400 )
		);
	}

	$mode    = isset( $input['mode'] ) ? sanitize_key( $input['mode'] ) : 'replace';
	$mode    = in_array( $mode, array( 'replace', 'append' ), true ) ? $mode : 'replace';
	$alt     = isset( $input['alt'] ) ? sanitize_text_field( (string) $input['alt'] ) : '';
	$created = array();
	$failed  = array();

	/*
	 * Resolve everything first. Downloads can still happen before a later input
	 * fails - those attachments are reported back - but the product is only
	 * written once every input resolved.
	 */
	$featured_id    = null;
	$unset_featured = false;
	if ( $has_featured ) {
		$raw_featured = $input['featured'];

		// An explicit empty value / 0 unsets the featured image. The attachment
		// itself stays in the media library - nothing is deleted.
		if ( ! px_shop_core_ability_is_url_input( $raw_featured ) && ! absint( $raw_featured ) ) {
			$unset_featured = true;
		} else {
			$resolved = px_shop_core_ability_resolve_image( $raw_featured, $product->get_id(), $alt, $created );
			if ( is_wp_error( $resolved ) ) {
				$failed[] = px_shop_core_ability_failure( 'featured', $raw_featured, $resolved );
			} else {
				$featured_id = (int) $resolved;
			}
		}
	}

	$gallery_ids = array();
	if ( $has_gallery ) {
		foreach ( $input['gallery'] as $index => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}
			$resolved = px_shop_core_ability_resolve_image( $value, $product->get_id(), $alt, $created );
			if ( is_wp_error( $resolved ) ) {
				$failed[] = px_shop_core_ability_failure( 'gallery[' . (int) $index . ']', $value, $resolved );
				continue;
			}
			$gallery_ids[] = (int) $resolved;
		}
	}

	if ( $failed ) {
		$resolved_ids = array_values( array_filter( array_merge( array( $featured_id ), $gallery_ids ) ) );
		return new WP_Error(
			'px_shop_core_image_resolve_failed',
			sprintf(
				/* translators: %d: number of inputs that could not be resolved. */
				_n(
					'%d image input could not be resolved; the product was not changed.',
					'%d image inputs could not be resolved; the product was not changed.',
					count( $failed ),
					'px-shop-core'
				),
				count( $failed )
			),
			array(
				'status'                 => 400,
				'resolved_ids'           => $resolved_ids,
				'failed'                 => $failed,
				'created_attachment_ids' => array_values( array_map( 'intval', $created ) ),
			)
		);
	}

	if ( $unset_featured ) {
		$product->set_image_id( 0 );
	} elseif ( null !== $featured_id ) {
		$product->set_image_id( $featured_id );
	}

	if ( $has_gallery ) {
		if ( 'append' === $mode ) {
			$gallery_ids = array_merge( array_map( 'intval', $product->get_gallery_image_ids() ), $gallery_ids );
		}

		// The featured image must not repeat inside the gallery - WooCommerce
		// would render it twice in the product gallery slider.
		$current_featured = (int) $product->get_image_id();
		$gallery_ids      = array_values(
			array_filter(
				array_unique( $gallery_ids ),
				static function ( $id ) use ( $current_featured ) {
					return $id && $id !== $current_featured;
				}
			)
		);

		$product->set_gallery_image_ids( $gallery_ids );
	}

	$product->save();

	// Report what is actually stored: a fresh read avoids stale in-memory
	// gallery state after an empty replace (seen on staging 2026-09-15).
	$fresh = wc_get_product( $product->get_id() );

	$payload                           = px_shop_core_ability_product_images_payload( $fresh ? $fresh : $product );
	$payload['mode']                   = $mode;
	$payload['created_attachment_ids'] = array_values( array_map( 'intval', $created ) );

	return $payload;
}

/**
 * Normalize one failed image input for the error payload.
 *
 * @param string   $field Input field the value came from.
 * @param mixed    $value The rejected value.
 * @param WP_Error $error The failure.
 * @return array<string,mixed>
 */
function px_shop_core_ability_failure( $field, $value, WP_Error $error ) {
	return array(
		'field'   => $field,
		'input'   => is_scalar( $value ) ? (string) $value : '',
		'code'    => $error->get_error_code(),
		'message' => $error->get_error_message(),
	);
}

/**
 * px-shop-core/set-attachment-alt
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|WP_Error
 */
function px_shop_core_ability_set_attachment_alt( $input = array() ) {
	$input         = is_array( $input ) ? $input : array();
	$attachment_id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
	$post          = $attachment_id ? get_post( $attachment_id ) : null;

	if ( ! $post || 'attachment' !== $post->post_type ) {
		return new WP_Error(
			'px_shop_core_attachment_not_found',
			__( 'No attachment found for the given attachment_id.', 'px-shop-core' ),
			array( 'status' => 404 )
		);
	}

	$alt = isset( $input['alt'] ) ? sanitize_text_field( (string) $input['alt'] ) : '';
	update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );

	$postarr = array( 'ID' => $attachment_id );
	if ( array_key_exists( 'title', $input ) ) {
		$postarr['post_title'] = wp_slash( sanitize_text_field( (string) $input['title'] ) );
	}
	if ( array_key_exists( 'caption', $input ) ) {
		$postarr['post_excerpt'] = wp_slash( wp_kses_post( (string) $input['caption'] ) );
	}
	if ( count( $postarr ) > 1 ) {
		$result = wp_update_post( $postarr, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	clean_post_cache( $attachment_id );

	return px_shop_core_ability_image_payload( $attachment_id );
}
