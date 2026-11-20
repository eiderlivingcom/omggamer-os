<?php
/**
 * Plugin Name: OMGGamer PRO prices in the visitor's currency
 * Description: The PRO price text written into Bricks (the buy box line "PRO is $0.99 for the first month, then
 *              $6.99/mo", the /pro/ page and its savings calculator) is typed in USD. When a market with another
 *              currency applies, those amounts are rewritten into that currency with the same conversion the
 *              cart uses (OMG_Markets::convert), so the page, cart and checkout all show the same PRO price.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Pro_Prices
{
    private static string $cur = '';

    public static function init(): void
    {
        add_action('template_redirect', [__CLASS__, 'start'], 6);
    }

    public static function start(): void
    {
        if (is_admin() || wp_doing_ajax() || !class_exists('OMG_Markets') || !OMG_Markets::applies() || !class_exists('OMG_Pro_Offer')) {
            return;
        }
        $cur = OMG_Markets::currency();
        if ($cur === '' || $cur === OMG_Markets::base_currency()) {
            return;
        }
        $page = is_page('pro');
        if (!$page && !(function_exists('is_product') && is_product())) {
            return;
        }
        self::$cur = $cur;
        ob_start($page ? [__CLASS__, 'pro_page'] : [__CLASS__, 'product_page']);
    }

    /** A base-currency amount as plain text in the market currency ("₹652.13"). */
    private static function money(float $usd): string
    {
        $v = OMG_Markets::convert($usd);
        if ($v === null) {
            return '$' . number_format($usd, 2);
        }
        return self::fmt($v);
    }

    private static function fmt(float $v): string
    {
        $html = wc_price($v, ['currency' => self::$cur, 'decimals' => OMG_Markets::decimals(self::$cur)]);
        return trim(html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8'));
    }

    /** Replace typed USD amounts ("$6.99", "$0.99") in a piece of HTML. */
    private static function amounts(string $html): string
    {
        return (string) preg_replace_callback('/\$(\d+(?:\.\d{1,2})?)(?!\d|\.\d)/', static fn ($m) => self::money((float) $m[1]), $html);
    }

    public static function product_page(string $html): string
    {
        return (string) preg_replace_callback(
            '~(<p id="omg-dg-pro"[^>]*>)(.*?)(</p>)~s',
            static fn ($m) => $m[1] . self::amounts($m[2]) . $m[3],
            $html,
            1
        );
    }

    public static function pro_page(string $html): string
    {
        $start = strpos($html, '<main');
        $end = strpos($html, '</main>');
        if ($start === false || $end === false) {
            return $html;
        }
        $main = substr($html, $start, $end - $start);
        // Calculator: the script and the slider work in the market currency.
        $main = self::calculator($main);
        // Every other typed amount on the page (hero, steps, compare table, FAQ, final call).
        $parts = preg_split('~(<script\b.*?</script>)~s', $main, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $p) {
            if ($i % 2 === 0) {
                $parts[$i] = self::amounts($p);
            }
        }
        return substr($html, 0, $start) . implode('', $parts) . substr($html, $end);
    }

    /** A round slider step in the market currency for about $5. */
    private static function step(float $fx): float
    {
        $raw = 5 * $fx;
        $pow = 10 ** floor(log10(max($raw, 0.01)));
        foreach ([1, 2, 5, 10] as $k) {
            if ($raw <= $k * $pow * 1.5) {
                return $k * $pow;
            }
        }
        return 10 * $pow;
    }

    private static function calculator(string $main): string
    {
        $hundred = OMG_Markets::convert(100);
        if (!$hundred) {
            return $main;
        }
        $fx = $hundred / 100;
        $step = self::step($fx);
        $snap = static fn ($usd) => max($step, round($usd * $fx / $step) * $step);
        $min = $snap(10);
        $max = $snap(500);
        $val = $snap(100);
        $main = (string) preg_replace(
            '~(<input id="omg-pro-range"[^>]*?)min="[^"]*"\s+max="[^"]*"\s+step="[^"]*"\s+value="[^"]*"~',
            '$1min="' . $min . '" max="' . $max . '" step="' . $step . '" value="' . $val . '"',
            $main,
            1
        );
        $main = (string) preg_replace(
            '~(<div class="omg-pro-calc-scale"><span>)[^<]*(</span><span>)[^<]*(</span>)~',
            '${1}' . self::fmt($min) . '${2}' . self::fmt($max) . '${3}',
            $main,
            1
        );
        $dp = OMG_Markets::decimals(self::$cur);
        $sample = self::fmt(1234.5);
        $num = number_format(1234.5, $dp, wc_get_price_decimal_separator(), wc_get_price_thousand_separator());
        $tpl = str_contains($sample, $num) ? str_replace($num, '%s', $sample) : get_woocommerce_currency_symbol(self::$cur) . '%s';
        $fee = OMG_Markets::convert(OMG_Pro_Offer::MONTHLY) ?? OMG_Pro_Offer::MONTHLY;
        $js = 'function money(v) { var s = v.toFixed(' . $dp . ').split("."), i = s[0].replace(/(\d)(?=(\d{3})+$)/g, "$1" + ' . wp_json_encode(wc_get_price_thousand_separator()) . ');'
            . ' return ' . wp_json_encode($tpl) . '.replace("%s", i + (s[1] ? ' . wp_json_encode(wc_get_price_decimal_separator()) . ' + s[1] : "")); }';
        $main = str_replace("function money(v) { return '$' + v.toFixed(2); }", $js, $main);
        return (string) preg_replace('~var FEE = [0-9.]+,~', 'var FEE = ' . $fee . ',', $main, 1);
    }
}

OMG_Pro_Prices::init();
