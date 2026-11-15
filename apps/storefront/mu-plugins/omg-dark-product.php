<?php
/**
 * Plugin Name: OMGGamer dark hardware & accessory page
 * Description: Puts the AI Studio hardware/accessory product page (.omgp) on the site's dark colour scheme.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_head', static function (): void {
    if (is_admin() || !function_exists('is_product') || !is_product()) {
        return;
    }
    ?>
<style id="omg-dark-product">
html body .omgp{--ink:#f4f2fb;--mut:#a79ec0;--line:#2a2540;--soft:#1f1c2c;--pur:#b79cff;--ok:#4ade80;--okbg:rgba(74,222,128,.12);color:var(--ink)}
html body .omgp-box,html body .omgp-gallery,html body .omgp-high,html body .omgp-buy,html body .omgp-seller,html body .omgp-card{background:#171424;border-color:#2a2540;box-shadow:none}
html body .omgp-stage{background:#fff;border-radius:12px}
html body .omgp-thumbs button{background:#fff;border-color:#2a2540}
html body .omgp-thumbs button.is-on{border-color:#7C3AED}
html body .omgp-nav{background:#1f1c2c;color:#f4f2fb;box-shadow:0 2px 10px rgba(0,0,0,.4)}
html body .omgp-pill{background:#1f1c2c;color:#f4f2fb;box-shadow:none}
html body .omgp-price,html body .omgp-price *,html body .omgp-title,html body .omgp-buy h1{color:#fff}
html body .omgp-price del,html body .omgp-price del *{color:#8d84a8}
html body .omgp-crumbs span{color:#d9d6e8}
html body .omgp-crumbs a{color:#a79ec0}
html body .omgp-brand{color:#b79cff}
html body .omgp-addall,html body .omgp #reviews .submit{background:linear-gradient(120deg,#7C3AED,#C026D3 52%,#E0115F);color:#fff}
html body .omgp-seller .lg{background:#2a2540;color:#fff}
html body .omgp-cartbtn,html body .omgp-atc .single_add_to_cart_button.omgp-cartbtn{background:#2a2540!important;border-color:#2a2540!important}
html body .omgp-atc .quantity,html body .omgp-atc .quantity input,html body .omgp-atc table.variations select{background-color:#1f1c2c;color:#f4f2fb;border-color:#2a2540}
html body .omgp-setup .it.is-off .ck{background:#1f1c2c;border-color:#3a3456}
html body .omgp-trust>*{background:#1f1c2c}
html body .omgp-stock,html body .omgp-stock *{background-color:transparent;color:#4ade80}
html body .omgp-stock{background:rgba(74,222,128,.12)}
html body .omgp-short,html body .omgp-short *{color:#d9d6e8}
html body .omgp-tabbar{border-color:#2a2540}
html body .omgp-spec,html body .omgp-spec *{border-color:#2a2540}
</style>
    <?php
}, 70);
