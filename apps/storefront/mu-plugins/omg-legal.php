<?php
/**
 * Plugin Name: OMGGamer legal pages and footer
 * Description: Operator details (WooCommerce → OMG legal details) used by the legal pages and the footer through
 *              shortcodes, legal page styling, the footer payment row and operator block, and redirects from old
 *              legal/seller URLs.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Legal
{
    public const OPTION = 'omg_legal';
    public const UPDATED = '15 November 2026';
    /** Slugs of the pages built from apps/storefront/legal/*.html. */
    public const PAGES = ['terms-and-conditions', 'privacy-policy', 'cookie-policy', 'refund-policy', 'pro-terms', 'buyer-protection', 'dsa-gpsr', 'legal-notice'];

    private const DEFAULTS = [
        'name' => 'XGamer Labs Private Limited',
        'brand' => 'OMGGamer',
        'address' => "2nd Floor, 281/174 Rajpur Road\nDehradun, Uttarakhand\nIndia 248009",
        'email' => 'hello@omggamer.com',
        'phone' => '',
        'cin' => '',
        'gstin' => '',
        'grievance_name' => '',
    ];

    private const LABELS = [
        'name' => 'Company name',
        'brand' => 'Store name',
        'address' => 'Registered address',
        'email' => 'Contact and legal email',
        'phone' => 'Phone (optional)',
        'cin' => 'Corporate Identity Number (CIN, optional)',
        'gstin' => 'GSTIN (optional)',
        'grievance_name' => 'Grievance Officer name (optional)',
    ];

    private const REDIRECTS = [
        'terms' => 'terms-and-conditions',
        'terms-of-service' => 'terms-and-conditions',
        'privacy' => 'privacy-policy',
        'privacy-policy-2' => 'privacy-policy',
        'refund_returns' => 'refund-policy',
        'refunds' => 'refund-policy',
        'cookies' => 'cookie-policy',
        'imprint' => 'legal-notice',
        'dsa' => 'dsa-gpsr',
        // Selling on OMGGamer is switched off for now.
        'sell' => '',
        'seller-hub' => '',
        'vendor-onboarding' => '',
    ];

    public static function init(): void
    {
        add_shortcode('omg_legal', [__CLASS__, 'sc_field']);
        add_shortcode('omg_legal_company', [__CLASS__, 'sc_company']);
        add_shortcode('omg_legal_updated', static fn () => esc_html(self::UPDATED));
        add_shortcode('omg_footer_pay', [__CLASS__, 'sc_footer_pay']);
        add_shortcode('omg_footer_company', [__CLASS__, 'sc_footer_company']);
        add_action('template_redirect', [__CLASS__, 'redirects'], 1);
        add_filter('body_class', [__CLASS__, 'body_class']);
        add_action('wp_head', [__CLASS__, 'css'], 60);
        add_action('admin_menu', [__CLASS__, 'menu'], 99);
        add_action('admin_post_omg_legal_save', [__CLASS__, 'save']);
    }

    public static function get(string $key): string
    {
        $o = get_option(self::OPTION, []);
        $v = is_array($o) && isset($o[$key]) && trim((string) $o[$key]) !== '' ? (string) $o[$key] : (self::DEFAULTS[$key] ?? '');
        return trim($v);
    }

    /* ------------------------------------------------------------ shortcodes */

    public static function sc_field($atts): string
    {
        $a = shortcode_atts(['field' => 'name', 'link' => '1'], $atts);
        $f = (string) $a['field'];
        if ($f === 'address') {
            return nl2br(esc_html(self::get('address')));
        }
        if ($f === 'address_inline') {
            return esc_html(preg_replace('/\s*\n\s*/', ', ', self::get('address')));
        }
        if ($f === 'email') {
            $e = self::get('email');
            return $a['link'] === '1' ? '<a href="mailto:' . esc_attr($e) . '">' . esc_html($e) . '</a>' : esc_html($e);
        }
        if ($f === 'grievance') {
            $n = self::get('grievance_name');
            return esc_html($n !== '' ? $n . ', Grievance Officer' : 'Grievance Officer');
        }
        return esc_html(self::get($f));
    }

    public static function sc_company(): string
    {
        $rows = [['Company', self::get('name')], ['Registered address', preg_replace('/\s*\n\s*/', ', ', self::get('address'))]];
        foreach (['cin' => 'CIN', 'gstin' => 'GSTIN', 'phone' => 'Phone'] as $k => $l) {
            if (self::get($k) !== '') {
                $rows[] = [$l, self::get($k)];
            }
        }
        $out = '<dl class="omg-legal-co">';
        foreach ($rows as [$l, $v]) {
            $out .= '<div><dt>' . esc_html($l) . '</dt><dd>' . esc_html($v) . '</dd></div>';
        }
        $e = self::get('email');
        $out .= '<div><dt>Email</dt><dd><a href="mailto:' . esc_attr($e) . '">' . esc_html($e) . '</a></dd></div></dl>';
        return $out;
    }

    public static function sc_footer_pay(): string
    {
        $logos = [
            'Visa' => '<svg viewBox="0 0 64 22" width="58" height="20" aria-hidden="true"><text x="32" y="19" text-anchor="middle" fill="currentColor" font-family="Arial Black,Arial,sans-serif" font-size="22" font-style="italic" font-weight="900" letter-spacing=".5">VISA</text></svg>',
            'Mastercard' => '<svg viewBox="0 0 136 26" width="134" height="26" aria-hidden="true"><circle cx="12" cy="13" r="11" fill="currentColor" opacity=".95"/><circle cx="26" cy="13" r="11" fill="currentColor" opacity=".55"/><text x="42" y="18" fill="currentColor" font-family="Arial,sans-serif" font-size="15" font-weight="600">mastercard</text></svg>',
            'American Express' => '<svg viewBox="0 0 64 22" width="58" height="20" aria-hidden="true"><text x="32" y="17.5" text-anchor="middle" fill="currentColor" font-family="Arial Black,Arial,sans-serif" font-size="18" font-style="italic" font-weight="900" letter-spacing=".3">AMEX</text></svg>',
            'Apple Pay' => '<svg viewBox="0 0 58 24" width="54" height="22" aria-hidden="true"><path fill="currentColor" d="M15.5 12.8c0-2.4 2-3.6 2.1-3.7-1.1-1.7-2.9-1.9-3.6-1.9-1.5-.2-3 .9-3.7.9-.8 0-2-.9-3.2-.9-1.7 0-3.2 1-4.1 2.5-1.7 3-.4 7.5 1.3 9.9.8 1.2 1.8 2.5 3 2.5 1.2-.1 1.7-.8 3.2-.8s1.9.8 3.2.8c1.3 0 2.2-1.2 2.9-2.4.9-1.3 1.3-2.7 1.3-2.8 0 0-2.4-1-2.4-4.1zM13.1 5.5c.7-.8 1.1-1.9 1-3-1 0-2.1.7-2.8 1.5-.6.7-1.2 1.8-1 2.9 1 .1 2.1-.6 2.8-1.4z"/><text x="21" y="18" fill="currentColor" font-family="Arial,sans-serif" font-size="15" font-weight="600">Pay</text></svg>',
            'Google Pay' => '<svg viewBox="0 0 62 24" width="58" height="22" aria-hidden="true"><text x="0" y="18" fill="currentColor" font-family="Arial,sans-serif" font-size="17" font-weight="700">G</text><text x="16" y="18" fill="currentColor" font-family="Arial,sans-serif" font-size="15" font-weight="600">Pay</text></svg>',
        ];
        $out = '<div class="omg-fpay"><div class="omg-fpay-logos" aria-label="Payment methods">';
        foreach ($logos as $label => $svg) {
            $out .= '<span class="omg-fpay-l" role="img" aria-label="' . esc_attr($label) . '" title="' . esc_attr($label) . '">' . $svg . '</span>';
        }
        $out .= '<span class="omg-fpay-more">and more secure payment methods at checkout</span></div>';
        if (class_exists('OMG_Markets')) {
            $cur = OMG_Markets::currency();
            $lang = strtoupper(substr(get_locale(), 0, 2)) ?: 'EN';
            $out .= '<button type="button" class="omg-fpay-loc" data-omg-market aria-label="Change country, language and currency">'
                . '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><circle cx="12" cy="12" r="9.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M2.5 12h19M12 2.5c2.6 2.6 3.9 5.8 3.9 9.5s-1.3 6.9-3.9 9.5c-2.6-2.6-3.9-5.8-3.9-9.5S9.4 5.1 12 2.5z" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>'
                . '<span>' . esc_html($lang) . '</span><span class="sep">/</span><span>' . esc_html($cur) . '</span></button>';
        }
        return $out . '</div>';
    }

    public static function sc_footer_company(): string
    {
        $out = '<div class="omg-fco"><p class="omg-fco-t">' . esc_html(self::get('name')) . ' (website operator)</p>';
        $out .= '<p>Address:<br>' . nl2br(esc_html(self::get('address'))) . '</p>';
        if (self::get('cin') !== '') {
            $out .= '<p>CIN: ' . esc_html(self::get('cin')) . '</p>';
        }
        $e = self::get('email');
        return $out . '<p>Email: <a href="mailto:' . esc_attr($e) . '">' . esc_html($e) . '</a></p></div>';
    }

    /* ------------------------------------------------------------ requests */

    public static function redirects(): void
    {
        if (is_admin() || !is_404() && !is_page(array_keys(self::REDIRECTS))) {
            return;
        }
        $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        if (array_key_exists($path, self::REDIRECTS)) {
            $to = self::REDIRECTS[$path];
            wp_safe_redirect(home_url('/' . ($to !== '' ? $to . '/' : '')), 301);
            exit;
        }
    }

    public static function is_legal(): bool
    {
        return is_page(self::PAGES);
    }

    public static function body_class(array $c): array
    {
        if (self::is_legal()) {
            $c[] = 'omg-legal-page';
        }
        return $c;
    }

    public static function css(): void
    {
        ?>
<style id="omg-legal-css">
.omg-legal-page #brx-content>h1,.omg-legal-page #brx-content>.wp-block-post-title{display:none}
.omg-legal{max-width:880px;margin:0 auto;padding:48px 20px 72px;color:#d9d6e8;font:400 16px/1.75 Poppins,system-ui,sans-serif}
.omg-legal h1{margin:0 0 6px;color:#fff;font:800 clamp(28px,4vw,40px)/1.15 Poppins,system-ui,sans-serif;letter-spacing:-.01em}
.omg-legal .omg-legal-meta{margin:0 0 28px;color:#a79ec0;font-size:14px}
.omg-legal h2{margin:40px 0 12px;color:#fff;font:700 22px/1.3 Poppins,system-ui,sans-serif;scroll-margin-top:100px}
.omg-legal h3{margin:24px 0 8px;color:#f4f2fb;font:600 17px/1.4 Poppins,system-ui,sans-serif}
.omg-legal p,.omg-legal ul,.omg-legal ol{margin:0 0 14px}
.omg-legal ul,.omg-legal ol{padding-left:22px}
.omg-legal li{margin:0 0 6px}
.omg-legal a{color:#c4a8ff;text-decoration:underline;text-underline-offset:2px}
.omg-legal a:hover{color:#fff}
.omg-legal strong{color:#f4f2fb}
.omg-legal .omg-legal-intro{padding:20px 22px;border-radius:16px;background:#171424;border:1px solid #2a2540;margin:0 0 28px}
.omg-legal .omg-legal-toc{padding:18px 22px;border-radius:16px;background:#120f1d;border:1px solid #241f36;margin:0 0 8px}
.omg-legal .omg-legal-toc p{margin:0 0 8px;color:#fff;font-weight:600}
.omg-legal .omg-legal-toc ol{margin:0;columns:2;column-gap:28px}
.omg-legal table{width:100%;border-collapse:collapse;margin:0 0 18px;font-size:14px}
.omg-legal th,.omg-legal td{text-align:left;vertical-align:top;padding:10px 12px;border-bottom:1px solid #2a2540}
.omg-legal th{color:#fff;font-weight:600;background:#171424}
.omg-legal .omg-legal-co{display:grid;gap:0;margin:0 0 18px;border-radius:16px;overflow:hidden;border:1px solid #2a2540}
.omg-legal .omg-legal-co>div{display:grid;grid-template-columns:200px 1fr;gap:12px;padding:12px 18px;background:#171424;border-bottom:1px solid #2a2540}
.omg-legal .omg-legal-co>div:last-child{border-bottom:0}
.omg-legal .omg-legal-co dt{color:#a79ec0}
.omg-legal .omg-legal-co dd{margin:0;color:#fff}
@media (max-width:640px){.omg-legal{padding:32px 16px 56px;font-size:15px}.omg-legal .omg-legal-toc ol{columns:1}.omg-legal .omg-legal-co>div{grid-template-columns:1fr;gap:2px}.omg-legal table{display:block;overflow-x:auto}}
/* footer: payment row and operator block */
#omg-ftr .omg-fpay{display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;width:100%;padding-bottom:28px;border-bottom:1px solid #241A3A}
#omg-ftr .omg-fpay-logos{display:flex;align-items:center;flex-wrap:wrap;gap:14px 26px;color:#cdbbff}
#omg-ftr .omg-fpay-l{display:inline-flex;align-items:center;line-height:0;color:#e9e2ff}
#omg-ftr .omg-fpay-l:nth-child(2n){color:#cdbbff}
#omg-ftr .omg-fpay-more{color:#a79ec0;font-size:15px}
#omg-ftr .omg-fpay-loc{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;border:1px solid #2f2a45;background:#171424;color:#f4f2fb;font:600 15px/1 Poppins,system-ui,sans-serif;cursor:pointer;transition:border-color .15s,background .15s}
#omg-ftr .omg-fpay-loc:hover,#omg-ftr .omg-fpay-loc:focus-visible{border-color:#7C3AED;background:#1d1830;outline:none}
#omg-ftr .omg-fpay-loc svg{color:#b79cff}
#omg-ftr .omg-fpay-loc .sep{color:#6f6690}
#omg-ftr .omg-fco{font-size:14px;line-height:1.6;color:#a79ec0}
#omg-ftr .omg-fco p{margin:0 0 6px}
#omg-ftr .omg-fco .omg-fco-t{color:#e9e2ff;font-weight:600;font-size:15px}
#omg-ftr .omg-fco a{color:#c4a8ff}
#omg-ftr .brxe-shortcode{width:100%}
@media (max-width:767px){#omg-ftr .omg-fpay-logos{gap:12px 18px}#omg-ftr .omg-fpay-more{flex-basis:100%;font-size:14px}}
</style>
        <?php
    }

    /* ------------------------------------------------------------ admin */

    public static function menu(): void
    {
        add_submenu_page('woocommerce', 'OMG legal details', 'OMG legal details', 'manage_woocommerce', 'omg-legal', [__CLASS__, 'page']);
    }

    public static function page(): void
    {
        echo '<div class="wrap"><h1>OMG legal details</h1><p>Used by the legal pages (Terms, Privacy, Refunds, Legal notice…) and the footer. Change them here once; every page updates.</p>';
        if (!empty($_GET['saved'])) {
            echo '<div class="notice notice-success"><p>Saved.</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="omg_legal_save">';
        wp_nonce_field('omg_legal');
        echo '<table class="form-table">';
        foreach (self::LABELS as $k => $l) {
            $v = self::get($k);
            echo '<tr><th><label for="omg-l-' . esc_attr($k) . '">' . esc_html($l) . '</label></th><td>';
            echo $k === 'address'
                ? '<textarea id="omg-l-address" name="omg_legal[address]" rows="3" class="large-text">' . esc_textarea($v) . '</textarea>'
                : '<input id="omg-l-' . esc_attr($k) . '" name="omg_legal[' . esc_attr($k) . ']" value="' . esc_attr($v) . '" class="regular-text">';
            echo '</td></tr>';
        }
        echo '</table>';
        submit_button();
        echo '</form><p>Pages: ';
        foreach (self::PAGES as $s) {
            echo '<a href="' . esc_url(home_url('/' . $s . '/')) . '" target="_blank">/' . esc_html($s) . '/</a> ';
        }
        echo '</p><p><em>These texts are a starting point written for this store. Have them reviewed by a lawyer before launch.</em></p></div>';
    }

    public static function save(): void
    {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('omg_legal')) {
            wp_die('Not allowed.');
        }
        $in = (array) ($_POST['omg_legal'] ?? []);
        $out = [];
        foreach (array_keys(self::LABELS) as $k) {
            $v = wp_unslash((string) ($in[$k] ?? ''));
            $out[$k] = $k === 'address' ? sanitize_textarea_field($v) : ($k === 'email' ? sanitize_email($v) : sanitize_text_field($v));
        }
        update_option(self::OPTION, $out, false);
        wp_safe_redirect(admin_url('admin.php?page=omg-legal&saved=1'));
        exit;
    }
}

OMG_Legal::init();
