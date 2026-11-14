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
    public const CUR_COOKIE = 'omg_currency';
    private static $chosen_currency = false;

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
            ] + self::europe($m),
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

    /** Every European country we sell to (not Russia or Belarus), each in its own currency. */
    public const EUROPE = [
        // Euro area and countries that use the euro.
        'AT' => 'EUR', 'BE' => 'EUR', 'BG' => 'EUR', 'HR' => 'EUR', 'CY' => 'EUR', 'EE' => 'EUR', 'FI' => 'EUR',
        'FR' => 'EUR', 'DE' => 'EUR', 'GR' => 'EUR', 'IE' => 'EUR', 'IT' => 'EUR', 'LV' => 'EUR', 'LT' => 'EUR',
        'LU' => 'EUR', 'MT' => 'EUR', 'NL' => 'EUR', 'PT' => 'EUR', 'SK' => 'EUR', 'SI' => 'EUR', 'ES' => 'EUR',
        'AD' => 'EUR', 'MC' => 'EUR', 'SM' => 'EUR', 'VA' => 'EUR', 'ME' => 'EUR',
        // Own currencies.
        'GB' => 'GBP', 'CH' => 'CHF', 'LI' => 'CHF', 'NO' => 'NOK', 'IS' => 'ISK', 'SE' => 'SEK', 'DK' => 'DKK',
        'PL' => 'PLN', 'CZ' => 'CZK', 'HU' => 'HUF', 'RO' => 'RON', 'TR' => 'TRY', 'AL' => 'ALL', 'BA' => 'BAM',
        'RS' => 'RSD', 'MK' => 'MKD', 'MD' => 'MDL', 'UA' => 'UAH',
    ];

    private static function europe(callable $m): array
    {
        $out = [];
        foreach (self::EUROPE as $cc => $cur) {
            $out[$cc] = $m($cur);
        }
        return $out;
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
            if (empty($_GET['currency'])) {
                self::remember_currency('');
            }
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
        $explicit = (bool) $code;
        $code = $code ?: self::current();
        $m = self::settings()['markets'][$code] ?? ['currency' => self::base_currency(), 'markup' => 0, 'rounding' => 'none'];
        // The visitor may pick a different currency from their country's own (Region, language & currency menu).
        if ((!$explicit || ($code === self::current() && !self::$forced)) && !self::$forced && self::applies()) {
            $cur = self::chosen_currency();
            if ($cur && $cur !== $m['currency']) {
                $m['currency'] = $cur;
            }
        }
        return $m;
    }

    /** Currencies a visitor can choose: those of the enabled markets. */
    public static function currencies(): array
    {
        $out = [];
        foreach (self::enabled() as $m) {
            $out[$m['currency']] = true;
        }
        $out = array_keys($out);
        sort($out);
        return $out;
    }

    /** The visitor's own currency choice, or '' to use their country's currency. */
    public static function chosen_currency(): string
    {
        if (self::$chosen_currency !== false) {
            return self::$chosen_currency;
        }
        $ok = self::currencies();
        $get = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($_GET['currency'] ?? '')));
        if ($get !== '' && in_array($get, $ok, true)) {
            self::remember_currency($get);
            return self::$chosen_currency = $get;
        }
        $c = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($_COOKIE[self::CUR_COOKIE] ?? '')));
        return self::$chosen_currency = in_array($c, $ok, true) ? $c : '';
    }

    private static function remember_currency(string $cur): void
    {
        self::$chosen_currency = $cur;
        if ($cur === '') {
            unset($_COOKIE[self::CUR_COOKIE]);
        } else {
            $_COOKIE[self::CUR_COOKIE] = $cur;
        }
        if (headers_sent()) {
            return;
        }
        setcookie(self::CUR_COOKIE, $cur, [
            'expires' => $cur === '' ? time() - 3600 : time() + 180 * DAY_IN_SECONDS,
            'path' => COOKIEPATH ?: '/',
            'domain' => COOKIE_DOMAIN ?: '',
            'secure' => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
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
