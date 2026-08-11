<?php
/**
 * Back-in-stock waitlist.
 *
 * A visitor asks to be told when an out-of-stock product is available again.
 * The subscription is double opt-in: the address is only counted once the
 * owner clicks the link in the confirmation e-mail, and every message carries
 * an unsubscribe link.
 *
 * Storage is product meta - one entry per address:
 *
 *     _px_waitlist_emails = [
 *         'a@b.sk' => [
 *             'created'     => 1723380000,
 *             'confirmed'   => 1723380500,   // 0 while pending
 *             'confirm'     => 'token',      // '' once confirmed
 *             'unsubscribe' => 'token',
 *             'user_id'     => 12,
 *         ],
 *     ]
 *
 * Variations are products too - a variation waitlist lives on the variation
 * ID, which is why everything below works with a plain post ID.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Waitlist {

	const META_KEY = '_px_waitlist_emails';
	const MAX      = 500;

	/** How long before an unconfirmed subscription may trigger a new e-mail. */
	const RESEND_AFTER = HOUR_IN_SECONDS;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'maybe_notify' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'maybe_notify' ), 10, 3 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_metabox' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_link' ) );
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_emails' ) );
	}

	/* ------------------------------ Storage ------------------------------ */

	/**
	 * Subscribers of a product, normalized.
	 *
	 * Entries written by version 1.0 were a bare timestamp; those are read as
	 * confirmed, because back then subscribing was the whole flow.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return array email => entry
	 */
	public static function get_subscribers( $product_id ) {
		$stored = get_post_meta( $product_id, self::META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$subscribers = array();
		foreach ( $stored as $email => $entry ) {
			$subscribers[ $email ] = self::normalize_entry( $entry );
		}

		return $subscribers;
	}

	/**
	 * @param mixed $entry Stored value.
	 * @return array
	 */
	protected static function normalize_entry( $entry ) {
		if ( ! is_array( $entry ) ) {
			$time = (int) $entry;

			return array(
				'created'     => $time,
				'confirmed'   => $time,
				'confirm'     => '',
				'unsubscribe' => '',
				'user_id'     => 0,
			);
		}

		return wp_parse_args(
			$entry,
			array(
				'created'     => 0,
				'confirmed'   => 0,
				'confirm'     => '',
				'unsubscribe' => '',
				'user_id'     => 0,
			)
		);
	}

	/**
	 * @param int   $product_id  Product ID.
	 * @param array $subscribers email => entry.
	 */
	protected static function save( $product_id, $subscribers ) {
		if ( $subscribers ) {
			update_post_meta( $product_id, self::META_KEY, $subscribers );
		} else {
			delete_post_meta( $product_id, self::META_KEY );
		}
	}

	/**
	 * Number of people waiting - by default only the confirmed ones, because
	 * those are the only ones who will actually be e-mailed.
	 *
	 * @param int  $product_id Product ID.
	 * @param bool $confirmed_only Count confirmed subscribers only.
	 * @return int
	 */
	public static function count( $product_id, $confirmed_only = true ) {
		$subscribers = self::get_subscribers( $product_id );

		if ( ! $confirmed_only ) {
			return count( $subscribers );
		}

		return count( array_filter( wp_list_pluck( $subscribers, 'confirmed' ) ) );
	}

	/* ---------------------------- Subscribing ---------------------------- */

	/**
	 * Add an address to a product's waitlist and send the confirmation mail.
	 *
	 * Repeated calls are harmless: a confirmed address is left alone, and a
	 * pending one only gets another e-mail once RESEND_AFTER has passed.
	 *
	 * @param int    $product_id Product or variation ID.
	 * @param string $email      Address.
	 * @return true|WP_Error
	 */
	public static function subscribe( $product_id, $email ) {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'px_invalid_email', __( 'Please enter a valid e-mail address.', 'px-shop-core' ), array( 'status' => 400 ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'px_invalid_product', __( 'Invalid product.', 'px-shop-core' ), array( 'status' => 404 ) );
		}

		$subscribers = self::get_subscribers( $product_id );
		$existing    = isset( $subscribers[ $email ] ) ? $subscribers[ $email ] : null;

		if ( $existing && $existing['confirmed'] ) {
			return true;
		}
		if ( $existing && ( time() - (int) $existing['created'] ) < self::RESEND_AFTER ) {
			return true; // Confirmation mail already on its way - do not flood.
		}
		if ( ! $existing && count( $subscribers ) >= self::MAX ) {
			return new WP_Error( 'px_waitlist_full', __( 'The waitlist for this product is full.', 'px-shop-core' ), array( 'status' => 409 ) );
		}

		$entry = array(
			'created'     => time(),
			'confirmed'   => 0,
			'confirm'     => self::make_token( $product_id ),
			'unsubscribe' => $existing ? $existing['unsubscribe'] : self::make_token( $product_id ),
			'user_id'     => get_current_user_id(),
		);

		if ( ! apply_filters( 'px_waitlist_require_confirmation', true, $email, $product_id ) ) {
			$entry['confirmed'] = time();
			$entry['confirm']   = '';
		}

		$subscribers[ $email ] = $entry;
		self::save( $product_id, $subscribers );

		if ( $entry['confirmed'] ) {
			/** Address is on the list for good. */
			do_action( 'px_waitlist_confirmed', $email, $product, $entry );
		} else {
			/** Address needs to confirm - the confirmation e-mail hangs on this. */
			do_action( 'px_waitlist_subscribed', $email, $product, $entry );
		}

		return true;
	}

	/**
	 * Turn a pending subscription into a confirmed one.
	 *
	 * @param string $token Confirmation token.
	 * @return array|WP_Error { product_id, email }
	 */
	public static function confirm( $token ) {
		$found = self::find_by_token( $token, 'confirm' );
		if ( is_wp_error( $found ) ) {
			return $found;
		}

		list( $product_id, $email, $subscribers ) = array( $found['product_id'], $found['email'], $found['subscribers'] );

		$subscribers[ $email ]['confirmed'] = time();
		$subscribers[ $email ]['confirm']   = '';
		self::save( $product_id, $subscribers );

		do_action( 'px_waitlist_confirmed', $email, wc_get_product( $product_id ), $subscribers[ $email ] );

		return array(
			'product_id' => $product_id,
			'email'      => $email,
		);
	}

	/**
	 * Remove an address from the waitlist.
	 *
	 * The address is dropped from every product, not just the one the link
	 * came from - somebody clicking "unsubscribe" means "stop e-mailing me",
	 * not "stop e-mailing me about this one item".
	 *
	 * No blocklist is kept. Double opt-in already means nobody but the owner
	 * of the address can put it back on a list.
	 *
	 * @param string $token Unsubscribe token.
	 * @return array|WP_Error { product_id, email, removed }
	 */
	public static function unsubscribe( $token ) {
		$found = self::find_by_token( $token, 'unsubscribe' );
		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$email   = $found['email'];
		$removed = self::remove_everywhere( $email );

		do_action( 'px_waitlist_unsubscribed', $email, wc_get_product( $found['product_id'] ) );

		return array(
			'product_id' => $found['product_id'],
			'email'      => $email,
			'removed'    => $removed,
		);
	}

	/**
	 * Drop an address from every waitlist.
	 *
	 * @param string $email Address.
	 * @return int Number of products it was on.
	 */
	public static function remove_everywhere( $email ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
				self::META_KEY,
				'%' . $wpdb->esc_like( $email ) . '%'
			)
		);

		$removed = 0;
		foreach ( $ids as $product_id ) {
			$subscribers = self::get_subscribers( $product_id );
			if ( ! isset( $subscribers[ $email ] ) ) {
				continue; // LIKE matched a substring of another address.
			}

			unset( $subscribers[ $email ] );
			self::save( $product_id, $subscribers );
			++$removed;
		}

		return $removed;
	}

	/* ------------------------------ Tokens ------------------------------- */

	/**
	 * Token carrying its product, so a link can be resolved without scanning
	 * the whole catalog.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	protected static function make_token( $product_id ) {
		return $product_id . '.' . wp_generate_password( 20, false );
	}

	/**
	 * @param string $token Token from a link.
	 * @param string $type  'confirm' or 'unsubscribe'.
	 * @return array|WP_Error { product_id, email, subscribers }
	 */
	protected static function find_by_token( $token, $type ) {
		$token = (string) $token;
		$parts = explode( '.', $token, 2 );

		$invalid = new WP_Error( 'px_waitlist_bad_token', __( 'This link is no longer valid.', 'px-shop-core' ), array( 'status' => 404 ) );

		if ( 2 !== count( $parts ) || ! absint( $parts[0] ) ) {
			return $invalid;
		}

		$product_id  = absint( $parts[0] );
		$subscribers = self::get_subscribers( $product_id );

		foreach ( $subscribers as $email => $entry ) {
			if ( '' !== $entry[ $type ] && hash_equals( (string) $entry[ $type ], $token ) ) {
				return array(
					'product_id'  => $product_id,
					'email'       => $email,
					'subscribers' => $subscribers,
				);
			}
		}

		return $invalid;
	}

	/**
	 * Link that confirms or cancels a subscription.
	 *
	 * @param string $action  'confirm' or 'unsubscribe'.
	 * @param string $token   Token.
	 * @param int    $product_id Product the link points back to.
	 * @return string
	 */
	public static function get_link( $action, $token, $product_id ) {
		$base = get_permalink( $product_id );
		if ( ! $base ) {
			$base = home_url( '/' );
		}

		return add_query_arg(
			array(
				'px_waitlist' => $action,
				'token'       => rawurlencode( $token ),
			),
			$base
		);
	}

	/**
	 * Handle a click on a confirm/unsubscribe link.
	 */
	public static function handle_link() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- tokens from e-mail links, there is no session to nonce against.
		if ( empty( $_GET['px_waitlist'] ) || empty( $_GET['token'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['px_waitlist'] ) );
		$token  = sanitize_text_field( wp_unslash( $_GET['token'] ) );
		// phpcs:enable

		if ( ! in_array( $action, array( 'confirm', 'unsubscribe' ), true ) ) {
			return;
		}

		$result = 'confirm' === $action ? self::confirm( $token ) : self::unsubscribe( $token );

		if ( is_wp_error( $result ) ) {
			self::notice( $result->get_error_message(), 'error' );
			$target = home_url( '/' );
		} else {
			$target = get_permalink( $result['product_id'] ) ? get_permalink( $result['product_id'] ) : home_url( '/' );

			self::notice(
				'confirm' === $action
					? __( 'Done - we will e-mail you as soon as the product is back in stock.', 'px-shop-core' )
					: __( 'Done - we will not e-mail you about product availability again.', 'px-shop-core' ),
				'success'
			);
		}

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * @param string $message Text.
	 * @param string $type    'success' or 'error'.
	 */
	protected static function notice( $message, $type ) {
		if ( function_exists( 'wc_add_notice' ) && WC()->session ) {
			wc_add_notice( $message, 'error' === $type ? 'error' : 'success' );
		}
	}

	/* ------------------------------- REST -------------------------------- */

	public static function register_routes() {
		register_rest_route( 'px-shop-core/v1', '/waitlist', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => '__return_true',
			'args'                => array(
				'product_id' => array( 'required' => true, 'type' => 'integer' ),
				'email'      => array( 'required' => true, 'type' => 'string' ),
			),
			'callback'            => array( __CLASS__, 'rest_subscribe' ),
		) );
	}

	public static function rest_subscribe( WP_REST_Request $request ) {
		$product = wc_get_product( (int) $request['product_id'] );

		if ( ! $product || ! in_array( $product->get_status(), array( 'publish', 'private' ), true ) ) {
			return new WP_Error( 'px_invalid_product', __( 'Invalid product.', 'px-shop-core' ), array( 'status' => 404 ) );
		}
		if ( $product->is_in_stock() ) {
			return new WP_Error( 'px_in_stock', __( 'This product is already in stock.', 'px-shop-core' ), array( 'status' => 400 ) );
		}

		$result = self::subscribe( $product->get_id(), (string) $request['email'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'ok'      => true,
			'message' => apply_filters( 'px_waitlist_require_confirmation', true, (string) $request['email'], $product->get_id() )
				? __( 'Almost there - open the e-mail we just sent and confirm your address.', 'px-shop-core' )
				: __( 'Thank you! We will e-mail you as soon as the product is back in stock.', 'px-shop-core' ),
		);
	}

	/* ---------------------------- Notification ---------------------------- */

	/**
	 * Runs on every stock status change; acts only on -> instock.
	 *
	 * Confirmed subscribers are notified and removed. Pending ones stay: their
	 * confirmation link keeps working, and they will be told next time.
	 *
	 * @param int        $product_id   Product ID.
	 * @param string     $stock_status New status.
	 * @param WC_Product $product      Product.
	 */
	public static function maybe_notify( $product_id, $stock_status, $product = null ) {
		if ( 'instock' !== $stock_status ) {
			return;
		}

		$subscribers = self::get_subscribers( $product_id );
		if ( ! $subscribers ) {
			return;
		}

		$product = $product instanceof WC_Product ? $product : wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$left = array();
		foreach ( $subscribers as $email => $entry ) {
			if ( ! $entry['confirmed'] ) {
				$left[ $email ] = $entry;
				continue;
			}

			/** The back-in-stock e-mail hangs on this. */
			do_action( 'px_waitlist_back_in_stock', $email, $product, $entry );
		}

		self::save( $product_id, $left );
	}

	/* ------------------------------- Form -------------------------------- */

	/**
	 * Sign-up form for an out-of-stock product. Prints nothing when the
	 * product is available or cannot be waited for.
	 *
	 * Markup is deliberately plain (px-* classes, no styling beyond layout) -
	 * a theme either styles it or renders its own form against the REST
	 * endpoint px-shop-core/v1/waitlist.
	 *
	 * @param int|WC_Product|null $product Product, ID, or null for the global one.
	 */
	public static function render_form( $product = null ) {
		echo self::get_form_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * @param int|WC_Product|null $given Product, ID, or null for the global one.
	 * @return string
	 */
	public static function get_form_html( $given = null ) {
		if ( $given instanceof WC_Product ) {
			$product = $given;
		} elseif ( is_numeric( $given ) ) {
			$product = wc_get_product( (int) $given );
		} else {
			global $product;
		}

		if ( ! $product instanceof WC_Product || $product->is_in_stock() ) {
			return '';
		}
		if ( ! apply_filters( 'px_waitlist_show_form', true, $product ) ) {
			return '';
		}

		$user_email = is_user_logged_in() ? wp_get_current_user()->user_email : '';

		ob_start();
		echo self::form_assets(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<form class="px-waitlist" data-product="<?php echo esc_attr( $product->get_id() ); ?>">
			<p class="px-waitlist__intro"><?php esc_html_e( 'Out of stock. Leave your e-mail and we will tell you when it is back.', 'px-shop-core' ); ?></p>
			<div class="px-waitlist__row">
				<label class="screen-reader-text" for="px-waitlist-email-<?php echo esc_attr( $product->get_id() ); ?>"><?php esc_html_e( 'Your e-mail', 'px-shop-core' ); ?></label>
				<input
					type="email"
					id="px-waitlist-email-<?php echo esc_attr( $product->get_id() ); ?>"
					class="px-waitlist__email"
					name="email"
					required
					value="<?php echo esc_attr( $user_email ); ?>"
					placeholder="<?php esc_attr_e( 'your@email.com', 'px-shop-core' ); ?>"
				/>
				<button type="submit" class="px-waitlist__submit"><?php esc_html_e( 'Notify me', 'px-shop-core' ); ?></button>
			</div>
			<p class="px-waitlist__message" role="status" aria-live="polite"></p>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Inline styles and script for the form, printed once per request.
	 *
	 * @return string
	 */
	protected static function form_assets() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;

		$css = '
.px-waitlist{margin:16px 0}
.px-waitlist__intro{margin:0 0 8px}
.px-waitlist__row{display:flex;gap:8px;flex-wrap:wrap}
.px-waitlist__email{flex:1 1 12rem;min-width:0}
.px-waitlist__message{margin:8px 0 0}
.px-waitlist__message:empty{display:none}
.px-waitlist--done .px-waitlist__row{display:none}
';

		$js = sprintf(
			'
document.addEventListener("submit",function(e){
var f=e.target.closest(".px-waitlist");
if(!f){return;}
e.preventDefault();
var b=f.querySelector(".px-waitlist__submit"),m=f.querySelector(".px-waitlist__message");
b.disabled=true;
fetch("%s",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({product_id:parseInt(f.dataset.product,10),email:f.querySelector(".px-waitlist__email").value})})
.then(function(r){return r.json();})
.then(function(d){
b.disabled=false;
m.textContent=d&&d.message?d.message:(d&&d.ok?"":%s);
if(d&&d.ok){f.classList.add("px-waitlist--done");}
})
.catch(function(){b.disabled=false;m.textContent=%s;});
});
',
			esc_url_raw( rest_url( 'px-shop-core/v1/waitlist' ) ),
			wp_json_encode( __( 'Something went wrong, please try again.', 'px-shop-core' ) ),
			wp_json_encode( __( 'Something went wrong, please try again.', 'px-shop-core' ) )
		);

		return '<style id="px-waitlist-css">' . $css . '</style><script id="px-waitlist-js">' . $js . '</script>';
	}

	/* ------------------------------ E-mails ------------------------------- */

	/**
	 * @param array $emails WooCommerce e-mail classes.
	 * @return array
	 */
	public static function register_emails( $emails ) {
		require_once PX_SHOP_CORE_DIR . 'includes/emails/class-px-email-waitlist.php';
		require_once PX_SHOP_CORE_DIR . 'includes/emails/class-px-email-waitlist-confirm.php';
		require_once PX_SHOP_CORE_DIR . 'includes/emails/class-px-email-waitlist-subscribed.php';
		require_once PX_SHOP_CORE_DIR . 'includes/emails/class-px-email-waitlist-in-stock.php';
		require_once PX_SHOP_CORE_DIR . 'includes/emails/class-px-email-waitlist-admin.php';

		$emails['PX_Email_Waitlist_Confirm']    = new PX_Email_Waitlist_Confirm();
		$emails['PX_Email_Waitlist_Subscribed'] = new PX_Email_Waitlist_Subscribed();
		$emails['PX_Email_Waitlist_In_Stock']   = new PX_Email_Waitlist_In_Stock();
		$emails['PX_Email_Waitlist_Admin']      = new PX_Email_Waitlist_Admin();

		return $emails;
	}

	/* ------------------------------- Admin -------------------------------- */

	public static function register_metabox() {
		global $post;

		if ( ! $post || 'product' !== $post->post_type || ! self::get_subscribers( $post->ID ) ) {
			return;
		}

		add_meta_box(
			'px-waitlist',
			__( 'Waitlist', 'px-shop-core' ),
			array( __CLASS__, 'render_metabox' ),
			'product',
			'side'
		);
	}

	public static function render_metabox( $post ) {
		$subscribers = self::get_subscribers( $post->ID );
		$confirmed   = self::count( $post->ID );

		echo '<p>' . esc_html( sprintf(
			/* translators: %d: number of subscribers. */
			__( 'Customers waiting for this product: %d', 'px-shop-core' ),
			$confirmed
		) ) . '</p><ul style="margin:0;">';

		foreach ( $subscribers as $email => $entry ) {
			$date = $entry['confirmed'] ? $entry['confirmed'] : $entry['created'];

			printf(
				'<li><code>%s</code> <span style="color:#787c82;">%s</span>%s</li>',
				esc_html( $email ),
				esc_html( $date ? date_i18n( get_option( 'date_format' ), $date ) : '' ),
				$entry['confirmed'] ? '' : ' <em>' . esc_html__( '(not confirmed)', 'px-shop-core' ) . '</em>'
			);
		}

		echo '</ul>';
	}
}
