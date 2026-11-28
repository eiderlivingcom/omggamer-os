<?php
/**
 * Account balance: a ledger of credits and debits per customer, in the store's base currency.
 *
 * Customers cannot add funds. Balance comes from top-ups issued by staff (WooCommerce → Account balance, or the
 * customer's user profile) and from top-up codes staff generate and hand out, which customers redeem in their
 * account. Balance pays for orders at checkout ("Account balance" payment method) when it covers the whole order.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Wallet
{
    private const DB_VERSION = '1';

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'maybe_install'], 5);
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_omg_wallet', [__CLASS__, 'admin_post']);
        add_action('show_user_profile', [__CLASS__, 'profile']);
        add_action('edit_user_profile', [__CLASS__, 'profile']);
        add_action('personal_options_update', [__CLASS__, 'profile_save']);
        add_action('edit_user_profile_update', [__CLASS__, 'profile_save']);
        add_filter('woocommerce_payment_gateways', static function ($g) {
            $g[] = 'OMG_Wallet_Gateway';
            return $g;
        });
        add_action('woocommerce_blocks_payment_method_type_registration', [__CLASS__, 'blocks']);
    }

    /* --------------------------------------------------------------- tables */

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'omg_wallet';
    }

    public static function codes_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'omg_wallet_codes';
    }

    public static function maybe_install(): void
    {
        if (get_option('omg_wallet_db') === self::DB_VERSION) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta('CREATE TABLE ' . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            amount decimal(18,4) NOT NULL,
            currency char(3) NOT NULL,
            kind varchar(20) NOT NULL,
            note varchar(255) NOT NULL DEFAULT '',
            ref varchar(100) NOT NULL DEFAULT '',
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY ref (ref)
        ) $c;");
        dbDelta('CREATE TABLE ' . self::codes_table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(40) NOT NULL,
            amount decimal(18,4) NOT NULL,
            currency char(3) NOT NULL,
            status varchar(12) NOT NULL DEFAULT 'active',
            note varchar(255) NOT NULL DEFAULT '',
            batch varchar(40) NOT NULL DEFAULT '',
            expires_at datetime NULL,
            redeemed_by bigint(20) unsigned NOT NULL DEFAULT 0,
            redeemed_at datetime NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code)
        ) $c;");
        update_option('omg_wallet_db', self::DB_VERSION);
    }

    /* -------------------------------------------------------------- balance */

    public static function base(): string
    {
        return class_exists('OMG_Markets') ? OMG_Markets::base_currency() : get_option('woocommerce_currency', 'USD');
    }

    /** Balance in the base currency. */
    public static function balance(int $user_id): float
    {
        if (!$user_id) {
            return 0.0;
        }
        global $wpdb;
        return round((float) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(amount),0) FROM ' . self::table() . ' WHERE user_id = %d', $user_id)), 4);
    }

    /** Base currency → the shopper's current currency (market rate and markup), 1.0 when it is the base. */
    public static function factor(?string $currency = null): float
    {
        $currency = $currency ?: (function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : self::base());
        if ($currency === self::base() || !class_exists('OMG_Markets') || !OMG_Markets::applies() || OMG_Markets::currency() !== $currency) {
            return 1.0;
        }
        $v = OMG_Markets::convert(1000);
        return $v ? $v / 1000 : 1.0;
    }

    /** Balance shown in the shopper's currency. */
    public static function balance_display(int $user_id): float
    {
        return round(self::balance($user_id) * self::factor(), wc_get_price_decimals());
    }

    /** Add an entry (positive credits, negative debits). Returns the entry id. */
    public static function add(int $user_id, float $amount, string $kind, string $note = '', string $ref = ''): int
    {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'user_id' => $user_id,
            'amount' => round($amount, 4),
            'currency' => self::base(),
            'kind' => substr($kind, 0, 20),
            'note' => mb_substr($note, 0, 255),
            'ref' => substr($ref, 0, 100),
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function entries(int $user_id, array $kinds = [], int $limit = 50, int $offset = 0): array
    {
        global $wpdb;
        $where = $wpdb->prepare('user_id = %d', $user_id);
        if ($kinds) {
            $where .= ' AND kind IN (' . implode(',', array_map(static fn ($k) => $wpdb->prepare('%s', $k), $kinds)) . ')';
        }
        return $wpdb->get_results('SELECT * FROM ' . self::table() . " WHERE $where ORDER BY id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset, ARRAY_A) ?: [];
    }

    public static function kind_label(string $kind): string
    {
        return ['topup' => 'Top-up from OMGGamer', 'redeem' => 'Code redeemed', 'spend' => 'Order payment', 'refund' => 'Refund to balance', 'adjust' => 'Adjustment'][$kind] ?? ucfirst($kind);
    }

    /* ------------------------------------------------------------------ codes */

    public static function normalise_code(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    private static function new_code(): string
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I
        $s = '';
        for ($i = 0; $i < 16; $i++) {
            $s .= $abc[random_int(0, strlen($abc) - 1)];
        }
        return $s;
    }

    public static function pretty(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    /** Create $n codes worth $amount (base currency). Returns the codes. */
    public static function create_codes(int $n, float $amount, ?string $expires, string $note): array
    {
        global $wpdb;
        $batch = 'B' . gmdate('ymdHis');
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            for ($try = 0; $try < 5; $try++) {
                $code = self::new_code();
                $ok = $wpdb->insert(self::codes_table(), [
                    'code' => $code, 'amount' => round($amount, 4), 'currency' => self::base(), 'status' => 'active',
                    'note' => mb_substr($note, 0, 255), 'batch' => $batch, 'expires_at' => $expires ?: null,
                    'created_by' => get_current_user_id(), 'created_at' => current_time('mysql', true),
                ]);
                if ($ok) {
                    $out[] = $code;
                    break;
                }
            }
        }
        return $out;
    }

    /** Redeem a top-up code onto a customer's balance. @return array{ok:bool,message:string,amount?:float} */
    public static function redeem(int $user_id, string $raw): array
    {
        global $wpdb;
        $code = self::normalise_code($raw);
        if (!$user_id || strlen($code) < 8) {
            return ['ok' => false, 'message' => 'Enter the code exactly as it was given to you.'];
        }
        // Slow down guessing: 10 tries per hour per account.
        $tries_key = 'omg_redeem_tries_' . $user_id;
        $tries = (int) get_transient($tries_key);
        if ($tries >= 10) {
            return ['ok' => false, 'message' => 'Too many attempts. Please try again in an hour.'];
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::codes_table() . ' WHERE code = %s', $code), ARRAY_A);
        $fail = static function (string $m) use ($tries_key, $tries) {
            set_transient($tries_key, $tries + 1, HOUR_IN_SECONDS);
            return ['ok' => false, 'message' => $m];
        };
        if (!$row) {
            return $fail('This code is not valid. Check it and try again.');
        }
        if ($row['status'] === 'redeemed') {
            return $fail('This code has already been redeemed.');
        }
        if ($row['status'] !== 'active') {
            return $fail('This code is no longer valid.');
        }
        if ($row['expires_at'] && strtotime($row['expires_at'] . ' UTC') < time()) {
            return $fail('This code has expired.');
        }
        // Claim it atomically so a code can never be redeemed twice.
        $claimed = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::codes_table() . " SET status = 'redeemed', redeemed_by = %d, redeemed_at = %s WHERE id = %d AND status = 'active'",
            $user_id, current_time('mysql', true), (int) $row['id']
        ));
        if (!$claimed) {
            return $fail('This code has already been redeemed.');
        }
        self::add($user_id, (float) $row['amount'], 'redeem', 'Code ' . self::pretty($code), 'code:' . $row['id']);
        delete_transient($tries_key);
        return ['ok' => true, 'message' => 'Code redeemed: added to your account balance.', 'amount' => (float) $row['amount']];
    }

    /* ------------------------------------------------------------------ admin */

    public static function menu(): void
    {
        add_submenu_page('woocommerce', 'Account balance', 'Account balance', 'manage_woocommerce', 'omg-wallet', [__CLASS__, 'page']);
    }

    private static function find_user(string $who): ?WP_User
    {
        $who = trim($who);
        if ($who === '') {
            return null;
        }
        $u = is_numeric($who) ? get_user_by('id', (int) $who) : (is_email($who) ? get_user_by('email', $who) : get_user_by('login', $who));
        return $u ?: null;
    }

    public static function admin_post(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('omg_wallet');
        $do = sanitize_key($_POST['do'] ?? '');
        $msg = '';
        if ($do === 'issue') {
            $u = self::find_user(sanitize_text_field(wp_unslash($_POST['user'] ?? '')));
            $amount = round((float) ($_POST['amount'] ?? 0), 2);
            $note = sanitize_text_field(wp_unslash($_POST['note'] ?? ''));
            $type = ($_POST['type'] ?? '') === 'adjust' ? 'adjust' : 'topup';
            if (!$u) {
                $msg = 'err:Customer not found.';
            } elseif ($amount == 0.0 || ($type === 'topup' && $amount < 0)) {
                $msg = 'err:Enter an amount' . ($type === 'topup' ? ' above 0.' : '.');
            } elseif ($amount < 0 && self::balance($u->ID) + $amount < 0) {
                $msg = 'err:That would make the balance negative.';
            } else {
                self::add($u->ID, $amount, $type, $note);
                $msg = 'ok:' . wp_strip_all_tags(wc_price($amount, ['currency' => self::base()])) . ' ' . ($amount > 0 ? 'added to' : 'taken from') . ' ' . $u->user_email . '. New balance ' . wp_strip_all_tags(wc_price(self::balance($u->ID), ['currency' => self::base()])) . '.';
            }
        } elseif ($do === 'codes') {
            $n = max(1, min(500, (int) ($_POST['count'] ?? 1)));
            $amount = round((float) ($_POST['amount'] ?? 0), 2);
            $exp = sanitize_text_field(wp_unslash($_POST['expires'] ?? ''));
            $exp = $exp && strtotime($exp) ? gmdate('Y-m-d 23:59:59', strtotime($exp)) : null;
            if ($amount <= 0) {
                $msg = 'err:Enter an amount above 0.';
            } else {
                $codes = self::create_codes($n, $amount, $exp, sanitize_text_field(wp_unslash($_POST['note'] ?? '')));
                set_transient('omg_wallet_new_codes_' . get_current_user_id(), $codes, 10 * MINUTE_IN_SECONDS);
                $msg = 'ok:' . count($codes) . ' code(s) created.';
            }
        } elseif ($do === 'disable') {
            global $wpdb;
            $wpdb->update(self::codes_table(), ['status' => 'disabled'], ['id' => (int) ($_POST['id'] ?? 0), 'status' => 'active']);
            $msg = 'ok:Code disabled.';
        }
        wp_safe_redirect(add_query_arg(['page' => 'omg-wallet', 'msg' => rawurlencode($msg), 'tab' => $do === 'issue' ? 'issue' : 'codes'], admin_url('admin.php')));
        exit;
    }

    public static function page(): void
    {
        global $wpdb;
        $tab = sanitize_key($_GET['tab'] ?? 'issue');
        $msg = sanitize_text_field(wp_unslash($_GET['msg'] ?? ''));
        $base = self::base();
        echo '<div class="wrap"><h1>Account balance</h1><p>Customers cannot add funds themselves. Issue a top-up to a customer, or create top-up codes to hand out: customers redeem them under <strong>Account → Redeem code</strong>. Balance pays for whole orders at checkout. Amounts are in ' . esc_html($base) . '.</p>';
        if ($msg) {
            [$t, $m] = array_pad(explode(':', $msg, 2), 2, '');
            echo '<div class="notice notice-' . ($t === 'ok' ? 'success' : 'error') . '"><p>' . esc_html($m) . '</p></div>';
        }
        echo '<h2 class="nav-tab-wrapper">';
        foreach (['issue' => 'Issue top-up', 'codes' => 'Top-up codes', 'ledger' => 'Recent activity'] as $k => $l) {
            echo '<a class="nav-tab' . ($tab === $k ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg(['page' => 'omg-wallet', 'tab' => $k], admin_url('admin.php'))) . '">' . esc_html($l) . '</a>';
        }
        echo '</h2>';
        $form = static function (string $do, string $inner, string $button): void {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('omg_wallet', '_wpnonce', true, false)
                . '<input type="hidden" name="action" value="omg_wallet"><input type="hidden" name="do" value="' . esc_attr($do) . '">' . $inner;
            submit_button($button);
            echo '</form>';
        };
        if ($tab === 'issue') {
            $form('issue', '<table class="form-table"><tr><th>Customer</th><td><input name="user" class="regular-text" required placeholder="Email, username or user ID"></td></tr>'
                . '<tr><th>Amount (' . esc_html($base) . ')</th><td><input name="amount" type="number" step="0.01" required> <select name="type"><option value="topup">Top-up (adds)</option><option value="adjust">Adjustment (+ or −)</option></select></td></tr>'
                . '<tr><th>Note</th><td><input name="note" class="regular-text" placeholder="Shown to the customer, e.g. Goodwill credit for order #1234"></td></tr></table>', 'Issue');
        } elseif ($tab === 'codes') {
            $new = get_transient('omg_wallet_new_codes_' . get_current_user_id());
            if ($new) {
                delete_transient('omg_wallet_new_codes_' . get_current_user_id());
                echo '<div class="notice notice-info"><p><strong>New codes</strong> (shown once here; they also stay in the list below):</p><textarea readonly rows="' . min(12, count($new) + 1) . '" style="width:100%;font-family:monospace">' . esc_textarea(implode("\n", array_map([__CLASS__, 'pretty'], $new))) . '</textarea></div>';
            }
            $form('codes', '<table class="form-table"><tr><th>How many</th><td><input name="count" type="number" min="1" max="500" value="1"></td></tr>'
                . '<tr><th>Value each (' . esc_html($base) . ')</th><td><input name="amount" type="number" step="0.01" min="0.01" required></td></tr>'
                . '<tr><th>Expires</th><td><input name="expires" type="date"> <span class="description">Optional</span></td></tr>'
                . '<tr><th>Note</th><td><input name="note" class="regular-text" placeholder="Internal, e.g. Giveaway November"></td></tr></table>', 'Create codes');
            $rows = $wpdb->get_results('SELECT * FROM ' . self::codes_table() . ' ORDER BY id DESC LIMIT 100', ARRAY_A) ?: [];
            echo '<h2>Latest codes</h2><table class="widefat striped"><thead><tr><th>Code</th><th>Value</th><th>Status</th><th>Redeemed by</th><th>Expires</th><th>Note</th><th></th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $u = $r['redeemed_by'] ? get_user_by('id', (int) $r['redeemed_by']) : null;
                echo '<tr><td><code>' . esc_html(self::pretty($r['code'])) . '</code></td><td>' . wp_kses_post(wc_price((float) $r['amount'], ['currency' => $r['currency']])) . '</td><td>' . esc_html($r['status']) . '</td><td>'
                    . ($u ? esc_html($u->user_email) . '<br><small>' . esc_html($r['redeemed_at']) . ' UTC</small>' : '–') . '</td><td>' . esc_html($r['expires_at'] ? substr($r['expires_at'], 0, 10) : '–') . '</td><td>' . esc_html($r['note']) . '</td><td>';
                if ($r['status'] === 'active') {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('omg_wallet', '_wpnonce', true, false) . '<input type="hidden" name="action" value="omg_wallet"><input type="hidden" name="do" value="disable"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><button class="button-link-delete" type="submit">Disable</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            $rows = $wpdb->get_results('SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT 100', ARRAY_A) ?: [];
            echo '<table class="widefat striped"><thead><tr><th>Date (UTC)</th><th>Customer</th><th>Type</th><th>Amount</th><th>Note</th><th>By</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $u = get_user_by('id', (int) $r['user_id']);
                $by = $r['created_by'] ? get_user_by('id', (int) $r['created_by']) : null;
                echo '<tr><td>' . esc_html($r['created_at']) . '</td><td>' . ($u ? '<a href="' . esc_url(get_edit_user_link($u->ID)) . '">' . esc_html($u->user_email) . '</a>' : '#' . (int) $r['user_id']) . '</td><td>' . esc_html(self::kind_label($r['kind'])) . '</td><td>' . wp_kses_post(wc_price((float) $r['amount'], ['currency' => $r['currency']])) . '</td><td>' . esc_html($r['note']) . '</td><td>' . esc_html($by ? $by->user_login : 'system') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    public static function profile(WP_User $user): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $base = self::base();
        echo '<h2>OMGGamer account balance</h2><table class="form-table"><tr><th>Balance</th><td><strong>' . wp_kses_post(wc_price(self::balance($user->ID), ['currency' => $base])) . '</strong></td></tr>'
            . '<tr><th><label for="omg-wallet-add">Issue top-up (' . esc_html($base) . ')</label></th><td><input id="omg-wallet-add" name="omg_wallet_add" type="number" step="0.01" min="0.01"> '
            . '<input name="omg_wallet_note" class="regular-text" placeholder="Note shown to the customer"> ' . wp_nonce_field('omg_wallet_profile', 'omg_wallet_nonce', true, false)
            . '<p class="description">Adds to the balance when you save the profile. Full history: WooCommerce → Account balance.</p></td></tr></table>';
    }

    public static function profile_save(int $user_id): void
    {
        if (!current_user_can('manage_woocommerce') || empty($_POST['omg_wallet_add']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['omg_wallet_nonce'] ?? '')), 'omg_wallet_profile')) {
            return;
        }
        $amount = round((float) $_POST['omg_wallet_add'], 2);
        if ($amount > 0) {
            self::add($user_id, $amount, 'topup', sanitize_text_field(wp_unslash($_POST['omg_wallet_note'] ?? '')));
        }
    }

    /* -------------------------------------------------------------- checkout */

    public static function blocks($registry): void
    {
        if (class_exists('OMG_Wallet_Blocks')) {
            $registry->register(new OMG_Wallet_Blocks());
        }
    }
}

