<?php
/**
 * Plugin Name: OMGGamer payment form look
 * Description: Styles the Stripe payment form at checkout in the store's dark colours (Stripe Appearance API), and
 *              tidies the box around it: no extra "Payment methods" radio row when Stripe is the only option, one
 *              clean card instead of the double purple border.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Payment_Look
{
    public static function init(): void
    {
        add_filter('wc_stripe_upe_params', [__CLASS__, 'params'], 20);
        add_action('wp_head', [__CLASS__, 'css'], 99);
        // Stripe caches the look it measured from the page; clear it once so ours is used.
        add_action('init', static function () {
            if (get_option('omg_payment_look_v') !== '1') {
                delete_transient('wc_stripe_appearance');
                delete_transient('wc_stripe_blocks_appearance');
                update_option('omg_payment_look_v', '1', false);
            }
        });
    }

    public static function appearance(): array
    {
        return [
            'theme' => 'night',
            'variables' => [
                'colorPrimary' => '#8b5cf6',
                'colorBackground' => '#0f0d18',
                'colorText' => '#f4f2fb',
                'colorTextSecondary' => '#a79ec0',
                'colorTextPlaceholder' => '#6f6890',
                'colorDanger' => '#fb7185',
                'colorSuccess' => '#34d399',
                'colorIcon' => '#cdbbff',
                'fontFamily' => 'Poppins, system-ui, -apple-system, Segoe UI, sans-serif',
                'fontSizeBase' => '15px',
                'borderRadius' => '12px',
                'spacingUnit' => '4px',
                'gridRowSpacing' => '14px',
            ],
            'rules' => [
                '.Input' => ['backgroundColor' => '#0f0d18', 'border' => '1px solid #2a2540', 'boxShadow' => 'none', 'padding' => '13px 14px'],
                '.Input:focus' => ['border' => '1px solid #8b5cf6', 'boxShadow' => '0 0 0 3px rgba(139,92,246,.25)'],
                '.Input--invalid' => ['border' => '1px solid #fb7185'],
                '.Label' => ['color' => '#a79ec0', 'fontWeight' => '500'],
                '.Tab' => ['backgroundColor' => '#141121', 'border' => '1px solid #2a2540', 'boxShadow' => 'none'],
                '.Tab:hover' => ['border' => '1px solid #3b3460', 'color' => '#f4f2fb'],
                '.Tab--selected' => ['backgroundColor' => '#1d1930', 'border' => '1px solid #8b5cf6', 'boxShadow' => '0 0 0 1px #8b5cf6'],
                '.AccordionItem' => ['backgroundColor' => '#141121', 'border' => '1px solid #2a2540', 'boxShadow' => 'none', 'padding' => '16px 18px'],
                '.AccordionItem--selected' => ['border' => '1px solid #8b5cf6'],
                '.PickerItem' => ['backgroundColor' => '#141121', 'border' => '1px solid #2a2540', 'boxShadow' => 'none'],
                '.PickerItem--selected' => ['border' => '1px solid #8b5cf6', 'boxShadow' => '0 0 0 1px #8b5cf6'],
                '.Block' => ['backgroundColor' => '#141121', 'border' => '1px solid #2a2540', 'boxShadow' => 'none'],
                '.Text--redirect' => ['color' => '#a79ec0'],
            ],
        ];
    }

    public static function params($params)
    {
        if (is_array($params)) {
            $params['appearance'] = self::appearance();
            $params['blocksAppearance'] = self::appearance();
        }
        return $params;
    }

    public static function css(): void
    {
        if (!function_exists('is_checkout') || !is_checkout() || is_wc_endpoint_url()) {
            return;
        }
        ?>
<style id="omg-payment-look-css">
/* One clean card around the Stripe form; no radio row when Stripe is the only payment option. */
html body .wc-block-checkout__payment-method .wc-block-components-radio-control--highlight-checked::after,
html body .wc-block-checkout__payment-method .wc-block-components-radio-control-accordion-option::after{display:none!important}
html body .wc-block-checkout__payment-method .wc-block-components-radio-control-accordion-option{border:1px solid #2a2540!important;border-radius:16px!important;background:#141121!important;box-shadow:none!important;overflow:hidden}
html body .wc-block-checkout__payment-method .wc-block-components-radio-control-accordion-option:only-child > .wc-block-components-radio-control__option{display:none!important}
html body .wc-block-checkout__payment-method .wc-block-components-radio-control-accordion-content{padding:18px!important;border:0!important;background:transparent!important}
html body .wc-block-checkout__payment-method .wc-block-components-radio-control-accordion-option:only-child .wc-block-components-radio-control-accordion-content{padding-top:18px!important}
</style>
        <?php
    }
}

OMG_Payment_Look::init();
