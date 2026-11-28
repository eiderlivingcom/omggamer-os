<?php
/**
 * The customer account at /account/: sidebar navigation and every account page.
 *
 * Account:            Overview · Orders · Settings · Linked accounts · Communication preferences · Password and security
 * Payment and rewards: Payment settings · Transactions (Purchases, Subscriptions, Account balance, Code redemptions)
 *                     · Redeem code · PRO membership
 * WooCommerce pages (addresses, saved payment methods, subscriptions) still work inside the same layout.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Account
{
    /** Our endpoints → page titles. */
    private const PAGES = [
        'settings' => 'Settings',
        'linked-accounts' => 'Linked accounts',
        'communication' => 'Communication preferences',
        'security' => 'Password and security',
        'payments' => 'Payment settings',
        'transactions' => 'Transactions',
        'redeem' => 'Redeem code',
        'membership' => 'PRO membership',
    ];

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'old_url_redirect'], 0);
        add_filter('woocommerce_get_query_vars', [__CLASS__, 'query_vars']);
        foreach (self::PAGES as $ep => $title) {
            add_filter('woocommerce_endpoint_' . $ep . '_title', static fn () => $title);
        }
        add_filter('body_class', [__CLASS__, 'body_class']);
        add_action('wp', [__CLASS__, 'takeover']);
        add_action('template_redirect', [__CLASS__, 'handle_post'], 20);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets'], 50);
    }

    /** /my-account/… → /account/… (the account moved). */
    public static function old_url_redirect(): void
    {
        $path = (string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (preg_match('#^/my-account(/.*)?$#', $path, $m)) {
            $q = (string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
            wp_safe_redirect(home_url('/account' . ($m[1] ?? '/')) . ($q !== '' ? '?' . $q : ''), 301);
            exit;
        }
    }

    public static function query_vars(array $vars): array
    {
        foreach (array_keys(self::PAGES) as $ep) {
            $vars[$ep] = $ep;
        }
        return $vars;
    }

    private static function on_account(): bool
    {
        return function_exists('is_account_page') && is_account_page() && is_user_logged_in();
    }

    public static function body_class(array $c): array
    {
        if (self::on_account()) {
            $c[] = 'omg-acc2';
        }
        return $c;
    }

    public static function endpoint(): string
    {
        return function_exists('WC') && WC()->query ? (string) WC()->query->get_current_endpoint() : '';
    }

    public static function url(string $ep = '', array $args = []): string
    {
        $u = $ep === '' ? wc_get_page_permalink('myaccount') : wc_get_account_endpoint_url($ep);
        return $args ? add_query_arg($args, $u) : $u;
    }

    /** Replace WooCommerce's navigation and content dispatcher with ours. */
    public static function takeover(): void
    {
        if (!self::on_account()) {
            return;
        }
        remove_action('woocommerce_account_navigation', 'woocommerce_account_navigation');
        add_action('woocommerce_account_navigation', [__CLASS__, 'nav']);
        remove_action('woocommerce_account_content', 'woocommerce_account_content');
        add_action('woocommerce_account_content', [__CLASS__, 'content']);
    }

    /* ---------------------------------------------------------------- nav */

    private static function icon(string $name): string
    {
        $p = [
            'home' => '<path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
            'bag' => '<path d="M5 8h14l-1 12H6zM9 8V6a3 3 0 0 1 6 0v2"/>',
            'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
            'link' => '<circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M8.2 10.8l7.6-3.6M8.2 13.2l7.6 3.6"/>',
            'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
            'shield' => '<path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
            'wallet' => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M16 14.5h2"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'ticket' => '<path d="M3 8a2 2 0 0 0 0 4v4h18v-4a2 2 0 0 1 0-4V4H3z" transform="translate(0 2)"/><path d="M9 6v12" stroke-dasharray="2 2"/>',
            'star' => '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
            'out' => '<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 16l-4-4 4-4M6 12h10"/>',
        ][$name] ?? '';
        return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
    }

    public static function nav(): void
    {
        $ep = self::endpoint();
        $active = static function (array $eps) use ($ep): bool {
            return in_array($ep, $eps, true);
        };
        $view = sanitize_key($_GET['view'] ?? 'purchases');
        $groups = [
            'Account' => [
                ['', 'Overview', 'home', ['']],
                ['orders', 'Orders', 'bag', ['orders', 'view-order']],
                ['settings', 'Settings', 'user', ['settings', 'edit-account', 'edit-address']],
                ['linked-accounts', 'Linked accounts', 'link', ['linked-accounts']],
                ['communication', 'Communication preferences', 'mail', ['communication']],
                ['security', 'Password and security', 'shield', ['security']],
            ],
            'Payment and rewards' => [
                ['payments', 'Payment settings', 'wallet', ['payments', 'payment-methods', 'add-payment-method']],
                ['transactions', 'Transactions', 'clock', ['transactions']],
                ['redeem', 'Redeem code', 'ticket', ['redeem']],
                ['membership', 'PRO membership', 'star', ['membership', 'subscriptions', 'view-subscription']],
            ],
        ];
        echo '<nav class="oa-nav" aria-label="Account">';
        foreach ($groups as $label => $links) {
            echo '<div class="oa-nav-g"><p class="oa-nav-h">' . esc_html($label) . '</p><ul>';
            foreach ($links as [$slug, $text, $icon, $eps]) {
                $on = $active($eps);
                echo '<li><a class="' . ($on ? 'on' : '') . '" href="' . esc_url(self::url($slug)) . '"' . ($on ? ' aria-current="page"' : '') . '>' . self::icon($icon) . '<span>' . esc_html($text) . '</span>'
                    . ($slug === 'transactions' ? '<svg class="oa-chev" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2"/></svg>' : '') . '</a>';
                if ($slug === 'transactions' && $on) {
                    echo '<ul class="oa-sub">';
                    foreach (['purchases' => 'Purchases', 'subscriptions' => 'Subscriptions', 'balance' => 'Account balance', 'redemptions' => 'Code redemptions'] as $v => $t) {
                        echo '<li><a class="' . ($view === $v ? 'on' : '') . '" href="' . esc_url(self::url('transactions', ['view' => $v])) . '">' . esc_html($t) . '</a></li>';
                    }
                    echo '</ul>';
                }
                echo '</li>';
            }
            echo '</ul></div>';
        }
        echo '<div class="oa-nav-g"><ul><li><a class="oa-out" href="' . esc_url(wc_logout_url()) . '">' . self::icon('out') . '<span>Log out</span></a></li></ul></div></nav>';
    }

    /* ------------------------------------------------------------ dispatcher */

    public static function content(): void
    {
        $ep = self::endpoint();
        echo '<div class="oa-page">';
        wc_print_notices();
        switch ($ep) {
            case '':
                self::overview();
                break;
            case 'orders':
                OMG_Account_Orders::render_list();
                break;
            case 'view-order':
                OMG_Account_Orders::render_details((int) get_query_var('view-order'));
                break;
            case 'settings':
                self::settings();
                break;
            case 'linked-accounts':
                self::linked();
                break;
            case 'communication':
                self::communication();
                break;
            case 'security':
                self::security();
                break;
            case 'payments':
                self::payments();
                break;
            case 'transactions':
                self::transactions();
                break;
            case 'redeem':
                self::redeem();
                break;
            case 'membership':
                self::membership();
                break;
            default:
                // WooCommerce pages (addresses, saved cards, subscriptions…) in our layout.
                woocommerce_account_content();
        }
        echo '</div>';
    }

    private static function head(string $title, string $sub = ''): void
    {
        echo '<div class="oa-head"><h1>' . esc_html($title) . '</h1>' . ($sub !== '' ? '<p>' . wp_kses_post($sub) . '</p>' : '') . '</div>';
    }

    private static function money_base(float $base): string
    {
        return wc_price($base * OMG_Wallet::factor());
    }

    /* -------------------------------------------------------------- overview */

    private static function overview(): void
    {
        $u = wp_get_current_user();
        $name = trim($u->first_name) ?: $u->display_name;
        self::head('Hi, ' . $name, 'Your orders, keys, balance and membership in one place.');
        $bal = OMG_Wallet::balance($u->ID);
        $pro = self::pro_state($u->ID);
        echo '<div class="oa-grid3">'
            . '<a class="oa-card oa-stat" href="' . esc_url(self::url('payments')) . '"><span>Account balance</span><b>' . wp_kses_post(self::money_base($bal)) . '</b><em>Redeem a code to top up</em></a>'
            . '<a class="oa-card oa-stat" href="' . esc_url(self::url('membership')) . '"><span>PRO membership</span><b class="' . ($pro['active'] ? 'pro' : '') . '">' . esc_html($pro['active'] ? 'Active' : 'Not a member') . '</b><em>' . esc_html($pro['line']) . '</em></a>'
            . '<a class="oa-card oa-stat" href="' . esc_url(self::url('orders')) . '"><span>Orders</span><b>' . count(OMG_Account_Orders::customer_orders($u->ID)) . '</b><em>Keys, accounts and deliveries</em></a></div>';
        $split = OMG_Account_Orders::split(OMG_Account_Orders::customer_orders($u->ID, 6));
        $recent = array_slice(array_merge($split['digital'], $split['physical']), 0, 3);
        usort($recent, static fn ($a, $b) => $b[0]->get_date_created() <=> $a[0]->get_date_created());
        echo '<h2 class="oa-h2">Recent orders <a href="' . esc_url(self::url('orders')) . '">See all ›</a></h2>';
        if (!$recent) {
            echo '<div class="oa-empty"><span class="ic">!</span><p>No orders yet.</p><a class="oa-btn pri" href="' . esc_url(wc_get_page_permalink('shop')) . '">Browse the store</a></div>';
        }
        $seen = [];
        foreach ($recent as [$o, $items]) {
            if (isset($seen[$o->get_id()])) {
                continue;
            }
            $seen[$o->get_id()] = 1;
            OMG_Account_Orders::order_card($o, $items, OMG_Account_Orders::is_physical_item($items[0]));
        }
    }

    /* -------------------------------------------------------------- settings */

    private static function field(string $name, string $label, string $value, array $o = []): string
    {
        $type = $o['type'] ?? 'text';
        $req = !empty($o['required']);
        return '<label class="oa-field' . (!empty($o['wide']) ? ' wide' : '') . '"><span>' . esc_html($label) . ($req ? ' <i>*</i>' : '') . '</span>'
            . '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . ($req ? ' required' : '') . (!empty($o['readonly']) ? ' readonly' : '') . (!empty($o['auto']) ? ' autocomplete="' . esc_attr($o['auto']) . '"' : '') . '></label>';
    }

    private static function form_open(string $action): string
    {
        return '<form method="post" class="oa-form">' . wp_nonce_field('omg_acc_' . $action, '_omgacc', true, false) . '<input type="hidden" name="omg_acc" value="' . esc_attr($action) . '">';
    }

    private static function settings(): void
    {
        $u = wp_get_current_user();
        $m = static fn (string $k) => (string) get_user_meta($u->ID, $k, true);
        self::head('Settings', 'Manage your account details.');
        echo '<section class="oa-sec"><h2>Account information</h2><p class="oa-id">Customer ID: <b>' . esc_html(class_exists('OMG_Ids') ? OMG_Ids::customer_id($u->ID) : (string) $u->ID) . '</b></p>';
        echo self::form_open('profile') . '<div class="oa-row">' . self::field('display_name', 'Display name', $u->display_name, ['required' => true, 'auto' => 'nickname'])
            . '</div><button class="oa-btn pri" type="submit">Save display name</button></form>';
        echo self::form_open('email') . '<div class="oa-row">' . self::field('email', 'Email address', $u->user_email, ['type' => 'email', 'required' => true, 'auto' => 'email'])
            . self::field('current_password', 'Current password (to change your email)', '', ['type' => 'password', 'auto' => 'current-password']) . '</div>'
            . '<p class="oa-note">Order emails and keys go to this address.</p><button class="oa-btn" type="submit">Change email</button></form></section>';

        $countries = WC()->countries ? WC()->countries->get_countries() : [];
        $country = $m('billing_country') ?: (class_exists('OMG_Markets') ? strtoupper((string) OMG_Markets::current()) : '');
        $opts = '<option value="">Choose a country</option>';
        foreach ($countries as $cc => $cn) {
            $opts .= '<option value="' . esc_attr($cc) . '"' . selected($country, $cc, false) . '>' . esc_html(html_entity_decode($cn)) . '</option>';
        }
        echo '<section class="oa-sec"><h2>Personal details</h2><p class="oa-sub">Your name and address for receipts. These details are private. <a href="' . esc_url(get_privacy_policy_url() ?: home_url('/privacy-policy/')) . '">Privacy policy</a></p>'
            . self::form_open('personal') . '<div class="oa-row">' . self::field('first_name', 'First name', $m('billing_first_name') ?: $u->first_name, ['auto' => 'given-name']) . self::field('last_name', 'Last name', $m('billing_last_name') ?: $u->last_name, ['auto' => 'family-name']) . '</div>'
            . '<h3>Address</h3><div class="oa-row">' . self::field('address_1', 'Address line 1', $m('billing_address_1'), ['auto' => 'address-line1']) . self::field('address_2', 'Address line 2', $m('billing_address_2'), ['auto' => 'address-line2']) . '</div>'
            . '<div class="oa-row three">' . self::field('city', 'City', $m('billing_city'), ['auto' => 'address-level2']) . self::field('state', 'Region', $m('billing_state'), ['auto' => 'address-level1']) . self::field('postcode', 'Postal code', $m('billing_postcode'), ['auto' => 'postal-code']) . '</div>'
            . '<div class="oa-row"><label class="oa-field"><span>Country / region</span><select name="country" autocomplete="country">' . $opts . '</select></label>' . self::field('phone', 'Phone (optional)', $m('billing_phone'), ['type' => 'tel', 'auto' => 'tel']) . '</div>'
            . '<button class="oa-btn pri" type="submit">Save changes</button></form>'
            . '<p class="oa-note"><a href="' . esc_url(wc_get_account_endpoint_url('edit-address')) . '">Delivery address for physical orders ›</a></p></section>';

        echo '<section class="oa-sec"><h2>Company</h2><p class="oa-sub">Buying for a business? Add your company to show it on your receipts. <b>If you are registered for VAT, enter your company VAT number.</b></p>'
            . self::form_open('company') . '<div class="oa-row">' . self::field('company_name', 'Company name', $m('omg_company_name'), ['auto' => 'organization']) . self::field('company_vat', 'Company VAT number', $m('omg_company_vat')) . '</div>'
            . '<h3>Company address</h3><div class="oa-row">' . self::field('company_address_1', 'Address line 1', $m('omg_company_address_1')) . self::field('company_address_2', 'Address line 2', $m('omg_company_address_2')) . '</div>'
            . '<div class="oa-row three">' . self::field('company_city', 'City', $m('omg_company_city')) . self::field('company_region', 'Region', $m('omg_company_region')) . self::field('company_postcode', 'Postal code', $m('omg_company_postcode')) . '</div>'
            . '<button class="oa-btn pri" type="submit">Save company</button></form></section>';

        $req = self::export_request($u->user_email);
        echo '<section class="oa-sec"><h2>Download account data</h2><p class="oa-sub">Get a copy of the data you have shared with us. We email you a download link once it is ready; the link works for 15 days.</p>';
        if ($req) {
            echo '<p class="oa-note">Requested on ' . esc_html(wp_date('j M Y', strtotime($req->post_date_gmt . ' UTC'))) . ' (' . esc_html(['request-pending' => 'check your email to confirm it', 'request-confirmed' => 'being prepared', 'request-completed' => 'sent'][$req->post_status] ?? 'in progress') . ').</p>';
        } else {
            echo self::form_open('export') . '<button class="oa-btn" type="submit">Request download</button></form>';
        }
        echo '</section>';
    }

    private static function export_request(string $email): ?WP_Post
    {
        $q = get_posts(['post_type' => 'user_request', 'post_name__in' => ['export_personal_data'], 'title' => $email, 'post_status' => ['request-pending', 'request-confirmed', 'request-completed'], 'numberposts' => 1, 'date_query' => [['after' => '15 days ago']]]);
        return $q ? $q[0] : null;
    }

    /* --------------------------------------------------------- linked accounts */

    private static function linked(): void
    {
        $uid = get_current_user_id();
        $g = (string) get_user_meta($uid, '_omg_google_email', true);
        self::head('Linked accounts', 'Link your accounts to sign in to OMGGamer faster.');
        $start = add_query_arg(['omg_google' => 'start', 'back' => rawurlencode(self::url('linked-accounts'))], home_url('/'));
        echo '<section class="oa-sec"><h2>Your linked accounts</h2><div class="oa-link-row"><span class="oa-logo g" aria-hidden="true"><svg viewBox="0 0 48 48" width="26" height="26"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.3 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3 0 5.8 1.1 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3 0 5.8 1.1 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.3 0-9.7-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg></span>'
            . '<div><b>Google</b><span>' . ($g !== '' ? esc_html($g) : 'Not linked') . '</span></div>';
        if ($g !== '') {
            echo self::form_open('unlink_google') . '<button class="oa-btn sm" type="submit">Unlink</button></form>';
        } else {
            echo '<a class="oa-btn sm pri" href="' . esc_url($start) . '">Link Google</a>';
        }
        echo '</div></section>';
    }

    /* ----------------------------------------------------------- communication */

    private static function newsletter_on(WP_User $u): bool
    {
        if (function_exists('FluentCrmApi')) {
            $c = FluentCrmApi('contacts')->getContact($u->user_email);
            if ($c) {
                return $c->status === 'subscribed';
            }
        }
        return get_user_meta($u->ID, 'omg_newsletter', true) === 'yes';
    }

    private static function communication(): void
    {
        $u = wp_get_current_user();
        $on = self::newsletter_on($u);
        self::head('Communication preferences', 'We always tell you about your orders, keys and account. Choose what else you want to hear about.');
        echo '<section class="oa-sec"><h2>Email preferences</h2><p class="oa-sub">Order confirmations, keys, receipts, password resets and security emails are always sent, whatever you choose here.</p>'
            . self::form_open('communication') . '<label class="oa-switch"><input type="checkbox" name="newsletter" value="1"' . checked($on, true, false) . ' onchange="this.form.submit()"><span class="tg" aria-hidden="true"></span><span>I would like to receive deals, restock alerts and news from OMGGamer (about one email a week).</span></label>'
            . '<noscript><button class="oa-btn" type="submit">Save</button></noscript></form></section>';
    }

    /* -------------------------------------------------------------- security */

    private static function security(): void
    {
        $uid = get_current_user_id();
        $sessions = class_exists('WP_Session_Tokens') ? count(WP_Session_Tokens::get_instance($uid)->get_all()) : 1;
        self::head('Password and security', 'Keep your account and your keys safe.');
        echo '<section class="oa-sec"><h2>Change password</h2><p class="oa-sub">Use at least 8 characters. A long phrase is easier to remember and harder to guess.</p>'
            . self::form_open('password') . '<div class="oa-row">' . self::field('current_password', 'Current password', '', ['type' => 'password', 'required' => true, 'auto' => 'current-password']) . '</div>'
            . '<div class="oa-row">' . self::field('new_password', 'New password', '', ['type' => 'password', 'required' => true, 'auto' => 'new-password']) . self::field('confirm_password', 'Repeat new password', '', ['type' => 'password', 'required' => true, 'auto' => 'new-password']) . '</div>'
            . '<button class="oa-btn pri" type="submit">Change password</button></form>'
            . '<p class="oa-note">Signed up with Google and never set a password? <a href="' . esc_url(wp_lostpassword_url()) . '">Set one by email</a>.</p></section>';
        echo '<section class="oa-sec"><h2>Signed-in devices</h2><p class="oa-sub">You are signed in on ' . (int) $sessions . ' ' . _n('device', 'devices', $sessions) . '. If you see something you do not recognise, sign out everywhere else and change your password.</p>'
            . ($sessions > 1 ? self::form_open('sessions') . '<button class="oa-btn" type="submit">Sign out of all other devices</button></form>' : '') . '</section>';
    }

    /* -------------------------------------------------------------- payments */

    private static function payments(): void
    {
        $uid = get_current_user_id();
        $bal = OMG_Wallet::balance($uid);
        self::head('Payment settings', 'Your account balance and saved payment methods. <a href="' . esc_url(get_privacy_policy_url() ?: home_url('/privacy-policy/')) . '">Privacy policy</a>');
        echo '<section class="oa-sec"><h2>Account balance</h2><p class="oa-sub">Use your balance to pay for games, keys and top-ups at checkout. Balance comes from top-ups from OMGGamer and from top-up codes you redeem; it is non-refundable and cannot be withdrawn. <a href="' . esc_url(home_url('/terms-and-conditions/')) . '">View terms</a></p>'
            . '<p class="oa-bal-l">Balance</p><p class="oa-bal">' . wp_kses_post(self::money_base($bal)) . '</p>'
            . (OMG_Wallet::factor() !== 1.0 ? '<p class="oa-note">Kept in ' . esc_html(OMG_Wallet::base()) . ' (' . wp_kses_post(wc_price($bal, ['currency' => OMG_Wallet::base()])) . '), shown in your currency.</p>' : '')
            . '<div class="oa-actions"><a class="oa-btn pri" href="' . esc_url(self::url('redeem')) . '"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><rect x="3" y="7" width="18" height="13" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 7v13M3 11h18M12 7c-2-4-6-3-5 0M12 7c2-4 6-3 5 0" fill="none" stroke="currentColor" stroke-width="2"/></svg>Redeem top-up code</a></div>'
            . '<p class="oa-note"><a href="' . esc_url(self::url('transactions', ['view' => 'balance'])) . '">View balance transactions</a></p></section>';
        echo '<section class="oa-sec"><h2>Your payment methods</h2><p class="oa-sub">Cards you save at checkout are stored securely by our payment provider, Stripe; we never see your full card number.</p>';
        $saved = function_exists('wc_get_customer_saved_methods_list') ? wc_get_customer_saved_methods_list($uid) : [];
        $any = false;
        foreach ($saved as $type => $methods) {
            foreach ($methods as $mth) {
                $any = true;
                $brand = ucfirst((string) ($mth['method']['brand'] ?? $type));
                $last4 = (string) ($mth['method']['last4'] ?? '');
                echo '<div class="oa-pm"><span class="oa-pm-ic" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><rect x="2" y="5" width="20" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M2 10h20" stroke="currentColor" stroke-width="1.8"/></svg></span><div><b>' . esc_html($brand) . ($last4 ? ' •••• ' . esc_html($last4) : '') . '</b><span>' . esc_html(!empty($mth['expires']) ? 'Expires ' . $mth['expires'] : '') . (!empty($mth['is_default']) ? ' · Default' : '') . '</span></div>';
                if (!empty($mth['actions']['delete']['url'])) {
                    echo '<a class="oa-btn sm" href="' . esc_url($mth['actions']['delete']['url']) . '">Remove</a>';
                }
                echo '</div>';
            }
        }
        if (!$any) {
            echo '<div class="oa-empty sm"><p>No saved payment methods. You can save a card when you check out.</p></div>';
        }
        echo '</section>';
    }

    /* ---------------------------------------------------------- transactions */

    private static function transactions(): void
    {
        $uid = get_current_user_id();
        $view = sanitize_key($_GET['view'] ?? 'purchases');
        $titles = ['purchases' => ['Purchases', 'Every order you have placed. Open one for its keys, receipt and details.'], 'subscriptions' => ['Subscriptions', 'Your PRO membership payments.'], 'balance' => ['Account balance', 'Top-ups, redeemed codes and orders paid from your balance.'], 'redemptions' => ['Code redemptions', 'Top-up codes you have redeemed.']];
        [$t, $s] = $titles[$view] ?? $titles['purchases'];
        self::head($t, $s);
        if ($view === 'balance' || $view === 'redemptions') {
            $rows = OMG_Wallet::entries($uid, $view === 'redemptions' ? ['redeem'] : [], 100);
            if (!$rows) {
                self::empty_box($view === 'redemptions' ? 'No codes redeemed yet.' : 'No balance activity yet.');
                return;
            }
            echo '<div class="oa-table"><div class="oa-tr oa-th"><span>Date</span><span>Description</span><span class="n">Amount</span></div>';
            foreach ($rows as $r) {
                $amt = (float) $r['amount'];
                echo '<div class="oa-tr"><span>' . esc_html(wp_date('M j, Y', strtotime($r['created_at'] . ' UTC'))) . '</span><span><b>' . esc_html(OMG_Wallet::kind_label($r['kind'])) . '</b>' . ($r['note'] !== '' ? '<small>' . esc_html($r['note']) . '</small>' : '') . '</span><span class="n ' . ($amt >= 0 ? 'good' : '') . '">' . ($amt >= 0 ? '+ ' : '− ') . wp_kses_post(wc_price(abs($amt), ['currency' => $r['currency']])) . '</span></div>';
            }
            echo '</div>';
            return;
        }
        $orders = OMG_Account_Orders::customer_orders($uid);
        if ($view === 'subscriptions') {
            $pro = class_exists('OMG_Pro_Offer') ? OMG_Pro_Offer::PRODUCT : 1906;
            $orders = array_values(array_filter($orders, static function ($o) use ($pro) {
                foreach ($o->get_items() as $it) {
                    if ((int) $it->get_product_id() === $pro) {
                        return true;
                    }
                }
                return function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($o);
            }));
        }
        if (!$orders) {
            self::empty_box($view === 'subscriptions' ? 'No charges have been made for a membership yet.' : 'No purchases yet.');
            return;
        }
        echo '<div class="oa-table"><div class="oa-tr oa-th"><span>Date</span><span>Description</span><span class="n">Price</span><span class="n">Status</span></div>';
        foreach (array_slice($orders, 0, 100) as $o) {
            $items = array_values($o->get_items());
            $first = $items ? $items[0]->get_name() : 'Order';
            [$label, $cls] = OMG_Account_Orders::status($o);
            echo '<a class="oa-tr" href="' . esc_url($o->get_view_order_url()) . '"><span>' . esc_html(wc_format_datetime($o->get_date_created(), 'M j, Y')) . '</span><span><small>Order #' . esc_html($o->get_order_number()) . '</small><b>' . esc_html($first) . (count($items) > 1 ? ' + ' . (count($items) - 1) . ' more' : '') . '</b></span><span class="n">' . wp_kses_post(wc_price($o->get_total(), ['currency' => $o->get_currency()])) . '</span><span class="n"><span class="oa-pill ' . esc_attr($cls) . '">' . esc_html($label) . '</span></span></a>';
        }
        echo '</div>';
    }

    private static function empty_box(string $msg): void
    {
        echo '<div class="oa-empty"><span class="ic">!</span><p>' . esc_html($msg) . '</p></div>';
    }

    /* ---------------------------------------------------------------- redeem */

    private static function redeem(): void
    {
        self::head('Redeem code', 'Enter your OMGGamer top-up code to add it to your account balance. It looks like this: K5XS-HCTL-EQQ5-RF6U.');
        echo '<section class="oa-sec">' . self::form_open('redeem') . '<div class="oa-row">' . self::field('code', 'Enter your code', '', ['required' => true, 'auto' => 'off']) . '</div><button class="oa-btn pri" type="submit">Redeem code</button></form></section>';
        echo '<section class="oa-sec"><h2>Where to find your code</h2><div class="oa-where"><div><span class="ic">' . self::icon('mail') . '</span><b>Email</b><p>Top-up codes from OMGGamer (giveaways, goodwill credit, partner rewards) arrive by email.</p></div>'
            . '<div><span class="ic">' . self::icon('ticket') . '</span><b>Printed card or voucher</b><p>Codes on printed OMGGamer vouchers work the same way.</p></div></div>'
            . '<p class="oa-note">Bought a game key, gift card or Robux? Those are activated on their own platform (Steam, PlayStation, Roblox…), not here: open the order and follow the steps. <a href="' . esc_url(home_url('/activate/')) . '">How to activate</a></p></section>';
    }

    /* ------------------------------------------------------------ membership */

    /** @return array{active:bool, line:string, sub:?WC_Subscription} */
    public static function pro_state(int $uid): array
    {
        $pro = class_exists('OMG_Pro_Offer') ? OMG_Pro_Offer::PRODUCT : 1906;
        $sub = null;
        if (function_exists('wcs_get_users_subscriptions')) {
            foreach (wcs_get_users_subscriptions($uid) as $s) {
                if ($s->has_product($pro) && (!$sub || $s->has_status(['active', 'pending-cancel']))) {
                    $sub = $s;
                }
            }
        }
        if ($sub && $sub->has_status(['active', 'pending-cancel'])) {
            $next = $sub->get_date('next_payment');
            $end = $sub->get_date('end');
            $line = $sub->has_status('pending-cancel') ? 'Ends ' . wp_date('j M Y', strtotime($end ?: $sub->get_date('next_payment') ?: 'now')) : ($next ? 'Renews ' . wp_date('j M Y', strtotime($next)) : 'Active');
            return ['active' => true, 'line' => $line, 'sub' => $sub];
        }
        if (class_exists('OMG_Pro_Offer') && OMG_Pro_Offer::is_member()) {
            return ['active' => true, 'line' => 'Active for 31 days from your last PRO payment', 'sub' => null];
        }
        return ['active' => false, 'line' => 'Member prices on every order', 'sub' => $sub];
    }

    private static function membership(): void
    {
        $uid = get_current_user_id();
        $st = self::pro_state($uid);
        self::head('PRO membership', 'Member prices on eligible games, priority support and early access to drops.');
        $sub = $st['sub'];
        echo '<section class="oa-pro ' . ($st['active'] ? 'on' : '') . '"><div class="oa-pro-card"><b>OMG</b><span>PRO</span></div><div class="oa-pro-main"><p class="oa-pro-st">' . ($st['active'] ? '<span class="oa-pill ok">Active</span>' : '<span class="oa-pill mute">Not a member</span>') . '</p>';
        if ($st['active']) {
            echo '<h2>You are an OMG PRO member</h2><p>' . esc_html($st['line']) . '.</p>';
            if ($sub) {
                echo '<dl class="oa-dl"><div><dt>Plan</dt><dd>' . wp_kses_post($sub->get_formatted_order_total()) . '</dd></div>'
                    . '<div><dt>Member since</dt><dd>' . esc_html(wp_date('j M Y', strtotime($sub->get_date('start')))) . '</dd></div>'
                    . ($sub->get_date('next_payment') ? '<div><dt>Next payment</dt><dd>' . esc_html(wp_date('j M Y', strtotime($sub->get_date('next_payment')))) . '</dd></div>' : '')
                    . '<div><dt>Payment method</dt><dd>' . esc_html($sub->get_payment_method_to_display('customer')) . '</dd></div></dl><div class="oa-actions">';
                foreach (function_exists('wcs_get_all_user_actions_for_subscription') ? wcs_get_all_user_actions_for_subscription($sub, $uid) : [] as $key => $a) {
                    echo '<a class="oa-btn ' . ($key === 'cancel' ? 'danger' : '') . '" href="' . esc_url($a['url']) . '">' . esc_html($a['name']) . '</a>';
                }
                echo '<a class="oa-btn" href="' . esc_url($sub->get_view_order_url()) . '">Membership details</a></div>';
            }
        } else {
            $price = class_exists('OMG_Pro_Offer') ? (OMG_Pro_Offer::eligible() ? sprintf('%s for the first month, then %s a month', wp_strip_all_tags(self::money_base(OMG_Pro_Offer::FIRST)), wp_strip_all_tags(self::money_base(OMG_Pro_Offer::MONTHLY))) : sprintf('%s a month', wp_strip_all_tags(self::money_base(OMG_Pro_Offer::MONTHLY)))) : '';
            echo '<h2>Pay less on every game</h2><p>' . esc_html($price) . '. Cancel any time.</p><div class="oa-actions"><a class="oa-btn pri" href="' . esc_url(home_url('/pro/')) . '">Join PRO</a></div>';
            if ($sub) {
                echo '<p class="oa-note">Your previous membership is ' . esc_html(strtolower(wcs_get_subscription_status_name($sub->get_status()))) . '. <a href="' . esc_url($sub->get_view_order_url()) . '">Details</a></p>';
            }
        }
        echo '</div></section><section class="oa-sec"><h2>What PRO includes</h2><ul class="oa-ticks"><li>Member prices on products with the PRO badge</li><li>Priority 24/7 support</li><li>Early access to drops and restocks</li><li>Cancel any time from this page; PRO stays on until the end of the month you paid for</li></ul><p class="oa-note"><a href="' . esc_url(home_url('/pro-terms/')) . '">PRO membership terms</a></p></section>';
    }

    /* ------------------------------------------------------------ form posts */

    public static function handle_post(): void
    {
        if (empty($_POST['omg_acc']) || !is_user_logged_in()) {
            return;
        }
        $action = sanitize_key($_POST['omg_acc']);
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_omgacc'] ?? '')), 'omg_acc_' . $action)) {
            wc_add_notice('Your session expired. Please try again.', 'error');
            return;
        }
        $u = wp_get_current_user();
        $p = static fn (string $k) => sanitize_text_field(wp_unslash($_POST[$k] ?? ''));
        $back = wp_get_referer() ?: self::url();
        switch ($action) {
            case 'profile':
                $name = $p('display_name');
                if ($name === '' || mb_strlen($name) > 50) {
                    wc_add_notice('Enter a display name (up to 50 characters).', 'error');
                    break;
                }
                wp_update_user(['ID' => $u->ID, 'display_name' => $name, 'nickname' => $name]);
                wc_add_notice('Display name saved.');
                break;
            case 'email':
                $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
                if (strcasecmp($email, $u->user_email) === 0) {
                    break;
                }
                if (!is_email($email)) {
                    wc_add_notice('Enter a valid email address.', 'error');
                } elseif (!wp_check_password(wp_unslash($_POST['current_password'] ?? ''), $u->user_pass, $u->ID)) {
                    wc_add_notice('Your current password is not correct.', 'error');
                } elseif (email_exists($email)) {
                    wc_add_notice('Another account already uses that email.', 'error');
                } else {
                    wp_update_user(['ID' => $u->ID, 'user_email' => $email]);
                    update_user_meta($u->ID, 'billing_email', $email);
                    wc_add_notice('Email changed. Order emails now go to ' . $email . '.');
                }
                break;
            case 'personal':
                $map = ['first_name' => 'billing_first_name', 'last_name' => 'billing_last_name', 'address_1' => 'billing_address_1', 'address_2' => 'billing_address_2', 'city' => 'billing_city', 'state' => 'billing_state', 'postcode' => 'billing_postcode', 'country' => 'billing_country', 'phone' => 'billing_phone'];
                foreach ($map as $f => $meta) {
                    update_user_meta($u->ID, $meta, $p($f));
                }
                wp_update_user(['ID' => $u->ID, 'first_name' => $p('first_name'), 'last_name' => $p('last_name')]);
                wc_add_notice('Personal details saved.');
                break;
            case 'company':
                foreach (['company_name', 'company_vat', 'company_address_1', 'company_address_2', 'company_city', 'company_region', 'company_postcode'] as $f) {
                    update_user_meta($u->ID, 'omg_' . $f, $p($f));
                }
                update_user_meta($u->ID, 'billing_company', $p('company_name'));
                wc_add_notice('Company details saved. They appear on your receipts.');
                break;
            case 'export':
                $id = wp_create_user_request($u->user_email, 'export_personal_data');
                if (is_wp_error($id)) {
                    wc_add_notice($id->get_error_code() === 'duplicate_request' ? 'You already have a request in progress.' : 'We could not create the request. Please contact support.', 'error');
                } else {
                    wp_send_user_request($id);
                    wc_add_notice('Request received. Check your email to confirm it; we then prepare your data.');
                }
                break;
            case 'unlink_google':
                delete_user_meta($u->ID, '_omg_google_email');
                delete_user_meta($u->ID, '_omg_google_sub');
                wc_add_notice('Google unlinked. You can still log in with your email and password.');
                break;
            case 'communication':
                $on = !empty($_POST['newsletter']);
                update_user_meta($u->ID, 'omg_newsletter', $on ? 'yes' : 'no');
                if (function_exists('FluentCrmApi')) {
                    FluentCrmApi('contacts')->createOrUpdate(['email' => $u->user_email, 'first_name' => $u->first_name, 'last_name' => $u->last_name, 'status' => $on ? 'subscribed' : 'unsubscribed'], true);
                }
                wc_add_notice($on ? 'You are subscribed to deals and news.' : 'You will no longer get deals and news emails.');
                break;
            case 'password':
                $cur = wp_unslash($_POST['current_password'] ?? '');
                $new = wp_unslash($_POST['new_password'] ?? '');
                if (!wp_check_password($cur, $u->user_pass, $u->ID)) {
                    wc_add_notice('Your current password is not correct.', 'error');
                } elseif (strlen($new) < 8) {
                    wc_add_notice('Use at least 8 characters for your new password.', 'error');
                } elseif ($new !== wp_unslash($_POST['confirm_password'] ?? '')) {
                    wc_add_notice('The new passwords do not match.', 'error');
                } else {
                    wp_set_password($new, $u->ID);
                    wp_set_auth_cookie($u->ID, true, is_ssl());
                    wc_add_notice('Password changed. Other devices have been signed out.');
                }
                break;
            case 'sessions':
                if (class_exists('WP_Session_Tokens')) {
                    WP_Session_Tokens::get_instance($u->ID)->destroy_others(wp_get_session_token());
                }
                wc_add_notice('Signed out of all other devices.');
                break;
            case 'redeem':
                $r = OMG_Wallet::redeem($u->ID, $p('code'));
                if ($r['ok']) {
                    wc_add_notice(sprintf('%s added to your account balance.', wp_strip_all_tags(self::money_base((float) $r['amount']))));
                    $back = self::url('payments');
                } else {
                    wc_add_notice($r['message'], 'error');
                }
                break;
        }
        wp_safe_redirect($back);
        exit;
    }

    /* ----------------------------------------------------------------- assets */

    public static function assets(): void
    {
        if (!self::on_account()) {
            return;
        }
        wp_enqueue_style('omg-account', OMG_ACCOUNT_URL . 'assets/account.css', [], OMG_ACCOUNT_VERSION);
        wp_enqueue_script('omg-account', OMG_ACCOUNT_URL . 'assets/account.js', [], OMG_ACCOUNT_VERSION, true);
        wp_localize_script('omg-account', 'OMGACC', ['ajax' => admin_url('admin-ajax.php')]);
    }
}
