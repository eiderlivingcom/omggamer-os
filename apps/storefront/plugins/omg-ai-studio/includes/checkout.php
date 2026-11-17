<?php
/**
 * Focused checkout: no site header or footer, a slim bar with the logo and the
 * Cart → Checkout → Delivery steps, numbered steps and an order summary card.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OMG_PS_Checkout {

	const PRO_PRODUCT = 1906;

	public static function init() {
		$s = new self();
		add_filter( 'bricks/active_templates', array( $s, 'no_header_footer' ), 30, 3 );
		add_action( 'wp_body_open', array( $s, 'topbar' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $s, 'assets' ), 40 );
		add_filter( 'body_class', array( $s, 'body_class' ) );
	}

	private function on() {
		return function_exists( 'is_checkout' ) && is_checkout() && ! ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() );
	}

	private function done() {
		return function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) );
	}

	public function body_class( $c ) {
		if ( $this->on() ) {
			$c[] = 'omgx-on';
			if ( $this->done() ) {
				$c[] = 'omgx-done';
			}
		}
		return $c;
	}

	public function no_header_footer( $active, $post_id, $content_type ) {
		if ( is_array( $active ) && $this->on() ) {
			$active['header'] = 0;
			$active['footer'] = 0;
		}
		return $active;
	}

	public function topbar() {
		if ( ! $this->on() ) {
			return;
		}
		$done  = $this->done();
		$check = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9.5" stroke-width="2"/><path d="m8 12.5 2.6 2.5L16 9.5"/></svg>';
		$steps = array(
			array( 'Cart', wc_get_cart_url(), 'done' ),
			array( 'Checkout', '', $done ? 'done' : 'now' ),
			array( 'Delivery', '', $done ? 'now' : 'next' ),
		);
		echo '<header class="omgx-bar"><div class="omgx-bar-in">';
		echo '<a class="omgx-logo" href="' . esc_url( home_url( '/' ) ) . '" aria-label="OMGGamer home"><span class="mk">OMG</span><span class="wm">OMG<b>Gamer</b></span></a>';
		echo '<ol class="omgx-steps" aria-label="Checkout progress">';
		foreach ( $steps as $i => $st ) {
			$n   = 'done' === $st[2] ? $check : (string) ( $i + 1 );
			$in  = '<span class="n">' . $n . '</span><span class="l">' . esc_html( $st[0] ) . '</span>';
			$cls = 'is-' . $st[2];
			echo '<li class="' . esc_attr( $cls ) . '"' . ( 'now' === $st[2] ? ' aria-current="step"' : '' ) . '>' . ( $st[1] && 'done' === $st[2] && ! $done ? '<a href="' . esc_url( $st[1] ) . '">' . $in . '</a>' : $in ) . '</li>';
			if ( $i < 2 ) {
				echo '<li class="ln" aria-hidden="true"></li>';
			}
		}
		echo '</ol>';
		echo '<div class="omgx-loc"><span class="flag" aria-hidden="true"></span>' . esc_html( get_woocommerce_currency() ) . ' · English</div>';
		echo '</div></header>';
	}

	public function assets() {
		if ( ! $this->on() ) {
			return;
		}
		wp_enqueue_style( 'omgx', OMG_PS_URL . 'assets/checkout.css', array(), OMG_PS_VERSION );
		if ( $this->done() ) {
			return;
		}
		wp_enqueue_script( 'omgx', OMG_PS_URL . 'assets/checkout.js', array( 'wp-data' ), OMG_PS_VERSION, true );
		$pro = array();
		$pro_member = function_exists( 'wcs_user_has_subscription' ) && is_user_logged_in() && wcs_user_has_subscription( get_current_user_id(), self::PRO_PRODUCT, 'active' );
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$pid = ! empty( $item['product_id'] ) ? (int) $item['product_id'] : 0;
				$pp  = $pid ? get_post_meta( $pid, 'omg_pro_price', true ) : '';
				if ( '' !== $pp ) {
					$pro[ $pid ] = (float) $pp;
				}
			}
		}
		$types = array();
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$pid  = (int) $item['product_id'];
				$kind = OMG_PS::kind( $pid );
				$slug = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'slugs' ) );
				$slug = is_array( $slug ) ? $slug : array();
				$types[ $pid ] = 'digital' !== $kind ? ( 'hardware' === $kind ? 'Hardware' : 'Accessory' ) : ( in_array( 'game-accounts', $slug, true ) ? 'Account' : ( in_array( 'gift-cards', $slug, true ) ? 'Gift card' : ( self::PRO_PRODUCT === $pid ? 'Membership' : 'Game' ) ) );
			}
		}
		wp_localize_script( 'omgx', 'OMGX', array(
			'pro'       => $pro,
			'types'     => $types,
			'proId'     => self::PRO_PRODUCT,
			'proPrice'  => (float) get_post_meta( self::PRO_PRODUCT, '_price', true ),
			'proMember' => $pro_member,
			'proUrl'    => home_url( '/pro/' ),
			'terms'     => function_exists( 'wc_terms_and_conditions_page_id' ) && wc_terms_and_conditions_page_id() ? get_permalink( wc_terms_and_conditions_page_id() ) : home_url( '/terms/' ),
			'privacy'   => get_privacy_policy_url() ? get_privacy_policy_url() : home_url( '/privacy/' ),
		) );
	}
}