add_action('plugins_loaded', static function () {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    final class OMG_Wallet_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'omg_balance';
            $this->method_title = 'Account balance';
            $this->method_description = 'Customers pay with their OMGGamer account balance (top-ups and redeemed codes) when it covers the whole order.';
            $this->has_fields = false;
            $this->supports = ['products', 'refunds'];
            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->get_option('title', 'Account balance');
            $this->description = $this->get_option('description', '');
            $this->enabled = $this->get_option('enabled', 'yes');
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }

        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => ['title' => 'Enable', 'type' => 'checkbox', 'label' => 'Let customers pay with their account balance', 'default' => 'yes'],
                'title' => ['title' => 'Title', 'type' => 'text', 'default' => 'Account balance'],
            ];
        }

        private function cart_total(): float
        {
            return function_exists('WC') && WC()->cart ? (float) WC()->cart->get_total('edit') : 0.0;
        }

        public function is_available()
        {
            if ($this->enabled !== 'yes' || !is_user_logged_in()) {
                return false;
            }
            if (class_exists('WC_Subscriptions_Cart') && WC_Subscriptions_Cart::cart_contains_subscription()) {
                return false; // renewals need a card
            }
            $total = $this->cart_total();
            if (is_admin() && !wp_doing_ajax()) {
                return true;
            }
            return $total > 0 && OMG_Wallet::balance_display(get_current_user_id()) + 0.00001 >= $total;
        }

        public function get_description()
        {
            $uid = get_current_user_id();
            return $uid ? sprintf('Available: %s. The order total is taken from your balance.', wp_strip_all_tags(wc_price(OMG_Wallet::balance_display($uid)))) : '';
        }

        public function process_payment($order_id)
        {
            global $wpdb;
            $order = wc_get_order($order_id);
            $uid = (int) $order->get_customer_id();
            if (!$uid || $uid !== get_current_user_id()) {
                wc_add_notice('Log in to pay with your account balance.', 'error');
                return ['result' => 'failure'];
            }
            $lock = 'omg_wallet_' . $uid;
            $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock));
            try {
                $factor = OMG_Wallet::factor($order->get_currency());
                $base = round((float) $order->get_total() / $factor, 4);
                if (OMG_Wallet::balance($uid) + 0.00001 < $base) {
                    wc_add_notice('Your account balance does not cover this order.', 'error');
                    return ['result' => 'failure'];
                }
                $entry = OMG_Wallet::add($uid, -$base, 'spend', 'Order #' . $order->get_order_number(), 'order:' . $order->get_id());
            } finally {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
            $order->update_meta_data('_omg_balance_base', $base);
            $order->update_meta_data('_omg_balance_entry', $entry);
            $order->save();
            $order->payment_complete('balance-' . $entry);
            if (WC()->cart) {
                WC()->cart->empty_cart();
            }
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        public function process_refund($order_id, $amount = null, $reason = '')
        {
            $order = wc_get_order($order_id);
            $paid = (float) $order->get_meta('_omg_balance_base');
            $total = (float) $order->get_total();
            if (!$order || $paid <= 0 || $total <= 0) {
                return new WP_Error('omg_balance', 'This order was not paid with account balance.');
            }
            $amount = $amount === null ? $total : (float) $amount;
            $back = round($paid * min(1, $amount / $total), 4);
            OMG_Wallet::add((int) $order->get_customer_id(), $back, 'refund', 'Refund for order #' . $order->get_order_number() . ($reason ? ': ' . $reason : ''), 'order:' . $order->get_id());
            $order->add_order_note(sprintf('%s returned to the customer\'s account balance.', wp_strip_all_tags(wc_price($back, ['currency' => OMG_Wallet::base()]))));
            return true;
        }
    }

    if (class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) {
        final class OMG_Wallet_Blocks extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType
        {
            protected $name = 'omg_balance';

            public function initialize()
            {
                $this->settings = get_option('woocommerce_omg_balance_settings', []);
            }

            public function is_active()
            {
                return ($this->settings['enabled'] ?? 'yes') === 'yes';
            }

            public function get_payment_method_script_handles()
            {
                wp_register_script('omg-balance-blocks', OMG_ACCOUNT_URL . 'assets/balance-blocks.js', ['wc-blocks-registry', 'wp-element', 'wp-html-entities'], OMG_ACCOUNT_VERSION, true);
                return ['omg-balance-blocks'];
            }

            public function get_payment_method_data()
            {
                $uid = get_current_user_id();
                return [
                    'title' => $this->settings['title'] ?? 'Account balance',
                    'description' => $uid ? 'Available: ' . wp_strip_all_tags(wc_price(OMG_Wallet::balance_display($uid))) . '. The order total is taken from your balance.' : '',
                    'supports' => ['products'],
                ];
            }
        }
    }
}, 20);
