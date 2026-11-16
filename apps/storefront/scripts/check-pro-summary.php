<?php
/** Read-only check: for test carts, the PRO saving shown in checkout equals the real cart difference. */
if (!defined('ABSPATH')) { exit; }
wc_load_cart();
$pro = OMG_Pro_Offer::PRODUCT;
$carts = ['GPU (floor-held)' => [[2027, 0]], 'Etsy accessory' => [[2035, 0]], 'Variable game (AoE Europe)' => [[3415, 3419]], 'Mixed' => [[2027, 0], [2033, 0], [3415, 3422]]];
foreach ($carts as $label => $lines) {
    foreach ([false, true] as $withPro) {
        WC()->cart->empty_cart();
        foreach ($lines as [$pid, $vid]) { WC()->cart->add_to_cart($pid, 1, $vid); }
        if ($withPro) { WC()->cart->add_to_cart($pro, 1); }
        WC()->cart->calculate_totals();
        $real = 0.0; $rows = [];
        foreach (WC()->cart->get_cart() as $it) {
            if ((int) $it['product_id'] === $pro) { continue; }
            $m = OMG_Pro_Offer::member_price($it['data']);
            $unit = (float) $it['line_subtotal'] / max(1, (int) $it['quantity']);
            $rows[] = $it['data']->get_name() . ' unit=' . number_format($unit, 2) . ' before=' . number_format($m['before'], 2) . ' member=' . number_format($m['pro'], 2) . ' meta=' . get_post_meta((int) $it['product_id'], 'omg_pro_price', true);
            if ($withPro) { $real += $m['before'] - $unit; }
        }
        $s = OMG_Pro_Offer::summary_data();
        printf("%s %s: total=%s shown_saving=%.2f real_saving=%.2f subtotal_before_pro=%.2f %s\n", $label, $withPro ? '+PRO' : '    ', WC()->cart->get_total('edit'), $s['pro_discount'] / 100, $real, $s['subtotal_before_pro'] / 100, abs($s['pro_discount'] / 100 - $real) < 0.011 || !$withPro ? 'OK' : 'MISMATCH');
        foreach ($rows as $r) { echo "    $r\n"; }
    }
}
WC()->cart->empty_cart();
