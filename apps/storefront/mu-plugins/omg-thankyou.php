<?php
/**
 * Plugin Name: OMGGamer thank-you page
 * Description: The order-received page after checkout: a confirmation hero, each item with its key (shown as soon
 *              as it is delivered, the page checks again for a few minutes), the order totals, order details and next
 *              steps. Replaces WooCommerce's checkout/thankyou.php template; WooCommerce and payment gateway hooks
 *              (woocommerce_thankyou...) still run.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('OMG_Thankyou', false)) {
    // Included again by wc_get_template('checkout/thankyou.php', ['order' => $order]): render the page.
    OMG_Thankyou::render($order ?? false);
    return;
}

// Declared conditionally so including this file as the template does not declare the class twice.
if (!class_exists('OMG_Thankyou', false)) {
final class OMG_Thankyou
{
    public static function init(): void
    {
        add_filter('wc_get_template', [__CLASS__, 'template'], 20, 2);
        // The standard order table and address boxes are replaced by the cards below.
        add_action('init', static function () {
            remove_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);
        });
        add_action('wp_head', [__CLASS__, 'css'], 99);
    }

    public static function template($located, $name)
    {
        if ('checkout/thankyou.php' === $name) {
            return __FILE__; // this file renders itself when included as the template (see the bottom)
        }
        return $located;
    }

    private static function money($amount, WC_Order $order): string
    {
        return wc_price($amount, ['currency' => $order->get_currency()]);
    }

    private static function has_keys(WC_Order_Item $item): bool
    {
        $k = $item->get_meta('_omg_keys', true);
        return is_array($k) && array_filter($k, static fn ($x) => is_array($x) && !empty($x['serial']));
    }

    public static function render($order): void
    {
        $check = '<svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>';
        echo '<div class="omgty">';
        if (!$order instanceof WC_Order) {
            echo '<section class="omgty-hero"><div class="ic">' . $check . '</div><h1>Thank you</h1><p>Your order has been received.</p></section></div>';
            return;
        }
        do_action('woocommerce_before_thankyou', $order->get_id());

        if ($order->has_status('failed')) {
            echo '<section class="omgty-hero is-bad"><div class="ic">!</div><h1>Payment was not completed</h1><p>Your bank or card provider declined the payment, so no money was taken. You can try again or use another payment method.</p>';
            echo '<div class="omgty-acts"><a class="btn pri" href="' . esc_url($order->get_checkout_payment_url()) . '">Try again</a><a class="btn" href="' . esc_url(wc_get_cart_url()) . '">Back to cart</a></div></section></div>';
            return;
        }

        $first = trim((string) $order->get_billing_first_name());
        $email = (string) $order->get_billing_email();
        $paid = $order->is_paid() || (float) $order->get_total() <= 0;
        $digital_waiting = false;
        $all_physical = true;

        echo '<section class="omgty-hero"><div class="ic">' . $check . '</div>';
        echo '<h1>' . ($first !== '' ? 'Thank you, ' . esc_html($first) . '!' : 'Thank you for your order!') . '</h1>';
        echo '<p>Order <b>#' . esc_html($order->get_order_number()) . '</b> is confirmed.' . ($email !== '' ? ' A receipt is on its way to <b>' . esc_html($email) . '</b>.' : '') . '</p>';
        echo '<div class="chips"><span class="chip ' . ($paid ? 'ok' : 'wait') . '">' . ($paid ? 'Paid' : 'Awaiting payment') . '</span><span class="chip">' . esc_html(wc_get_order_status_name($order->get_status())) . '</span></div></section>';

        echo '<div class="omgty-grid"><div class="col">';
        echo '<section class="omgty-card"><h2>Your items</h2><ul class="omgty-items">';
        foreach ($order->get_items() as $item_id => $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $product = $item->get_product();
            $virtual = $product && ($product->is_virtual() || $product->is_downloadable() || !$product->needs_shipping());
            if ($virtual) {
                $all_physical = false;
            }
            $img = $product ? wp_get_attachment_image_url($product->get_image_id() ?: ($product->get_parent_id() ? wc_get_product($product->get_parent_id())->get_image_id() : 0), 'woocommerce_thumbnail') : '';
            $meta = wc_display_item_meta($item, ['echo' => false, 'before' => '', 'after' => '', 'separator' => ' · ', 'label_before' => '', 'label_after' => ': ']);
            echo '<li><div class="im">' . ($img ? '<img src="' . esc_url($img) . '" alt="" loading="lazy">' : '') . '</div><div class="in">';
            echo '<div class="top"><span class="nm">' . esc_html(wp_strip_all_tags(html_entity_decode($item->get_name()))) . '</span><span class="pr">' . wp_kses_post(self::money($item->get_total(), $order)) . '</span></div>';
            if ($meta) {
                echo '<div class="vr">' . wp_kses_post(wp_strip_all_tags(html_entity_decode($meta), false)) . '</div>';
            }
            echo '<div class="qt">Qty ' . (int) $item->get_quantity() . '</div>';
            if ($virtual) {
                if (self::has_keys($item)) {
                    echo '<div class="keys">';
                    do_action('woocommerce_order_item_meta_end', $item_id, $item, $order, false); // the connector prints the keys
                    echo '</div>';
                } elseif ($paid) {
                    $digital_waiting = true;
                    echo '<div class="wait"><span class="sp" aria-hidden="true"></span>Delivering your key. It appears here and in your email, usually within a few minutes.</div>';
                }
            } else {
                echo '<div class="ship">Ships to your delivery address. We email you the tracking link.</div>';
            }
            echo '</div></li>';
        }
        echo '</ul></section>';

        echo '<section class="omgty-card"><h2>What happens next</h2><ol class="omgty-next">';
        if (!$all_physical) {
            echo '<li><b>Get your key</b><span>Keys show above and in your email as soon as they are delivered. Copy the key, or open My account → Orders at any time.</span></li>';
            echo '<li><b>Activate it</b><span>Redeem the key on the platform shown on the item (Steam, Xbox, PlayStation…). The product page has step-by-step activation help.</span></li>';
        }
        if ($all_physical || count($order->get_items()) > 0 && $order->needs_shipping_address()) {
            echo '<li><b>Shipping</b><span>Physical items ship to ' . esc_html(wp_strip_all_tags($order->get_formatted_shipping_address() ?: 'your address')) . '.</span></li>';
        }
        echo '<li><b>Need help?</b><span>Every order is covered by <a href="' . esc_url(home_url('/buyer-protection/')) . '">OMG Buyer Protection</a>. If a key does not work, contact us from your order page and we will fix it or refund you.</span></li>';
        echo '</ol></section></div>';

        echo '<aside class="col side"><section class="omgty-card"><h2>Order summary</h2><dl class="omgty-tot">';
        foreach ($order->get_order_item_totals() as $key => $row) {
            $cls = 'order_total' === $key ? ' class="total"' : ('discount' === $key ? ' class="g"' : '');
            echo '<div' . $cls . '><dt>' . esc_html(rtrim(wp_strip_all_tags($row['label']), ':')) . '</dt><dd>' . wp_kses_post($row['value']) . '</dd></div>';
        }
        echo '</dl></section>';
        echo '<section class="omgty-card"><h2>Order details</h2><dl class="omgty-det">';
        echo '<div><dt>Order number</dt><dd>#' . esc_html($order->get_order_number()) . '</dd></div>';
        echo '<div><dt>Date</dt><dd>' . esc_html(wc_format_datetime($order->get_date_created())) . '</dd></div>';
        if ($email !== '') {
            echo '<div><dt>Email</dt><dd>' . esc_html($email) . '</dd></div>';
        }
        if ($order->get_payment_method_title()) {
            echo '<div><dt>Payment</dt><dd>' . esc_html($order->get_payment_method_title()) . '</dd></div>';
        }
        echo '</dl></section>';
        echo '<div class="omgty-acts">';
        if (is_user_logged_in()) {
            echo '<a class="btn pri" href="' . esc_url($order->get_view_order_url()) . '">View order</a>';
        } else {
            echo '<a class="btn pri" href="' . esc_url(wc_get_page_permalink('myaccount')) . '">Create account / Log in</a>';
        }
        echo '<a class="btn" href="' . esc_url(home_url('/')) . '">Continue shopping</a></div></aside></div>';

        // Payment gateways, analytics and the like.
        do_action('woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id());
        do_action('woocommerce_thankyou', $order->get_id());
        echo '</div>';

        if ($digital_waiting) {
            // Check again for the key for up to ~5 minutes.
            echo '<script>(function(){try{var k="omgty-"+' . (int) $order->get_id() . ',n=+(sessionStorage.getItem(k)||0);if(n<15){sessionStorage.setItem(k,n+1);setTimeout(function(){location.reload();},20000);}}catch(e){}})();</script>';
        }
    }

    public static function css(): void
    {
        if (!function_exists('is_order_received_page') || !is_order_received_page()) {
            return;
        }
        ?>
<style id="omg-thankyou-css">
.omgty{max-width:1120px;margin:24px auto 64px;padding:0 16px;color:#d9d6e8;font-family:Poppins,system-ui,sans-serif}
.omgty a{color:#c4b5fd}
.omgty-hero{text-align:center;padding:36px 20px 28px}
.omgty-hero .ic{width:72px;height:72px;margin:0 auto 18px;border-radius:50%;display:grid;place-items:center;color:#0b0a10;background:linear-gradient(135deg,#34d399,#22c55e);box-shadow:0 0 0 10px rgba(52,211,153,.12);font:700 34px/1 Poppins,sans-serif}
.omgty-hero.is-bad .ic{background:linear-gradient(135deg,#fb7185,#ef4444);box-shadow:0 0 0 10px rgba(239,68,68,.12);color:#fff}
.omgty-hero h1{margin:0 0 8px;color:#fff;font:700 clamp(26px,4vw,36px)/1.2 Poppins,sans-serif}
.omgty-hero p{margin:0 auto;max-width:620px;color:#a79ec0;font-size:16px;line-height:1.6}
.omgty-hero p b{color:#f4f2fb}
.omgty-hero .chips{display:flex;gap:8px;justify-content:center;margin-top:16px;flex-wrap:wrap}
.omgty .chip{padding:5px 12px;border-radius:999px;border:1px solid #2a2540;background:#141121;font-size:13px;font-weight:600;color:#cdbbff}
.omgty .chip.ok{color:#34d399;border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.08)}
.omgty .chip.wait{color:#fbbf24;border-color:rgba(251,191,36,.35)}
.omgty-grid{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:20px;align-items:start}
.omgty .col{display:flex;flex-direction:column;gap:20px;min-width:0}
.omgty-card{background:#141121;border:1px solid #2a2540;border-radius:16px;padding:20px 22px}
.omgty-card h2{margin:0 0 14px;color:#fff;font:600 18px/1.3 Poppins,sans-serif}
.omgty-items{list-style:none;margin:0;padding:0}
.omgty-items li{display:flex;gap:14px;padding:14px 0;border-top:1px solid #231f35}
.omgty-items li:first-child{border-top:0;padding-top:0}
.omgty-items .im{flex:none;width:64px;height:86px;border-radius:10px;overflow:hidden;background:#1d1930}
.omgty-items .im img{width:100%;height:100%;object-fit:cover;display:block}
.omgty-items .in{flex:1;min-width:0}
.omgty-items .top{display:flex;justify-content:space-between;gap:12px}
.omgty-items .nm{color:#f4f2fb;font-weight:600;line-height:1.35}
.omgty-items .pr{color:#fff;font-weight:700;white-space:nowrap}
.omgty-items .vr,.omgty-items .qt{color:#8d84a8;font-size:13px;margin-top:3px}
.omgty-items .keys{margin-top:10px;padding:10px 12px;border-radius:10px;background:#0f0d18;border:1px dashed #3b3460}
.omgty-items .keys code{font-size:15px;color:#a7f3d0;background:none}
.omgty-items .wait,.omgty-items .ship{margin-top:10px;display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;background:#1a1628;color:#cdbbff;font-size:14px}
.omgty-items .sp{flex:none;width:14px;height:14px;border-radius:50%;border:2px solid #8b5cf6;border-right-color:transparent;animation:omgty-spin .8s linear infinite}
@keyframes omgty-spin{to{transform:rotate(360deg)}}
.omgty-next{margin:0;padding:0;list-style:none;counter-reset:s}
.omgty-next li{counter-increment:s;position:relative;padding:0 0 14px 40px}
.omgty-next li::before{content:counter(s);position:absolute;left:0;top:0;width:26px;height:26px;border-radius:50%;display:grid;place-items:center;background:#2a2540;color:#cdbbff;font-size:13px;font-weight:700}
.omgty-next b{display:block;color:#f4f2fb;margin-bottom:2px}
.omgty-next span{color:#a79ec0;font-size:14px;line-height:1.55}
.omgty dl{margin:0}
.omgty dl>div{display:flex;justify-content:space-between;gap:12px;padding:7px 0;font-size:15px}
.omgty dt{color:#a79ec0}
.omgty dd{margin:0;color:#f4f2fb;text-align:right}
.omgty-tot .g dd{color:#34d399}
.omgty-tot .total{margin-top:6px;padding-top:12px;border-top:1px solid #2a2540}
.omgty-tot .total dt{color:#fff;font-weight:600}
.omgty-tot .total dd{color:#fff;font-size:20px;font-weight:700}
.omgty-acts{display:flex;flex-direction:column;gap:10px;margin-top:4px}
.omgty-hero .omgty-acts{flex-direction:row;justify-content:center;margin-top:20px}
.omgty .btn{display:flex;align-items:center;justify-content:center;min-height:48px;padding:0 20px;border-radius:12px;border:1px solid #3b3460;color:#f4f2fb!important;text-decoration:none;font-weight:600}
.omgty .btn.pri{border:0;background:linear-gradient(135deg,#8b5cf6,#d946ef);color:#fff!important}
@media (max-width:900px){.omgty-grid{grid-template-columns:1fr}}
@media (prefers-reduced-motion:reduce){.omgty-items .sp{animation:none}}
/* WooCommerce's own notices and order overview on this page are replaced by the cards above. */
.woocommerce-order-received .woocommerce-thankyou-order-received,.woocommerce-order-received .woocommerce-order-overview{display:none}
</style>
        <?php
    }
}

OMG_Thankyou::init();
}
