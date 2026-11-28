<?php
/**
 * OMG PRO as a WooCommerce Subscriptions product (monthly membership).
 *
 *   wp eval-file pro-subscription.php          shows the PRO product's current setup
 *   wp eval-file pro-subscription.php apply    makes it a monthly subscription
 *
 * Setup: 6.99 / month (store base currency), first month for new members = 1-month trial + 0.99 sign-up fee,
 * so a new member pays 0.99 today and 6.99 a month from the second month. Returning members get no trial and
 * no fee (omg-pro-offer.php decides who is new). Virtual, sold one at a time, never ends until cancelled.
 */

$apply = isset( $args[0] ) && 'apply' === $args[0];
$id    = class_exists( 'OMG_Pro_Offer' ) ? OMG_Pro_Offer::PRODUCT : 1906;
$p     = wc_get_product( $id );
if ( ! $p ) {
	WP_CLI::error( "PRO product #$id not found." );
}
if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
	WP_CLI::error( 'WooCommerce Subscriptions is not active.' );
}
$keys = array( '_subscription_price', '_subscription_period', '_subscription_period_interval', '_subscription_length', '_subscription_trial_length', '_subscription_trial_period', '_subscription_sign_up_fee', '_regular_price', '_sale_price', '_price', '_virtual', '_sold_individually' );
WP_CLI::log( "#$id " . $p->get_name() . ' — type: ' . $p->get_type() . ', status: ' . $p->get_status() );
foreach ( $keys as $k ) {
	WP_CLI::log( "  $k = " . var_export( get_post_meta( $id, $k, true ), true ) );
}
if ( ! $apply ) {
	WP_CLI::log( 'Dry run. Add "apply" to make it a monthly subscription.' );
	return;
}

$monthly = class_exists( 'OMG_Pro_Offer' ) ? OMG_Pro_Offer::MONTHLY : 6.99;
$first   = class_exists( 'OMG_Pro_Offer' ) ? OMG_Pro_Offer::FIRST : 0.99;
wp_set_object_terms( $id, 'subscription', 'product_type' );
$meta = array(
	'_subscription_price'           => wc_format_decimal( $monthly ),
	'_subscription_period'          => 'month',
	'_subscription_period_interval' => '1',
	'_subscription_length'          => '0',
	'_subscription_trial_length'    => '1',
	'_subscription_trial_period'    => 'month',
	'_subscription_sign_up_fee'     => wc_format_decimal( $first ),
	'_subscription_limit'           => 'active',
	'_regular_price'                => wc_format_decimal( $monthly ),
	'_sale_price'                   => '',
	'_price'                        => wc_format_decimal( $monthly ),
	'_virtual'                      => 'yes',
	'_downloadable'                 => 'no',
	'_sold_individually'            => 'yes',
);
foreach ( $meta as $k => $v ) {
	update_post_meta( $id, $k, $v );
}
wc_delete_product_transients( $id );
clean_post_cache( $id );
wp_cache_delete( 'woocommerce_product_' . $id . '_type', 'products' ); // the object cache keeps the old type
wp_cache_delete( $id, 'post_meta' );
WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
$p = wc_get_product( $id );
WP_CLI::success( 'PRO is now a ' . $p->get_type() . ': ' . wp_strip_all_tags( WC_Subscriptions_Product::get_price_string( $p ) ) );
