<?php
/**
 * Old term slugs.
 *
 * WordPress remembers the previous slug of a post (_wp_old_slug) and sends
 * old links to the new address. Terms get nothing of the kind: rename a
 * category and every link to it - search results, backlinks, ads, bookmarks -
 * ends in a 404. This module fills the gap.
 *
 * Recording: when the slug of a term in a public taxonomy changes (admin,
 * REST, WP-CLI, anything calling wp_update_term()), the previous slug is
 * added to the term meta _px_old_slug (one row per slug, no duplicates).
 * Changing the term back to a slug it used to have removes that slug from
 * the list - it is live again.
 *
 * Redirecting: only a request that already ended in a 404 is looked at.
 * The last path segment (after /page/N/ is taken off) is looked up as the
 * current slug of a term and, failing that, as an old one. When exactly
 * one term matches and its canonical URL differs from the requested one,
 * the visitor gets a 301 there, keeping the page number and the query
 * string. More than one match means the old address is ambiguous - nothing
 * happens and the 404 stays.
 *
 * Looking the last segment up as a *current* slug is what makes a renamed
 * parent work: /pumps-old/electric/ is dead after "pumps-old" became
 * "pumps", but "electric" is still the child's slug and get_term_link()
 * knows where it lives now.
 *
 * Cost: a 404 that is not on a taxonomy costs no query at all. "On a
 * taxonomy" means either the query var of a supported taxonomy is set
 * (URLs with a base, /product-category/...) or - for sites whose
 * permalink plugin strips the base - one of the path segments is a known
 * old slug. Known old slugs are kept in one autoloaded option, so that
 * check is an isset() on an array already in memory.
 *
 * Storage:
 *   term    _px_old_slug          old slug, one meta row per slug
 *   option  px_old_term_slugs     slug => true, index of all old slugs
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Old_Term_Slugs {

	const META         = '_px_old_slug';
	const INDEX_OPTION = 'px_old_term_slugs';
	const REMOVE       = 'px_old_term_slug_remove';

	/**
	 * Slugs of terms about to be updated, keyed by term ID.
	 *
	 * @var string[]
	 */
	protected static $before = array();

	public static function init() {
		add_action( 'edit_terms', array( __CLASS__, 'remember_slug' ), 10, 2 );
		add_action( 'edited_term', array( __CLASS__, 'record_slug' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'rebuild_index' ) );

		// Before redirect_canonical() (10): its guess of a 404 permalink
		// would otherwise send an old category URL to some product that
		// happens to share a word with it.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 9 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'admin_hooks' ) );
			add_action( 'admin_post_' . self::REMOVE, array( __CLASS__, 'handle_remove' ) );
		}
	}

	/**
	 * Taxonomies whose slugs are tracked and redirected.
	 *
	 * @return string[]
	 */
	public static function taxonomies() {
		$taxonomies = get_taxonomies( array( 'publicly_queryable' => true ) );
		unset( $taxonomies['post_format'] );

		/**
		 * Filters the taxonomies whose old slugs are kept and redirected.
		 *
		 * @param string[] $taxonomies Taxonomy names.
		 */
		return array_values( (array) apply_filters( 'px_old_term_slugs_taxonomies', array_values( $taxonomies ) ) );
	}

	/**
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	protected static function tracked( $taxonomy ) {
		return in_array( $taxonomy, self::taxonomies(), true );
	}

	/* ------------------------------ Recording ---------------------------- */

	/**
	 * Keeps the slug a term has before wp_update_term() writes the new one.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function remember_slug( $term_id, $taxonomy ) {
		if ( ! self::tracked( $taxonomy ) ) {
			return;
		}

		$term = get_term( (int) $term_id, $taxonomy );

		if ( $term instanceof WP_Term ) {
			self::$before[ (int) $term_id ] = $term->slug;
		}
	}

	/**
	 * Stores the previous slug once the update is done.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function record_slug( $term_id, $tt_id, $taxonomy ) {
		$term_id = (int) $term_id;

		if ( ! isset( self::$before[ $term_id ] ) ) {
			return;
		}

		$old = self::$before[ $term_id ];
		unset( self::$before[ $term_id ] );

		$term = get_term( $term_id, $taxonomy );

		if ( ! $term instanceof WP_Term || $term->slug === $old ) {
			return;
		}

		// Back on a slug it used before: that one is live again.
		$changed = self::delete_meta( $term_id, $term->slug );
		$changed = self::add_meta( $term_id, $old ) || $changed;

		if ( $changed ) {
			self::rebuild_index();
		}
	}

	/**
	 * Old slugs of a term.
	 *
	 * @param int $term_id Term ID.
	 * @return string[]
	 */
	public static function get( $term_id ) {
		return array_values( array_unique( array_map( 'strval', (array) get_term_meta( (int) $term_id, self::META ) ) ) );
	}

	/**
	 * Adds an old slug to a term by hand (seeding after a rename done before
	 * the module was on, or an URL that came from elsewhere).
	 *
	 * @param int    $term_id Term ID.
	 * @param string $slug    Old slug.
	 * @return bool Whether the slug was added.
	 */
	public static function add( $term_id, $slug ) {
		$term = get_term( (int) $term_id );
		$slug = sanitize_title( $slug );

		if ( ! $term instanceof WP_Term || '' === $slug || $slug === $term->slug ) {
			return false;
		}

		if ( ! self::add_meta( $term->term_id, $slug ) ) {
			return false;
		}

		self::rebuild_index();

		return true;
	}

	/**
	 * Removes an old slug from a term.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $slug    Old slug.
	 * @return bool Whether something was removed.
	 */
	public static function remove( $term_id, $slug ) {
		if ( ! self::delete_meta( (int) $term_id, (string) $slug ) ) {
			return false;
		}

		self::rebuild_index();

		return true;
	}

	/**
	 * @param int    $term_id Term ID.
	 * @param string $slug    Slug.
	 * @return bool
	 */
	protected static function add_meta( $term_id, $slug ) {
		if ( '' === $slug || in_array( $slug, self::get( $term_id ), true ) ) {
			return false;
		}

		return (bool) add_term_meta( $term_id, self::META, $slug );
	}

	/**
	 * @param int    $term_id Term ID.
	 * @param string $slug    Slug.
	 * @return bool
	 */
	protected static function delete_meta( $term_id, $slug ) {
		if ( ! in_array( $slug, self::get( $term_id ), true ) ) {
			return false;
		}

		return delete_term_meta( $term_id, self::META, $slug );
	}

	/**
	 * Rewrites the index of known old slugs (autoloaded, read on 404s).
	 */
	public static function rebuild_index() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one DISTINCT over an indexed key, only when a slug changes.
		$slugs = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s", self::META ) );

		$index = array();
		foreach ( $slugs as $slug ) {
			$index[ (string) $slug ] = true;
		}

		if ( $index ) {
			update_option( self::INDEX_OPTION, $index, true );
		} else {
			delete_option( self::INDEX_OPTION );
		}
	}

	/* ----------------------------- Redirecting --------------------------- */

	/**
	 * 301 from a dead term URL to the term's current one.
	 */
	public static function maybe_redirect() {
		if ( ! is_404() || is_feed() || is_admin() || wp_doing_ajax() ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}

		global $wp, $wp_rewrite;

		$path = trim( (string) $wp->request, '/' );
		if ( '' === $path ) {
			return;
		}

		$segments = array_map( array( __CLASS__, 'normalize' ), explode( '/', $path ) );

		$paged = 0;
		$count = count( $segments );
		$base  = $wp_rewrite ? $wp_rewrite->pagination_base : 'page';
		if ( $count > 2 && $base === $segments[ $count - 2 ] && ctype_digit( $segments[ $count - 1 ] ) ) {
			$paged = (int) $segments[ $count - 1 ];
			array_splice( $segments, -2 );
		}
		if ( ! $paged ) {
			$paged = (int) get_query_var( 'paged' );
		}

		$segments = array_values( array_filter( $segments, 'strlen' ) );
		if ( ! $segments ) {
			return;
		}

		// Cheap gate: a taxonomy query var, or a known old slug in the path.
		$taxonomies = self::taxonomies();
		$queried    = array();
		foreach ( $taxonomies as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			if ( $object && $object->query_var && ! empty( $wp->query_vars[ $object->query_var ] ) ) {
				$queried[] = $taxonomy;
			}
		}

		if ( $queried ) {
			$taxonomies = $queried;
		} else {
			$index = get_option( self::INDEX_OPTION, array() );
			if ( ! is_array( $index ) || ! array_intersect_key( array_flip( $segments ), $index ) ) {
				return;
			}
		}

		$slug   = end( $segments );
		$parent = count( $segments ) > 1 ? $segments[ count( $segments ) - 2 ] : '';
		$term   = self::find_term( $slug, $parent, $taxonomies );

		if ( ! $term ) {
			return;
		}

		$target = get_term_link( $term );
		if ( is_wp_error( $target ) ) {
			return;
		}

		if ( $paged > 1 ) {
			$target = trailingslashit( $target ) . user_trailingslashit( $base . '/' . $paged, 'paged' );
		}

		// Both sides as normalized segments, without the home path of a
		// site installed in a subdirectory.
		$requested   = implode( '/', $segments ) . ( $paged > 1 ? '/' . $base . '/' . $paged : '' );
		$home_path   = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$target_path = trim( (string) wp_parse_url( $target, PHP_URL_PATH ), '/' );
		if ( '' !== $home_path && 0 === strpos( $target_path, $home_path . '/' ) ) {
			$target_path = substr( $target_path, strlen( $home_path ) + 1 );
		}
		$target_path = implode( '/', array_map( array( __CLASS__, 'normalize' ), explode( '/', $target_path ) ) );
		if ( $requested === $target_path ) {
			return;
		}

		if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
			$target .= '?' . wp_unslash( $_SERVER['QUERY_STRING'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed through as-is, wp_safe_redirect() sanitizes the Location header.
		}

		/**
		 * Filters the redirect target for a dead term URL. Return an empty
		 * value to keep the 404.
		 *
		 * @param string  $target    Absolute URL.
		 * @param WP_Term $term      Term the old URL belongs to.
		 * @param string  $requested Requested path.
		 */
		$target = apply_filters( 'px_old_term_slugs_redirect', $target, $term, $requested );

		if ( $target && wp_safe_redirect( $target, 301, 'PX Shop Core' ) ) {
			exit;
		}
	}

	/**
	 * A path segment in the form term slugs are stored in: percent-encoded
	 * with lowercase hex, as sanitize_title() leaves non-ASCII slugs.
	 *
	 * @param string $segment Raw path segment.
	 * @return string
	 */
	public static function normalize( $segment ) {
		return strtolower( rawurlencode( rawurldecode( (string) $segment ) ) );
	}

	/**
	 * The one term a dead URL belongs to, or null when none or ambiguous.
	 *
	 * @param string   $slug       Last path segment.
	 * @param string   $parent     Segment before it ('' when none).
	 * @param string[] $taxonomies Taxonomies to look in.
	 * @return WP_Term|null
	 */
	protected static function find_term( $slug, $parent, $taxonomies ) {
		$args = array(
			'taxonomy'   => $taxonomies,
			'hide_empty' => false,
		);

		$terms = get_terms( $args + array( 'slug' => $slug ) );

		if ( is_wp_error( $terms ) || ! $terms ) {
			$terms = get_terms(
				$args + array(
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- indexed meta_key, only on a 404 that passed the gate.
					'meta_query' => array(
						array(
							'key'   => self::META,
							'value' => $slug,
						),
					),
				)
			);
		}

		if ( is_wp_error( $terms ) || ! $terms ) {
			return null;
		}

		// Same slug in two places: the parent segment may tell them apart.
		if ( count( $terms ) > 1 && '' !== $parent ) {
			$terms = array_values(
				array_filter(
					$terms,
					static function ( $term ) use ( $parent ) {
						if ( ! $term->parent ) {
							return false;
						}
						$up = get_term( $term->parent, $term->taxonomy );

						return $up instanceof WP_Term
							&& ( $up->slug === $parent || in_array( $parent, self::get( $up->term_id ), true ) );
					}
				)
			);
		}

		return 1 === count( $terms ) ? $terms[0] : null;
	}

	/* -------------------------------- Admin ------------------------------ */

	/**
	 * Read-only list on the term edit screen of every tracked taxonomy.
	 */
	public static function admin_hooks() {
		foreach ( self::taxonomies() as $taxonomy ) {
			add_action( $taxonomy . '_edit_form_fields', array( __CLASS__, 'edit_field' ), 30 );
		}
	}

	/**
	 * @param WP_Term $term Term being edited.
	 */
	public static function edit_field( $term ) {
		$slugs = self::get( $term->term_id );
		if ( ! $slugs ) {
			return;
		}

		$can = current_user_can( 'edit_term', $term->term_id );
		?>
		<tr class="form-field px-old-term-slugs">
			<th scope="row"><?php esc_html_e( 'Old slugs', 'px-shop-core' ); ?></th>
			<td>
				<ul style="margin:0">
					<?php foreach ( $slugs as $slug ) : ?>
						<li>
							<code><?php echo esc_html( rawurldecode( $slug ) ); ?></code>
							<?php
							if ( $can ) :
								$url = wp_nonce_url(
									add_query_arg(
										array(
											'action'  => self::REMOVE,
											'term_id' => $term->term_id,
											'slug'    => rawurlencode( $slug ),
										),
										admin_url( 'admin-post.php' )
									),
									self::REMOVE . '_' . $term->term_id . '_' . $slug
								);
								?>
								<a href="<?php echo esc_url( $url ); ?>" class="delete">
									<?php
									/* translators: %s: old slug. */
									echo esc_html( sprintf( __( 'Remove %s', 'px-shop-core' ), rawurldecode( $slug ) ) );
									?>
								</a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="description"><?php esc_html_e( 'Addresses with these slugs are redirected (301) to this term. They are recorded automatically when the slug changes.', 'px-shop-core' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * admin-post handler for the remove link.
	 */
	public static function handle_remove() {
		$term_id = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0;
		// A slug as stored: lowercase, percent-encoded, dashes and
		// underscores. sanitize_title() would strip accents off it.
		$slug = isset( $_GET['slug'] ) ? preg_replace( '/[^a-z0-9%_\-]/', '', strtolower( wp_unslash( $_GET['slug'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- whitelisted by preg_replace().

		check_admin_referer( self::REMOVE . '_' . $term_id . '_' . $slug );

		$term = get_term( $term_id );
		if ( ! $term instanceof WP_Term || ! current_user_can( 'edit_term', $term_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit this item.', 'px-shop-core' ), 403 );
		}

		self::remove( $term_id, $slug );

		$back = get_edit_term_link( $term_id, $term->taxonomy );

		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}
}

if ( ! function_exists( 'px_add_old_term_slug' ) ) {
	/**
	 * Adds an old slug to a term, so its old URL redirects to the current one.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $slug    Old slug.
	 * @return bool Whether the slug was added.
	 */
	function px_add_old_term_slug( $term_id, $slug ) {
		return PX_Old_Term_Slugs::add( $term_id, $slug );
	}
}

if ( ! function_exists( 'px_remove_old_term_slug' ) ) {
	/**
	 * Removes an old slug from a term.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $slug    Old slug.
	 * @return bool Whether something was removed.
	 */
	function px_remove_old_term_slug( $term_id, $slug ) {
		return PX_Old_Term_Slugs::remove( $term_id, $slug );
	}
}

if ( ! function_exists( 'px_get_old_term_slugs' ) ) {
	/**
	 * Old slugs of a term.
	 *
	 * @param int $term_id Term ID.
	 * @return string[]
	 */
	function px_get_old_term_slugs( $term_id ) {
		return PX_Old_Term_Slugs::get( $term_id );
	}
}
