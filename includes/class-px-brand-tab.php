<?php
/**
 * "About the brand" product tab.
 *
 * When a product has a product_brand term assigned and that term has
 * a description, a tab is added to the single product page showing
 * the brand name, logo and description. Markup is neutral (px-* classes);
 * styling belongs to the theme.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Brand_Tab {

	public static function init() {
		add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'register_tab' ), 20 );
	}

	/**
	 * Brand terms of the product that actually have a description.
	 *
	 * @param WC_Product|null $product Product.
	 * @return WP_Term[]
	 */
	public static function get_brands_with_description( $product ) {
		if ( ! $product || ! taxonomy_exists( 'product_brand' ) ) {
			return array();
		}

		$terms = get_the_terms( $product->get_id(), 'product_brand' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}

		return array_values( array_filter( $terms, function ( $term ) {
			return '' !== trim( $term->description );
		} ) );
	}

	public static function register_tab( $tabs ) {
		global $product;

		$brands = self::get_brands_with_description( $product );
		if ( empty( $brands ) ) {
			return $tabs;
		}

		$tabs['px_brand'] = array(
			'title'    => 1 === count( $brands )
				/* translators: %s: brand name. */
				? sprintf( __( 'About %s', 'px-shop-core' ), $brands[0]->name )
				: __( 'About the brand', 'px-shop-core' ),
			'priority' => 25,
			'callback' => array( __CLASS__, 'render_tab' ),
		);

		return $tabs;
	}

	public static function render_tab() {
		global $product;

		foreach ( self::get_brands_with_description( $product ) as $brand ) {
			$logo_id    = (int) get_term_meta( $brand->term_id, 'thumbnail_id', true );
			$brand_link = get_term_link( $brand );
			?>
			<section class="px-brand-tab">
				<header class="px-brand-tab__header">
					<?php
					if ( $logo_id ) {
						echo wp_get_attachment_image( $logo_id, 'medium', false, array(
							'class' => 'px-brand-tab__logo',
							'alt'   => $brand->name,
						) );
					}
					?>
					<h2 class="px-brand-tab__name"><?php echo esc_html( $brand->name ); ?></h2>
				</header>

				<div class="px-brand-tab__desc"><?php echo wp_kses_post( wpautop( $brand->description ) ); ?></div>

				<?php if ( ! is_wp_error( $brand_link ) ) : ?>
					<a class="px-brand-tab__link" href="<?php echo esc_url( $brand_link ); ?>">
						<?php
						/* translators: %s: brand name. */
						printf( esc_html__( 'All products by %s', 'px-shop-core' ), esc_html( $brand->name ) );
						?>
					</a>
				<?php endif; ?>
			</section>
			<?php
		}
	}
}
