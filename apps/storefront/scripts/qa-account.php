<?php
/**
 * QA customer for checking the account pages with realistic orders.
 *
 *   wp eval-file qa-account.php setup     creates the QA customer + sample orders, prints a login cookie
 *   wp eval-file qa-account.php cookie    prints a fresh login cookie for the QA customer
 *   wp eval-file qa-account.php cleanup   deletes the sample orders and the QA customer
 *
 * The sample orders are set to "completed" directly (never "processing" / paid), so they are never sent to
 * OMGGamer OS and nothing is bought from a supplier. Emails go to an .invalid address. Products are real
 * store products so the pages show real images and names; stock is not touched.
 */

$cmd   = $args[0] ?? 'cookie';
$login = 'omg_qa_account';
$email = 'qa-account@omggamer.invalid';

function omg_qa_user( $login ) {
	$u = get_user_by( 'login', $login );
	return $u ? $u->ID : 0;
}

function omg_qa_orders( $uid ) {
	return $uid ? wc_get_orders( array( 'customer_id' => $uid, 'limit' => -1, 'return' => 'ids', 'meta_key' => '_omg_qa', 'meta_value' => '1' ) ) : array();
}

if ( 'cleanup' === $cmd ) {
	$uid = omg_qa_user( $login );
	foreach ( omg_qa_orders( $uid ) as $oid ) {
		$o = wc_get_order( $oid );
		if ( $o ) {
			$o->delete( true );
		}
	}
	if ( $uid ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $uid );
	}
	WP_CLI::success( 'QA customer and orders removed.' );
	return;
}

if ( 'setup' === $cmd ) {
	$uid = omg_qa_user( $login );
	if ( ! $uid ) {
		$uid = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password( 32 ), 'role' => 'customer', 'first_name' => 'QA', 'last_name' => 'Tester', 'display_name' => 'qa-tester' ) );
		if ( is_wp_error( $uid ) ) {
			WP_CLI::error( $uid->get_error_message() );
		}
	}
	// No customer emails for QA orders.
	add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
	add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );

	$pick = static function ( $physical ) {
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 60, 'fields' => 'ids', 'orderby' => 'rand' ) );
		foreach ( $ids as $id ) {
			$k = class_exists( 'OMG_SEO' ) ? OMG_SEO::physical( $id ) : '';
			if ( (bool) $k === $physical && 1906 !== $id ) {
				$p = wc_get_product( $id );
				if ( $p && $p->get_image_id() ) {
					return $p;
				}
			}
		}
		return null;
	};
	$make = static function ( array $products, array $keys, $uid, $email ) {
		$o = wc_create_order( array( 'customer_id' => $uid, 'status' => 'pending', 'created_via' => 'omg-qa' ) );
		foreach ( $products as $i => $p ) {
			$add = $p->is_type( 'variable' ) ? wc_get_product( $p->get_children()[0] ?? 0 ) : $p;
			$item_id = $o->add_product( $add ?: $p, 1 );
			if ( isset( $keys[ $i ] ) ) {
				wc_update_order_item_meta( $item_id, '_omg_keys', $keys[ $i ] );
			}
		}
		$o->set_billing_email( $email );
		$o->set_billing_first_name( 'QA' );
		$o->set_billing_last_name( 'Tester' );
		$o->set_billing_country( 'US' );
		$o->set_payment_method( 'stripe' );
		$o->set_payment_method_title( 'Credit / Debit Card' );
		$o->set_transaction_id( 'pi_QA' . wp_generate_password( 14, false ) );
		$o->update_meta_data( '_omg_qa', '1' );
		$o->calculate_totals();
		$o->save();
		$o->update_status( 'completed', 'QA sample order (not a real order).' );
		return $o->get_id();
	};
	$png = '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';
	$digital = array_filter( array( $pick( false ), $pick( false ), $pick( false ), $pick( false ) ) );
	$digital = array_values( $digital );
	$keys = array(
		array( array( 'type' => 'text/plain', 'serial' => 'QA5XS-HCTLE-QQ5RF-6U7Y9' ) ),
		array( array( 'type' => 'text/plain', 'serial' => "Login: qa.player@example.com\nPassword: Qa-Pass-123\nEmail password: Mail-Pass-456" ) ),
		array( array( 'type' => 'text/plain', 'serial' => 'https://store.steampowered.com/account/ackgift/QA1234567890?redeemer=qa' ) ),
		array( array( 'type' => 'image/png', 'serial' => $png ) ),
	);
	$made = array( $make( $digital, $keys, $uid, $email ) );
	$hw = $pick( true );
	if ( $hw ) {
		$made[] = $make( array( $hw ), array(), $uid, $email );
	}
	WP_CLI::log( 'QA orders: ' . implode( ', ', $made ) );
	$cmd = 'cookie';
}

if ( 'cookie' === $cmd ) {
	$uid = omg_qa_user( $login );
	if ( ! $uid ) {
		WP_CLI::error( 'No QA customer: run setup first.' );
	}
	$exp = time() + 3 * HOUR_IN_SECONDS;
	WP_CLI::log( 'COOKIE ' . LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie( $uid, $exp, 'logged_in' ) );
	WP_CLI::log( 'COOKIE ' . SECURE_AUTH_COOKIE . '=' . wp_generate_auth_cookie( $uid, $exp, 'secure_auth' ) );
}
