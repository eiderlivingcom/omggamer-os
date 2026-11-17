<?php
/**
 * Plugin Name: OMGGamer service fee
 * Description: Adds a service fee (6.5% by default) to every order at checkout once the buyer has chosen a
 *              payment method. It is the same for every payment method, is shown as its own line in the order
 *              summary before "Place order", and is worked out on the amount after discounts (a 100% coupon
 *              means no fee). Set the rate under WooCommerce → Settings → General → "Service fee %" (0 turns it
 *              off). The cart page and cart drawer never show it.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Service_Fee
{
    private const OPTION = 'omg_service_fee_pct';
    private const SESSION = 'omg_service_fee_on';
    private const NS = 'omg-service-fee';

    public static function init(): void
    {
        add_action('woocommerce_cart_calculate_fees', [__CLASS__, 'add_fee'], 99);
        add_action('woocommerce_blocks_loaded', [__CLASS__, 'register_update']);
        add_filter('rest_pre_dispatch', [__CLASS__, 'on_checkout_submit'], 5, 3);
        add_action('template_redirect', [__CLASS__, 'off_outside_checkout']);
        add_action('wp_footer', [__CLASS__, 'checkout_js'], 99);
        add_filter('woocommerce_general_settings', [__CLASS__, 'setting']);
    }

    public static function rate(): float
    {
        $v = get_option(self::OPTION, '6.5');
        return is_numeric($v) ? max(0.0, min(50.0, (float) $v)) : 6.5;
    }

    private static function on(): bool
    {
        return function_exists('WC') && WC()->session && WC()->session->get(self::SESSION);
    }

    private static function set(bool $on): void
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set(self::SESSION, $on ? 1 : null);
        }
    }

    public static function add_fee(WC_Cart $cart): void
    {
        $rate = self::rate();
        if ($rate <= 0 || !self::on()) {
            return;
        }
        // The amount the buyer pays for the order: items after discounts, plus shipping.
        $base = (float) $cart->get_subtotal() - (float) $cart->get_discount_total() + (float) $cart->get_shipping_total();
        $fee = round(max(0.0, $base) * $rate / 100, wc_get_price_decimals());
        if ($fee > 0) {
            $cart->add_fee('Service fee', $fee, false);
        }
    }

    /** The checkout tells the cart once a payment method is chosen (block checkout: extensionCartUpdate). */
    public static function register_update(): void
    {
        if (!function_exists('woocommerce_store_api_register_update_callback')) {
            return;
        }
        woocommerce_store_api_register_update_callback([
            'namespace' => self::NS,
            'callback' => static function ($data) {
                self::set(!empty($data['on']));
            },
        ]);
    }

    /** Placing the order always includes the fee, even if the page script did not run. */
    public static function on_checkout_submit($result, $server, $request)
    {
        if ($request instanceof WP_REST_Request && 'POST' === $request->get_method()
            && preg_match('#^/wc/store(/v\d+)?/checkout$#', $request->get_route())) {
            if (function_exists('WC') && WC()->session === null && function_exists('wc_load_cart')) {
                wc_load_cart();
            }
            self::set(true);
        }
        return $result;
    }

    /** Leaving checkout (cart page, product pages) hides the fee again until a payment method is chosen. */
    public static function off_outside_checkout(): void
    {
        if (function_exists('is_checkout') && !is_checkout() && !is_wc_endpoint_url() && self::on()) {
            self::set(false);
        }
    }

    public static function checkout_js(): void
    {
        if (!function_exists('is_checkout') || !is_checkout() || is_wc_endpoint_url() || self::rate() <= 0) {
            return;
        }
        ?>
<script id="omg-service-fee-js">
(function(){
  var NS = <?php echo wp_json_encode(self::NS); ?>, sent = null;
  function tell(on){
    if (sent === on) return;
    var b = window.wc && wc.blocksCheckout;
    if (!b || !b.extensionCartUpdate) return;
    sent = on;
    b.extensionCartUpdate({ namespace: NS, data: { on: on ? 1 : 0 } });
  }
  function watch(){
    var d = window.wp && wp.data;
    if (!d || !d.select('wc/store/payment')) { setTimeout(watch, 400); return; }
    tell(false);
    d.subscribe(function(){
      var m = d.select('wc/store/payment').getActivePaymentMethod && d.select('wc/store/payment').getActivePaymentMethod();
      if (m) tell(true);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', watch); else watch();
})();
</script>
        <?php
    }

    public static function setting(array $settings): array
    {
        $settings[] = ['title' => 'OMGGamer service fee', 'type' => 'title', 'id' => 'omg_service_fee'];
        $settings[] = [
            'title' => 'Service fee %',
            'desc' => 'Added to every order at checkout once a payment method is chosen, on the amount after discounts. 0 turns it off.',
            'id' => self::OPTION,
            'type' => 'number',
            'default' => '6.5',
            'custom_attributes' => ['step' => '0.1', 'min' => '0', 'max' => '50'],
        ];
        $settings[] = ['type' => 'sectionend', 'id' => 'omg_service_fee'];
        return $settings;
    }
}

OMG_Service_Fee::init();
