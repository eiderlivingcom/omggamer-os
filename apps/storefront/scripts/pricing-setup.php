<?php
/**
 * Pricing setup (safe to run again): saves the default OMG pricing settings and makes sure a 10% coupon that
 * works together with PRO exists.   wp eval-file pricing-setup.php [CODE]
 */
if (!defined('WP_CLI')) {
    return;
}
if (!get_option('omg_pricing')) {
    update_option('omg_pricing', ['sale' => 10, 'pro' => 10, 'markup' => 57.8, 'min_profit' => 15]);
    WP_CLI::log('Saved default pricing settings.');
}
WP_CLI::log('Pricing: ' . wp_json_encode(get_option('omg_pricing')));
foreach (get_posts(['post_type' => 'shop_coupon', 'numberposts' => 20, 'post_status' => 'any']) as $c) {
    $co = new WC_Coupon($c->ID);
    WP_CLI::log(sprintf('Existing coupon %s: %s %s, individual use: %s, status: %s', $co->get_code(), $co->get_discount_type(), $co->get_amount(), $co->get_individual_use() ? 'yes' : 'no', $c->post_status));
}
$code = $args[0] ?? '';
if ($code) {
    $id = wc_get_coupon_id_by_code($code);
    $co = new WC_Coupon($id ?: 0);
    if (!$id) {
        $co->set_code($code);
    }
    $co->set_discount_type('percent');
    $co->set_amount(10);
    $co->set_individual_use(false);
    $co->set_description('10% off. Works together with the storewide sale and PRO.');
    $co->save();
    WP_CLI::success(($id ? 'Updated' : 'Created') . " coupon {$code}: 10% off, combinable.");
}
