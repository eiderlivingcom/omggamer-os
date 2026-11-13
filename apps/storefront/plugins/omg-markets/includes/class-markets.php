<?php
/**
 * Markets: which countries we sell to, in which currency, and which market the current visitor is in.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Markets
{
    public const OPTION = 'omg_markets_settings';
    public const COOKIE = 'omg_market';

    /** Currencies shown without decimals. */
    private const ZERO_DECIMAL = ['JPY', 'KRW', 'ISK', 'HUF', 'IDR'];

    private static ?array $settings = null;
    private static ?string $current = null;
    private static ?string $forced = null;

    public static function defaults(): array
    {
        $m = static fn (string $cur): array => ['currency' => $cur, 'enabled' => 1, 'markup' => 0, 'rounding' => 'none'];

        return [
            'default' => 'US',
            'geo' => 1,
            'markets' => [
                'US' => $m('USD'), 'GB' => $m('GBP'), 'AU' => $m('AUD'), 'CA' => $m('CAD'), 'NZ' => $m('NZD'),
                'IE' => $m('EUR'), 'DE' => $m('EUR'), 'FR' => $m('EUR'), 'ES' => $m('EUR'), 'IT' => $m('EUR'),
                'NL' => $m('EUR'), 'IN' => $m('INR'),
            ],
            // Product region (pa_region slug) => countries it can be activated in. "*" = everywhere, "" = no feed.
            'regions' => [
                'global' => '*',
                'region-free' => '*',
                'worldwide' => '*',
                'australia' => 'AU',
                'india' => 'IN',
                'rest-of-world-row' => '',
            ],
            // Product category slug => Google product category ID (https://support.google.com/merchants/answer/6324436).
            'gpc' => [
                'game-keys' => '1279',
                'game-accounts' => '1279',
                'games' => '1279',
                'digital-key' => '1279',
                'gift-cards' => '53',
                'hardware' => '1294',
            ],
        ];
    }

    public static function settings(): array
    {
        if (self::$settings === null) {
            $saved = get_option(self::OPTION, []);
            $saved = is_array($saved) ? $saved : [];
            $def = self::defaults();
            self::$settings = array_merge($def, $saved);
            foreach (['markets', 'regions', 'gpc'] as $k) {
                if (!isset($saved[$k]) || !is_array($saved[$k])) {
                    self::$settings[$k] = $def[$k];
                }
            }
        }
        return self::$settings;
    }

    public static function save(array $settings): void
    {
        update_option(self::OPTION, $settings, true);
        self::$settings = null;
        self::$current = null;
    }

    public static function base_currency(): string
    {
        remove_filter('woocommerce_currency', ['OMG_Markets_Prices', 'currency'], 99);
        $c = get_woocommerce_currency();
        add_filter('woocommerce_currency', ['OMG_Markets_Prices', 'currency'], 99);
        return $c;
    }

    /** Enabled markets whose currency we can price (the base currency, or one with a current rate). */
    public static function enabled(): array
    {
        $out = [];
        $base = self::base_currency();
        foreach (self::settings()['markets'] as $cc => $m) {
            if (!empty($m['enabled']) && ($m['currency'] === $base || OMG_Markets_Rates::rate($base, $m['currency']) !== null)) {
                $out[$cc] = $m;
            }
        }
        return $out;
    }

    public static function normalise(?string $code): ?string
    {
        if (!$code) {
            return null;
        }
        $code = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $code), 0, 2));
        if ($code === 'UK') {
            $code = 'GB';
        }
        return isset(self::enabled()[$code]) ? $code : null;
    }

    public static function force(?string $code): void
    {
        self::$forced = $code ? self::normalise($code) : null;
    }

    /** Should prices on this request follow the visitor's market? */
    public static function applies(): bool
    {
        return self::why_not() === '';
    }

    /** Empty when the market applies, otherwise the reason it does not (also sent as X-OMG-Market). */
    public static function why_not(): string
    {
        if (self::$forced) {
            return '';
        }
        if (is_admin() && !self::is_front_ajax()) {
            return 'admin';
        }
        if ((defined('WP_CLI') && WP_CLI) || wp_doing_cron()) {
            return 'cli';
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($uri, '/wp-json/') !== false || strpos($uri, 'rest_route=') !== false) {
            // Back-office and integration APIs (OMGGamer OS connector, WooCommerce admin API) always use the base currency.
            if (preg_match('~(wp-json/|rest_route=/?)(omggamer|wc/v\d|wc-analytics|wc-admin|wp/v2|dokan|mcp)~', $uri)) {
                return 'api-route';
            }
        }
        return '';
    }

    private static function is_front_ajax(): bool
    {
        if (!wp_doing_ajax()) {
            return false;
        }
        $ref = wp_get_raw_referer();
        return $ref && strpos($ref, '/wp-admin/') === false;
    }

    public static function current(): string
    {
        if (self::$forced) {
            return self::$forced;
        }
        if (self::$current !== null) {
            return self::$current;
        }
        $s = self::settings();
        $default = self::normalise($s['default']) ?: (array_key_first(self::enabled()) ?: 'US');
        if (!self::applies()) {
            return self::$current = $default;
        }
        $pick = self::normalise($_GET['market'] ?? null);
        if ($pick) {
            self::remember($pick);
            return self::$current = $pick;
        }
        $pick = self::normalise($_COOKIE[self::COOKIE] ?? null);
        if ($pick) {
            return self::$current = $pick;
        }
        if (!empty($s['geo'])) {
            $pick = self::normalise($_SERVER['HTTP_CF_IPCOUNTRY'] ?? null);
            if ($pick) {
                self::remember($pick, 0);
                return self::$current = $pick;
            }
        }
        return self::$current = $default;
    }

    private static function remember(string $code, ?int $days = 180): void
    {
        $_COOKIE[self::COOKIE] = $code;
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $code, [
            'expires' => $days ? time() + $days * DAY_IN_SECONDS : 0,
            'path' => COOKIEPATH ?: '/',
            'domain' => COOKIE_DOMAIN ?: '',
            'secure' => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    public static function market(?string $code = null): array
    {
        $code = $code ?: self::current();
        return self::settings()['markets'][$code] ?? ['currency' => self::base_currency(), 'markup' => 0, 'rounding' => 'none'];
    }

    public static function currency(?string $code = null): string
    {
        return self::market($code)['currency'];
    }

    public static function decimals(string $currency): int
    {
        return in_array($currency, self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    public static function country_name(string $cc): string
    {
        $countries = function_exists('WC') && WC()->countries ? WC()->countries->get_countries() : [];
        return html_entity_decode($countries[$cc] ?? $cc);
    }

    public static function flag(string $cc): string
    {
        $cc = strtoupper($cc);
        return sprintf('&#%d;&#%d;', 0x1F1E6 + ord($cc[0]) - 65, 0x1F1E6 + ord($cc[1]) - 65);
    }

    /** Convert an amount in the base currency into a market's currency, or null when it cannot be priced. */
    public static function convert($amount, ?string $code = null): ?float
    {
        if ($amount === '' || $amount === null || !is_numeric($amount)) {
            return null;
        }
        $m = self::market($code);
        $base = self::base_currency();
        $rate = $m['currency'] === $base ? 1.0 : OMG_Markets_Rates::rate($base, $m['currency']);
        if ($rate === null) {
            return null;
        }
        $value = (float) $amount * $rate * (1 + ((float) ($m['markup'] ?? 0)) / 100);
        $dp = self::decimals($m['currency']);
        if (($m['rounding'] ?? 'none') === 'charm' && $value >= 1) {
            return $dp ? ceil($value) - 0.01 : ceil($value);
        }
        return round($value, $dp);
    }
}
