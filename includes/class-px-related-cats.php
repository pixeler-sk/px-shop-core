<?php
/**
 * Related categories.
 *
 * Cross-sells defined once per product category instead of product by
 * product. A category says which other categories go with it ("bicycles"
 * -> "lights", "bottle cages", "pedals") and every product in it offers
 * products from those categories.
 *
 * Relations are one-way on purpose: "bicycles -> lights" does not make
 * "lights -> bicycles". Selling a light to someone buying a bike is an
 * accessory; selling a bike to someone buying a light is not.
 *
 * Inheritance follows the tree - a category with nothing of its own asks
 * its parent, so the whole "Bicycles" branch is configured in one place.
 *
 * Storage:
 *   term  _px_related_cats        on a product_cat: array of term IDs
 *   term  _px_related_cats_title  on a product_cat: heading override
 *
 * Cost: the resolved product IDs are cached per *set of categories*, not
 * per product, so every bike in the shop shares one cache entry. A cache
 * hit costs no database query at all; a miss costs one indexed query per
 * related category. The entry is invalidated by a version counter bumped
 * when a category is saved or deleted (prices and stock change far more
 * often than the list of accessories, so product saves deliberately do
 * not flush it - the TTL and the visibility check at render time carry
 * that).
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Related_Cats {

	const TAXONOMY   = 'product_cat';
	const META_TERMS = '_px_related_cats';
	const META_TITLE = '_px_related_cats_title';

	/** Bumped whenever a category changes, so cached ID lists fall out. */
	const VERSION_OPTION = 'px_related_cats_version';

	/** Resolved configuration per product for this request. */
	protected static $resolved = array();

	public static function init() {
		if ( is_admin() ) {
			add_action( self::TAXONOMY . '_add_form_fields', array( __CLASS__, 'term_add_fields' ) );
			add_action( self::TAXONOMY . '_edit_form_fields', array( __CLASS__, 'term_edit_fields' ) );
			add_action( 'created_' . self::TAXONOMY, array( __CLASS__, 'save_term' ) );
			add_action( 'edited_' . self::TAXONOMY, array( __CLASS__, 'save_term' ) );

			add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', array( __CLASS__, 'term_column' ) );
			add_filter( 'manage_' . self::TAXONOMY . '_custom_column', array( __CLASS__, 'term_column_value' ), 10, 3 );
		}

		// A deleted category must not keep pulling products from cache.
		add_action( 'delete_' . self::TAXONOMY, array( __CLASS__, 'bump_version' ) );

		if ( self::cart_enabled() ) {
			add_filter( 'woocommerce_cart_crosssell_ids', array( __CLASS__, 'cart_crosssell_ids' ) );

			// WooCommerce shows two cross-sells in random order. Ours are
			// picked and ordered deliberately, so keep the order and show
			// as many as the module is set to.
			add_filter( 'woocommerce_cross_sells_total', array( __CLASS__, 'cart_total' ) );
			add_filter( 'woocommerce_cross_sells_orderby', array( __CLASS__, 'cart_orderby' ) );
			add_filter( 'woocommerce_cross_sells_order', array( __CLASS__, 'cart_order' ) );
		}
	}

	/* ------------------------------ Settings ----------------------------- */

	/**
	 * Fields for the module's own settings section.
	 *
	 * @return array
	 */
	public static function settings_fields() {
		return array(
			array(
				'title' => __( 'Related categories', 'px-shop-core' ),
				'type'  => 'title',
				'desc'  => __( 'Which categories go with which is set on the category itself (Products → Categories). Subcategories inherit from their parent, so one setting usually covers a whole branch.', 'px-shop-core' ),
				'id'    => 'px_related_cats_options',
			),
			array(
				'title'             => __( 'Number of products', 'px-shop-core' ),
				'desc'              => __( 'How many products the theme gets. They are taken in turn from each related category, so one category cannot fill the whole row.', 'px-shop-core' ),
				'id'                => 'px_related_cats_count',
				'type'              => 'number',
				'default'           => '8',
				'desc_tip'          => true,
				'custom_attributes' => array(
					'min'  => '1',
					'step' => '1',
				),
			),
			array(
				'title'    => __( 'Which products first', 'px-shop-core' ),
				'desc'     => __( 'Order inside each related category.', 'px-shop-core' ),
				'id'       => 'px_related_cats_order',
				'type'     => 'select',
				'default'  => 'popularity',
				'desc_tip' => true,
				'options'  => array(
					'popularity' => __( 'Best selling', 'px-shop-core' ),
					'date'       => __( 'Newest', 'px-shop-core' ),
					'menu_order' => __( 'Category order (menu order)', 'px-shop-core' ),
				),
			),
			array(
				'title'    => __( 'Heading', 'px-shop-core' ),
				'desc'     => __( 'Used when the category does not set its own. Empty falls back to "Goes well with this".', 'px-shop-core' ),
				'id'       => 'px_related_cats_title',
				'type'     => 'text',
				'desc_tip' => true,
			),
			array(
				'title'   => __( 'Cart', 'px-shop-core' ),
				'desc'    => __( 'Offer them in the cart as well', 'px-shop-core' ),
				'id'      => 'px_related_cats_cart',
				'type'    => 'checkbox',
				'default' => 'no',
				/* translators: no placeholders. */
				'desc_tip' => __( 'Adds them to the cart cross-sells, after the ones the products name themselves. Applies to the classic cart; the block cart draws its own.', 'px-shop-core' ),
			),
			array(
				'title'             => __( 'Cache', 'px-shop-core' ),
				'desc'              => __( 'Hours before the product list is looked up again. 0 turns caching off (development only).', 'px-shop-core' ),
				'id'                => 'px_related_cats_ttl',
				'type'              => 'number',
				'default'           => '12',
				'desc_tip'          => true,
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'px_related_cats_options',
			),
		);
	}

	/**
	 * How many products the public API returns by default.
	 *
	 * @return int
	 */
	public static function count() {
		return max( 1, (int) get_option( 'px_related_cats_count', 8 ) );
	}

	/**
	 * @return bool
	 */
	public static function cart_enabled() {
		return 'yes' === get_option( 'px_related_cats_cart', 'no' );
	}

	/* ----------------------------- Resolution ---------------------------- */

	/**
	 * Related categories that apply to a product.
	 *
	 * Each of the product's categories contributes its own list (or the
	 * nearest ancestor's). Categories the product already sits in are
	 * dropped - "more of the same" is what native related products are for.
	 *
	 * @param int $product_id Product ID.
	 * @return int[] Term IDs.
	 */
	public static function get_term_ids_for_product( $product_id ) {
		$config = self::resolve( $product_id );

		return $config['terms'];
	}

	/**
	 * Related categories of a single category, own or inherited.
	 *
	 * @param int $term_id Term ID.
	 * @return int[] Term IDs.
	 */
	public static function get_term_ids( $term_id ) {
		$own = self::stored_terms( $term_id );
		if ( $own ) {
			return $own;
		}

		foreach ( get_ancestors( $term_id, self::TAXONOMY, 'taxonomy' ) as $ancestor ) {
			$inherited = self::stored_terms( $ancestor );
			if ( $inherited ) {
				return $inherited;
			}
		}

		return array();
	}

	/**
	 * Heading for the product's block.
	 *
	 * The category that contributed the relations gets to name it; then the
	 * shop-wide setting; then a default.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	public static function get_title( $product_id ) {
		$config = self::resolve( $product_id );

		$title = $config['title'];

		if ( '' === $title ) {
			$title = (string) get_option( 'px_related_cats_title', '' );
		}

		if ( '' === $title ) {
			$title = __( 'Goes well with this', 'px-shop-core' );
		}

		/**
		 * Filters the heading of the related-categories block.
		 *
		 * @param string $title      Heading.
		 * @param int    $product_id Product ID.
		 */
		return (string) apply_filters( 'px_related_cats_title', $title, $product_id );
	}

	/**
	 * How many cross-sells the cart shows.
	 *
	 * @param int $limit WooCommerce default.
	 * @return int
	 */
	public static function cart_total( $limit ) {
		return self::count();
	}

	/**
	 * @param string $orderby WooCommerce default ('rand').
	 * @return string
	 */
	public static function cart_orderby( $orderby ) {
		return 'none';
	}

	/**
	 * @param string $order WooCommerce default ('desc', which reverses).
	 * @return string
	 */
	public static function cart_order( $order ) {
		return 'asc';
	}

	/**
	 * Products from the categories related to this one.
	 *
	 * @param int $product_id Product ID.
	 * @param int $limit      How many; 0 uses the setting.
	 * @return int[] Product IDs.
	 */
	public static function get_product_ids( $product_id, $limit = 0 ) {
		$product_id = (int) $product_id;
		$limit      = $limit > 0 ? (int) $limit : self::count();
		$terms      = self::get_term_ids_for_product( $product_id );

		if ( empty( $terms ) ) {
			return array();
		}

		// One spare, so removing the product itself cannot shorten the row.
		$ids = self::query( $terms, $limit + 1 );
		$ids = array_values( array_diff( $ids, array( $product_id ) ) );
		$ids = array_slice( $ids, 0, $limit );

		/**
		 * Filters the products offered next to a product.
		 *
		 * @param int[] $ids        Product IDs.
		 * @param int   $product_id Product ID.
		 */
		return (array) apply_filters( 'px_related_cats_product_ids', $ids, $product_id );
	}

	/**
	 * Products that go with what is currently in the cart.
	 *
	 * @param int   $limit   How many; 0 uses the setting.
	 * @param int[] $exclude Product IDs to leave out (defaults to the cart).
	 * @return int[] Product IDs.
	 */
	public static function get_cart_ids( $limit = 0, $exclude = null ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}

		$limit    = $limit > 0 ? (int) $limit : self::count();
		$terms    = array();
		$in_cart  = array();
		$own_cats = array();

		foreach ( WC()->cart->get_cart() as $item ) {
			$item_id   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$in_cart[] = $item_id;

			$config   = self::resolve( $item_id );
			$terms    = array_merge( $terms, $config['terms'] );
			$own_cats = array_merge( $own_cats, $config['own_cats'] );
		}

		// A category already represented in the cart is not an upsell.
		$terms = array_values( array_diff( array_unique( $terms ), $own_cats ) );

		if ( empty( $terms ) ) {
			return array();
		}

		$exclude = null === $exclude ? $in_cart : array_map( 'intval', (array) $exclude );
		$ids     = self::query( $terms, $limit + count( $exclude ) );
		$ids     = array_values( array_diff( $ids, $exclude ) );

		return array_slice( $ids, 0, $limit );
	}

	/**
	 * Appends our products to the cart cross-sells.
	 *
	 * WooCommerce has already removed what is in the cart from $ids; ours
	 * are filtered against the cart in get_cart_ids().
	 *
	 * @param array $ids Cross-sell product IDs.
	 * @return array
	 */
	public static function cart_crosssell_ids( $ids ) {
		$ids = array_map( 'intval', (array) $ids );
		$ours = self::get_cart_ids();

		if ( empty( $ours ) ) {
			return $ids;
		}

		// Products named on the product itself keep the first places.
		return array_values( array_unique( array_merge( $ids, array_diff( $ours, $ids ) ) ) );
	}

	/**
	 * Categories and heading that apply to a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array { terms: int[], own_cats: int[], title: string }
	 */
	protected static function resolve( $product_id ) {
		$product_id = (int) $product_id;

		if ( isset( self::$resolved[ $product_id ] ) ) {
			return self::$resolved[ $product_id ];
		}

		$empty = array(
			'terms'    => array(),
			'own_cats' => array(),
			'title'    => '',
		);

		$own_cats = wp_get_post_terms( $product_id, self::TAXONOMY, array( 'fields' => 'ids' ) );

		if ( is_wp_error( $own_cats ) || empty( $own_cats ) ) {
			self::$resolved[ $product_id ] = $empty;

			return $empty;
		}

		$own_cats = array_map( 'intval', $own_cats );
		$terms    = array();
		$title    = '';

		foreach ( $own_cats as $cat_id ) {
			$related = self::get_term_ids( $cat_id );

			if ( empty( $related ) ) {
				continue;
			}

			$terms = array_merge( $terms, $related );

			if ( '' === $title ) {
				$title = self::stored_title( $cat_id );
			}
		}

		$terms = array_values( array_diff( array_unique( $terms ), $own_cats ) );

		/**
		 * Filters the categories a product draws its accessories from.
		 *
		 * @param int[] $terms      Term IDs.
		 * @param int   $product_id Product ID.
		 */
		$terms = array_map( 'intval', (array) apply_filters( 'px_related_cats_term_ids', $terms, $product_id ) );

		$config = array(
			'terms'    => $terms,
			'own_cats' => $own_cats,
			'title'    => $title,
		);

		self::$resolved[ $product_id ] = $config;

		return $config;
	}

	/* ------------------------------- Query ------------------------------- */

	/**
	 * Product IDs from a set of categories, cached.
	 *
	 * The cache key is the set of categories - not the product - so all
	 * products of a category share one entry.
	 *
	 * @param int[] $term_ids Category term IDs.
	 * @param int   $limit    How many IDs are needed.
	 * @return int[]
	 */
	protected static function query( $term_ids, $limit ) {
		$term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );
		sort( $term_ids );

		$key = 'px_relcat_' . self::version() . '_' . substr(
			md5( implode( ',', $term_ids ) . '|' . $limit . '|' . self::order_key() ),
			0,
			24
		);

		$ttl = (int) get_option( 'px_related_cats_ttl', 12 );

		if ( $ttl > 0 ) {
			$cached = get_transient( $key );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$lists = array();

		foreach ( $term_ids as $term_id ) {
			$found = self::query_term( $term_id, $limit );

			if ( $found ) {
				$lists[] = $found;
			}
		}

		$ids = self::interleave( $lists, $limit );

		if ( $ttl > 0 ) {
			set_transient( $key, $ids, $ttl * HOUR_IN_SECONDS );
		}

		return $ids;
	}

	/**
	 * Visible, purchasable products of one category (children included).
	 *
	 * @param int $term_id Term ID.
	 * @param int $limit   How many.
	 * @return int[]
	 */
	protected static function query_term( $term_id, $limit ) {
		$args = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'fields'                 => 'ids',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tax_query'              => array(
				array(
					'taxonomy'         => self::TAXONOMY,
					'field'            => 'term_id',
					'terms'            => $term_id,
					'include_children' => true,
				),
			),
		);

		$hidden = self::hidden_visibility_ids();

		if ( $hidden ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'product_visibility',
				'field'    => 'term_taxonomy_id',
				'terms'    => $hidden,
				'operator' => 'NOT IN',
			);
		}

		$args = array_merge( $args, self::order_args() );

		/**
		 * Filters the query behind one related category.
		 *
		 * @param array $args    WP_Query arguments.
		 * @param int   $term_id Term ID.
		 */
		$args = apply_filters( 'px_related_cats_query_args', $args, $term_id );

		$query = new WP_Query( $args );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * product_visibility term_taxonomy_ids that must not show up.
	 *
	 * @return int[]
	 */
	protected static function hidden_visibility_ids() {
		if ( ! function_exists( 'wc_get_product_visibility_term_ids' ) ) {
			return array();
		}

		$visibility = wc_get_product_visibility_term_ids();
		$hide       = array( 'exclude-from-catalog' );

		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			$hide[] = 'outofstock';
		}

		$ids = array();

		foreach ( $hide as $name ) {
			if ( ! empty( $visibility[ $name ] ) ) {
				$ids[] = (int) $visibility[ $name ];
			}
		}

		return $ids;
	}

	/**
	 * @return string
	 */
	protected static function order_key() {
		$order = (string) get_option( 'px_related_cats_order', 'popularity' );

		return in_array( $order, array( 'popularity', 'date', 'menu_order' ), true ) ? $order : 'popularity';
	}

	/**
	 * WP_Query ordering for the configured mode.
	 *
	 * Deliberately no random order: it would make the cache pointless and
	 * every page view a fresh query.
	 *
	 * @return array
	 */
	protected static function order_args() {
		switch ( self::order_key() ) {
			case 'date':
				return array(
					'orderby' => 'date',
					'order'   => 'DESC',
				);

			case 'menu_order':
				return array(
					'orderby' => array(
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					),
				);

			default:
				return array(
					'meta_key' => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'orderby'  => array(
						'meta_value_num' => 'DESC',
						'date'           => 'DESC',
					),
				);
		}
	}

	/**
	 * One from each list, then the next one, until full.
	 *
	 * Without this the first category would fill the whole row and the
	 * customer would see eight pedals instead of a pedal, a light and a
	 * bottle cage.
	 *
	 * @param array $lists Lists of product IDs.
	 * @param int   $limit How many.
	 * @return int[]
	 */
	protected static function interleave( $lists, $limit ) {
		$out   = array();
		$index = 0;

		do {
			$found = false;

			foreach ( $lists as $list ) {
				if ( ! isset( $list[ $index ] ) ) {
					continue;
				}

				$found = true;
				$id    = (int) $list[ $index ];

				if ( ! in_array( $id, $out, true ) ) {
					$out[] = $id;
				}

				if ( count( $out ) >= $limit ) {
					return $out;
				}
			}

			$index++;
		} while ( $found );

		return $out;
	}

	/* ------------------------------- Cache ------------------------------- */

	/**
	 * @return int
	 */
	protected static function version() {
		return (int) get_option( self::VERSION_OPTION, 1 );
	}

	/**
	 * Invalidates every cached list by moving the key space.
	 *
	 * Cheaper and safer than hunting transients down one by one, and it
	 * works with an external object cache where they are not rows at all.
	 */
	public static function bump_version() {
		update_option( self::VERSION_OPTION, self::version() + 1, false );
	}

	/* ------------------------------- Admin ------------------------------- */

	/**
	 * Fields on the "add category" form.
	 */
	public static function term_add_fields() {
		?>
		<div class="form-field">
			<label><?php esc_html_e( 'Related categories', 'px-shop-core' ); ?></label>
			<?php self::checklist( 0 ); ?>
			<p class="description"><?php echo esc_html( self::field_hint() ); ?></p>
		</div>
		<div class="form-field">
			<label for="px_related_cats_title"><?php esc_html_e( 'Heading', 'px-shop-core' ); ?></label>
			<input type="text" name="px_related_cats_title" id="px_related_cats_title" value="" />
			<p class="description"><?php esc_html_e( 'Optional. Shown above the products instead of the default one.', 'px-shop-core' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Fields on the "edit category" form.
	 *
	 * @param WP_Term $term Term being edited.
	 */
	public static function term_edit_fields( $term ) {
		?>
		<tr class="form-field">
			<th scope="row"><label><?php esc_html_e( 'Related categories', 'px-shop-core' ); ?></label></th>
			<td>
				<?php self::checklist( $term->term_id ); ?>
				<p class="description"><?php echo esc_html( self::field_hint() ); ?></p>
				<?php self::inherited_hint( $term ); ?>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="px_related_cats_title"><?php esc_html_e( 'Heading', 'px-shop-core' ); ?></label></th>
			<td>
				<input type="text" name="px_related_cats_title" id="px_related_cats_title" class="regular-text"
					value="<?php echo esc_attr( self::stored_title( $term->term_id ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Optional. Shown above the products instead of the default one.', 'px-shop-core' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * @return string
	 */
	protected static function field_hint() {
		return __( 'Products in this category will offer products from the categories ticked here. Subcategories inherit this, so ticking it on the top category is usually enough.', 'px-shop-core' );
	}

	/**
	 * Tells the editor where the inherited list comes from.
	 *
	 * Without it an empty checklist looks like "nothing happens here",
	 * while the products may well be showing accessories from a parent.
	 *
	 * @param WP_Term $term Term being edited.
	 */
	protected static function inherited_hint( $term ) {
		if ( self::stored_terms( $term->term_id ) ) {
			return;
		}

		foreach ( get_ancestors( $term->term_id, self::TAXONOMY, 'taxonomy' ) as $ancestor ) {
			if ( ! self::stored_terms( $ancestor ) ) {
				continue;
			}

			$parent = get_term( $ancestor, self::TAXONOMY );

			if ( ! $parent || is_wp_error( $parent ) ) {
				return;
			}
			?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: name of the parent category the setting is inherited from. */
					esc_html__( 'Nothing ticked: the list is inherited from %s.', 'px-shop-core' ),
					'<a href="' . esc_url( get_edit_term_link( $ancestor, self::TAXONOMY ) ) . '">' . esc_html( $parent->name ) . '</a>'
				);
				?>
			</p>
			<?php
			return;
		}
	}

	/**
	 * Scrollable checklist of the category tree, with a filter box.
	 *
	 * Plain checkboxes on purpose - no select2, no admin asset to enqueue on
	 * a screen that has to work in every WordPress version. A shop can have
	 * hundreds of categories, so the box comes with a search field and a
	 * "selected only" switch; both are a dozen lines of inline script that
	 * only hide list items, and the field works without them.
	 *
	 * @param int $term_id Term being edited (0 on the add form).
	 */
	protected static function checklist( $term_id ) {
		$term_id = (int) $term_id;
		$terms   = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			esc_html_e( 'No product categories yet.', 'px-shop-core' );

			return;
		}

		// A category cannot point at itself or at its own subtree.
		$skip = array();

		if ( $term_id ) {
			$skip   = get_term_children( $term_id, self::TAXONOMY );
			$skip   = is_wp_error( $skip ) ? array() : array_map( 'intval', $skip );
			$skip[] = $term_id;
		}

		$current = $term_id ? self::stored_terms( $term_id ) : array();
		$names   = array();
		$tree    = array();

		foreach ( $terms as $term ) {
			$names[ (int) $term->term_id ] = $term->name;
			$tree[ (int) $term->parent ][] = $term;
		}

		wp_nonce_field( 'px_related_cats_save', 'px_related_cats_nonce' );
		?>
		<div class="px-related-cats" style="max-width:38em;">
			<p style="margin:0 0 6px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
				<input type="search" class="px-related-cats-search" style="flex:1 1 14em;"
					placeholder="<?php esc_attr_e( 'Search categories…', 'px-shop-core' ); ?>" />
				<label style="font-weight:400;">
					<input type="checkbox" class="px-related-cats-only" />
					<?php esc_html_e( 'Selected only', 'px-shop-core' ); ?>
				</label>
			</p>

			<ul class="px-related-cats-list" style="max-height:320px;overflow:auto;border:1px solid #dcdcde;background:#fff;padding:8px 12px;margin:0;">
				<?php self::checklist_branch( $tree, 0, 0, $current, $skip, $names ); ?>
			</ul>
		</div>
		<?php
		self::checklist_script();
	}

	/**
	 * One level of the checklist, then its children.
	 *
	 * The list is flat (indentation is padding, not nesting) so that hiding
	 * a single item while filtering cannot take its children with it.
	 *
	 * @param array $tree    Terms keyed by parent ID.
	 * @param int   $parent  Parent term ID.
	 * @param int   $depth   Current depth.
	 * @param int[] $current Ticked term IDs.
	 * @param int[] $skip    Term IDs to leave out (with their subtree).
	 * @param array $names   Term names keyed by term ID.
	 */
	protected static function checklist_branch( $tree, $parent, $depth, $current, $skip, $names ) {
		if ( empty( $tree[ $parent ] ) ) {
			return;
		}

		foreach ( $tree[ $parent ] as $term ) {
			$id = (int) $term->term_id;

			if ( in_array( $id, $skip, true ) ) {
				continue;
			}

			// Names repeat across branches ("Helmets" under two brands), and
			// once the list is filtered the indentation says nothing.
			$parent_name = $depth && isset( $names[ $parent ] ) ? $names[ $parent ] : '';
			?>
			<li style="margin:0 0 2px;padding-left:<?php echo esc_attr( $depth * 18 ); ?>px;">
				<label>
					<input type="checkbox" name="px_related_cats[]" value="<?php echo esc_attr( $id ); ?>"
						<?php checked( in_array( $id, $current, true ) ); ?> />
					<?php echo esc_html( $term->name ); ?>
					<?php if ( $parent_name ) : ?>
						<span style="color:#787c82;">— <?php echo esc_html( $parent_name ); ?></span>
					<?php endif; ?>
				</label>
			</li>
			<?php
			self::checklist_branch( $tree, $id, $depth + 1, $current, $skip, $names );
		}
	}

	/**
	 * Search and "selected only" behaviour for the checklist.
	 *
	 * Printed once per screen; the add and the edit form never appear on
	 * the same one, but a filter could put them there.
	 */
	protected static function checklist_script() {
		static $done = false;

		if ( $done ) {
			return;
		}

		$done = true;
		?>
		<script>
		( function () {
			document.querySelectorAll( '.px-related-cats' ).forEach( function ( box ) {
				var search = box.querySelector( '.px-related-cats-search' );
				var only   = box.querySelector( '.px-related-cats-only' );
				var items  = box.querySelectorAll( '.px-related-cats-list > li' );

				items.forEach( function ( item ) {
					item.dataset.pxPad = item.style.paddingLeft;
				} );

				function apply() {
					var needle = search.value.toLowerCase().trim();
					var flat   = needle || only.checked;

					items.forEach( function ( item ) {
						var input = item.querySelector( 'input[type="checkbox"]' );
						var hit   = ! needle || item.textContent.toLowerCase().indexOf( needle ) !== -1;

						if ( only.checked && ! input.checked ) {
							hit = false;
						}

						item.style.display = hit ? '' : 'none';
						// Indentation says nothing once the tree is filtered.
						item.style.paddingLeft = flat ? '0' : item.dataset.pxPad;
					} );
				}

				search.addEventListener( 'input', apply );
				only.addEventListener( 'change', apply );

				// Unticking while "selected only" is on must drop the row.
				box.addEventListener( 'change', function ( event ) {
					if ( only.checked && event.target !== only && 'checkbox' === event.target.type ) {
						apply();
					}
				} );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * @param int $term_id Term ID.
	 */
	public static function save_term( $term_id ) {
		if ( ! isset( $_POST['px_related_cats_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['px_related_cats_nonce'] ), 'px_related_cats_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}

		$term_id = (int) $term_id;
		$posted  = isset( $_POST['px_related_cats'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['px_related_cats'] ) ) : array();
		$skip    = get_term_children( $term_id, self::TAXONOMY );
		$skip    = is_wp_error( $skip ) ? array() : array_map( 'intval', $skip );
		$skip[]  = $term_id;

		$clean = array();

		foreach ( array_unique( $posted ) as $id ) {
			if ( ! $id || in_array( $id, $skip, true ) ) {
				continue;
			}

			$term = get_term( $id, self::TAXONOMY );

			if ( $term && ! is_wp_error( $term ) ) {
				$clean[] = (int) $id;
			}
		}

		if ( $clean ) {
			update_term_meta( $term_id, self::META_TERMS, $clean );
		} else {
			delete_term_meta( $term_id, self::META_TERMS );
		}

		$title = isset( $_POST['px_related_cats_title'] ) ? sanitize_text_field( wp_unslash( $_POST['px_related_cats_title'] ) ) : '';

		if ( '' !== $title ) {
			update_term_meta( $term_id, self::META_TITLE, $title );
		} else {
			delete_term_meta( $term_id, self::META_TITLE );
		}

		self::bump_version();
	}

	/**
	 * Adds the overview column to Products → Categories.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function term_column( $columns ) {
		$columns['px_related_cats'] = __( 'Related', 'px-shop-core' );

		return $columns;
	}

	/**
	 * @param string $content Column content.
	 * @param string $column  Column key.
	 * @param int    $term_id Term ID.
	 * @return string
	 */
	public static function term_column_value( $content, $column, $term_id ) {
		if ( 'px_related_cats' !== $column ) {
			return $content;
		}

		$own = self::stored_terms( $term_id );

		if ( ! $own ) {
			return self::get_term_ids( $term_id ) ? '<span aria-hidden="true">↳</span> ' . esc_html__( 'inherited', 'px-shop-core' ) : '—';
		}

		$names = array();

		foreach ( $own as $id ) {
			$term = get_term( $id, self::TAXONOMY );

			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
			}
		}

		return $names ? esc_html( implode( ', ', $names ) ) : '—';
	}

	/* ------------------------------ Storage ------------------------------ */

	/**
	 * Term IDs stored on a category (no inheritance).
	 *
	 * @param int $term_id Term ID.
	 * @return int[]
	 */
	protected static function stored_terms( $term_id ) {
		$stored = get_term_meta( (int) $term_id, self::META_TERMS, true );

		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'intval', $stored ) ) );
	}

	/**
	 * @param int $term_id Term ID.
	 * @return string
	 */
	protected static function stored_title( $term_id ) {
		return (string) get_term_meta( (int) $term_id, self::META_TITLE, true );
	}
}

/* ------------------------------- Helpers ------------------------------- */

/**
 * Products from the categories related to this product's categories.
 *
 * @param int $product_id Product ID.
 * @param int $limit      How many; 0 uses the setting.
 * @return int[]
 */
function px_related_category_ids( $product_id, $limit = 0 ) {
	if ( ! class_exists( 'PX_Related_Cats' ) ) {
		return array();
	}

	return PX_Related_Cats::get_product_ids( $product_id, $limit );
}
