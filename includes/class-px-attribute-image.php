<?php
/**
 * Attribute term images.
 *
 * Adds an image field to every product attribute term (all pa_* taxonomies),
 * stored as term meta `px_image_id`. Useful for swatches, brand-like logos,
 * comparison rows, etc. The image is picked with the WordPress media library.
 * Themes read it via px_get_term_image() / px_get_term_image_id().
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Attribute_Image {

	const META_KEY = 'px_image_id';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_fields' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Product attribute taxonomies (pa_*).
	 *
	 * @return string[]
	 */
	protected static function taxonomies() {
		return function_exists( 'wc_get_attribute_taxonomy_names' ) ? wc_get_attribute_taxonomy_names() : array();
	}

	public static function register_fields() {
		foreach ( self::taxonomies() as $taxonomy ) {
			add_action( "{$taxonomy}_add_form_fields", array( __CLASS__, 'add_field' ) );
			add_action( "{$taxonomy}_edit_form_fields", array( __CLASS__, 'edit_field' ) );
			add_action( "created_{$taxonomy}", array( __CLASS__, 'save' ) );
			add_action( "edited_{$taxonomy}", array( __CLASS__, 'save' ) );

			add_filter( "manage_edit-{$taxonomy}_columns", array( __CLASS__, 'column_header' ) );
			add_filter( "manage_{$taxonomy}_custom_column", array( __CLASS__, 'column_content' ), 10, 3 );
		}
	}

	/* ------------------------------- Assets ------------------------------ */

	public static function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->taxonomy, self::taxonomies(), true ) ) {
			return;
		}

		wp_enqueue_media();
		wp_add_inline_script( 'jquery-core', self::inline_js() );
	}

	protected static function inline_js() {
		$title  = esc_js( __( 'Select image', 'px-shop-core' ) );
		$button = esc_js( __( 'Use image', 'px-shop-core' ) );

		return <<<JS
jQuery( function ( $ ) {
	var frame;
	$( document ).on( 'click', '.px-term-image__select', function ( e ) {
		e.preventDefault();
		var \$wrap = $( this ).closest( '.px-term-image' );
		frame = wp.media( { title: '{$title}', button: { text: '{$button}' }, library: { type: 'image' }, multiple: false } );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			var url = ( att.sizes && att.sizes.thumbnail ) ? att.sizes.thumbnail.url : att.url;
			\$wrap.find( '.px-term-image__id' ).val( att.id );
			\$wrap.find( '.px-term-image__preview' ).html( '<img src="' + url + '" style="max-width:80px;height:auto;border-radius:6px;" />' );
			\$wrap.find( '.px-term-image__remove' ).show();
		} );
		frame.open();
	} );
	$( document ).on( 'click', '.px-term-image__remove', function ( e ) {
		e.preventDefault();
		var \$wrap = $( this ).closest( '.px-term-image' );
		\$wrap.find( '.px-term-image__id' ).val( '' );
		\$wrap.find( '.px-term-image__preview' ).empty();
		$( this ).hide();
	} );
	// Clear the preview after a term is added via AJAX on the add screen.
	$( document ).ajaxComplete( function ( e, xhr, settings ) {
		if ( settings.data && settings.data.indexOf( 'action=add-tag' ) !== -1 ) {
			var \$w = $( '.px-term-image' );
			\$w.find( '.px-term-image__id' ).val( '' );
			\$w.find( '.px-term-image__preview' ).empty();
			\$w.find( '.px-term-image__remove' ).hide();
		}
	} );
} );
JS;
	}

	/* ------------------------------- Fields ------------------------------ */

	protected static function controls( $image_id ) {
		ob_start();
		wp_nonce_field( 'px_term_image', 'px_term_image_nonce' );
		?>
		<div class="px-term-image">
			<div class="px-term-image__preview">
				<?php
				if ( $image_id ) {
					echo wp_get_attachment_image( $image_id, 'thumbnail', false, array( 'style' => 'max-width:80px;height:auto;border-radius:6px;' ) );
				}
				?>
			</div>
			<input type="hidden" class="px-term-image__id" name="px_image_id" value="<?php echo esc_attr( $image_id ); ?>" />
			<button type="button" class="button px-term-image__select"><?php esc_html_e( 'Select image', 'px-shop-core' ); ?></button>
			<button type="button" class="button px-term-image__remove" style="<?php echo $image_id ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove image', 'px-shop-core' ); ?></button>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function add_field() {
		?>
		<div class="form-field">
			<label><?php esc_html_e( 'Image', 'px-shop-core' ); ?></label>
			<?php echo self::controls( 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p><?php esc_html_e( 'Optional image for this attribute value (swatch, logo, ...).', 'px-shop-core' ); ?></p>
		</div>
		<?php
	}

	public static function edit_field( $term ) {
		$image_id = (int) get_term_meta( $term->term_id, self::META_KEY, true );
		?>
		<tr class="form-field">
			<th scope="row"><label><?php esc_html_e( 'Image', 'px-shop-core' ); ?></label></th>
			<td>
				<?php echo self::controls( $image_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="description"><?php esc_html_e( 'Optional image for this attribute value (swatch, logo, ...).', 'px-shop-core' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public static function save( $term_id ) {
		if ( ! isset( $_POST['px_term_image_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['px_term_image_nonce'] ), 'px_term_image' ) ) {
			return;
		}

		$image_id = isset( $_POST['px_image_id'] ) ? absint( $_POST['px_image_id'] ) : 0;

		if ( $image_id ) {
			update_term_meta( $term_id, self::META_KEY, $image_id );
		} else {
			delete_term_meta( $term_id, self::META_KEY );
		}
	}

	/* ------------------------- Public accessors -------------------------- */

	/**
	 * Attachment ID of a term's image (0 when none).
	 *
	 * @param int $term_id Term ID.
	 * @return int
	 */
	public static function image_id( $term_id ) {
		return (int) get_term_meta( $term_id, self::META_KEY, true );
	}

	/**
	 * <img> tag for a term's image, or '' when none.
	 *
	 * @param int          $term_id Term ID.
	 * @param string|int[] $size    Image size.
	 * @param array        $attr    Image attributes.
	 * @return string
	 */
	public static function image( $term_id, $size = 'thumbnail', $attr = array() ) {
		$image_id = self::image_id( $term_id );

		return $image_id ? wp_get_attachment_image( $image_id, $size, false, $attr ) : '';
	}

	/* --------------------------- List column ----------------------------- */

	public static function column_header( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'name' === $key ) {
				$new['px_image'] = __( 'Image', 'px-shop-core' );
			}
			$new[ $key ] = $label;
		}
		return $new;
	}

	public static function column_content( $content, $column, $term_id ) {
		if ( 'px_image' !== $column ) {
			return $content;
		}
		$image_id = (int) get_term_meta( $term_id, self::META_KEY, true );

		return $image_id
			? wp_get_attachment_image( $image_id, array( 40, 40 ), false, array( 'style' => 'border-radius:4px;object-fit:cover;' ) )
			: '&mdash;';
	}
}

/* ------------------------------- Helpers ------------------------------- */

/*
 * The two functions below are guarded: older Pixelers shops carry a legacy
 * mu-plugin (px-core) that declares px_get_term_image() with a different
 * signature, and an unguarded redeclaration is a fatal error. Where that
 * happens, use PX_Attribute_Image::image_id() / ::image() instead - those
 * never collide.
 */

/**
 * Attachment ID of an attribute term's image (0 when none).
 *
 * @param int $term_id Term ID.
 * @return int
 */
if ( ! function_exists( 'px_get_term_image_id' ) ) {
	function px_get_term_image_id( $term_id ) {
		return PX_Attribute_Image::image_id( $term_id );
	}
}

/**
 * <img> tag for an attribute term's image, or '' when none.
 *
 * @param int          $term_id Term ID.
 * @param string|int[] $size    Image size.
 * @param array        $attr    Image attributes.
 * @return string
 */
if ( ! function_exists( 'px_get_term_image' ) ) {
	function px_get_term_image( $term_id, $size = 'thumbnail', $attr = array() ) {
		return PX_Attribute_Image::image( $term_id, $size, $attr );
	}
}
