<?php
/**
 * Cart drawer: replaces the "has been added to your cart" notice with a slide-in cart.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OMG_PS_Drawer {

	const FREE_GAME_TARGET = 80;

	public static function init() {
		$s = new self();
		add_filter( 'wc_add_to_cart_message_html', array( $s, 'silence' ), 99 );
		add_filter( 'option_woocommerce_cart_redirect_after_add', array( $s, 'no_redirect' ) );
		add_action( 'woocommerce_add_to_cart', array( $s, 'flag' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( $s, 'assets' ), 30 );
		add_action( 'wp_footer', array( $s, 'markup' ), 5 );
	}

	private function active() {
		return ! is_admin() && function_exists( 'is_cart' ) && ! is_cart() && ! is_checkout() && ! ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() );
	}

	/** No green "added to cart" banner; the drawer opens instead. */
	public function silence( $message ) {
		return '';
	}

	public function no_redirect( $v ) {
		return 'no';
	}

	/** Non-AJAX add to cart (page reload): open the drawer on the next page view. */
	public function flag( $key, $product_id ) {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! WC()->session ) {
			return;
		}
		WC()->session->set( 'omg_drawer_open', $key );
	}

	public function assets() {
		// Phones and tablets: the header scrolls away with the page (no sticky header).
		wp_register_style( 'omgps-global', false, array(), OMG_PS_VERSION );
		wp_enqueue_style( 'omgps-global' );
		wp_add_inline_style( 'omgps-global', '@media (max-width:1024px){#brx-header,#brx-header.sticky,#brx-header.brx-sticky{position:relative!important;top:auto!important;transform:none!important}}' );
		if ( function_exists( 'is_product' ) && is_product() ) {
			wp_enqueue_script( 'omgps-mbar', OMG_PS_URL . 'assets/mbar.js', array( 'jquery' ), OMG_PS_VERSION, true );
		}
		if ( ! $this->active() ) {
			return;
		}
		wp_enqueue_style( 'omgps-drawer', OMG_PS_URL . 'assets/drawer.css', array(), OMG_PS_VERSION );
		wp_enqueue_script( 'omgps-drawer', OMG_PS_URL . 'assets/drawer.js', array( 'jquery' ), OMG_PS_VERSION, true );
		$open = '';
		if ( WC()->session ) {
			$open = (string) WC()->session->get( 'omg_drawer_open', '' );
			if ( $open ) {
				WC()->session->set( 'omg_drawer_open', '' );
			}
		}
		wp_localize_script( 'omgps-drawer', 'OMGDRAWER', array(
			'api'      => esc_url_raw( rest_url( 'wc/store/v1/' ) ),
			'nonce'    => wp_create_nonce( 'wc_store_api' ),
			'cart'     => wc_get_cart_url(),
			'checkout' => wc_get_checkout_url(),
			'shop'     => wc_get_page_permalink( 'shop' ),
			'target'   => self::FREE_GAME_TARGET,
			'open'     => $open,
		) );
	}

	public function markup() {
		if ( ! $this->active() ) {
			return;
		}
		?>
		<div class="omgd" id="omgd" aria-hidden="true">
			<div class="omgd-veil" data-omgd-close></div>
			<aside class="omgd-panel" role="dialog" aria-modal="true" aria-labelledby="omgd-title" tabindex="-1">
				<header class="omgd-head">
					<div><h2 id="omgd-title">Your cart <span class="omgd-n"></span></h2><p class="omgd-sub">Secure checkout · Buyer protection on every order</p></div>
					<button type="button" class="omgd-x" data-omgd-close aria-label="Close cart"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
				</header>
				<div class="omgd-added" hidden><span class="ic"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span><span class="t">Added to your cart</span></div>
				<div class="omgd-free"><div class="row"><span class="txt"></span><b class="pct"></b></div><div class="bar"><i></i></div></div>
				<div class="omgd-body" aria-live="polite"><div class="omgd-skel"><i></i><i></i><i></i></div></div>
				<section class="omgd-recs" hidden><h3>Complete your order</h3><div class="list"></div></section>
				<footer class="omgd-foot" hidden>
					<div class="omgd-line save" hidden><span>You save</span><b class="v"></b></div>
					<div class="omgd-line total"><span>Subtotal</span><b class="v"></b></div>
					<p class="omgd-note"></p>
					<a class="omgd-checkout" href="<?php echo esc_url( wc_get_checkout_url() ); ?>"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>Secure checkout</a>
					<a class="omgd-view" href="<?php echo esc_url( wc_get_cart_url() ); ?>">View full cart</a>
					<div class="omgd-pay"><span>VISA</span><span>MC</span><span>PayPal</span><span>Apple Pay</span><span>G Pay</span></div>
				</footer>
			</aside>
		</div>
		<?php
	}
}
