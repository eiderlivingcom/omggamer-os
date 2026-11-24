<?php
/**
 * Plugin Name: OMGGamer Payment Tests
 * Description: Tests every enabled payment method on the store, one at a time: places a test order, checks the
 *              method end to end with Stripe (a full payment in Stripe test mode with test cards, plus live-account
 *              readiness checks that never charge anyone), emails the result after each test, then deletes the
 *              test order and cancels anything it created in Stripe. Runs on a schedule and from
 *              WooCommerce → Payment tests.
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 * Author: OMGGamer
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Payment_Tests
{
    private const OPT = 'omg_payment_tests';
    private const LOG = 'omg_payment_tests_log';
    private const HOOK = 'omg_payment_tests_run';
    private const STRIPE = 'https://api.stripe.com/v1/';

    /** WooCommerce gateway ID → Stripe payment method type. */
    private const TYPES = [
        'stripe' => 'card',
        'stripe_link' => 'link',
        'stripe_klarna' => 'klarna',
        'stripe_affirm' => 'affirm',
        'stripe_amazon_pay' => 'amazon_pay',
        'stripe_afterpay_clearpay' => 'afterpay_clearpay',
        'stripe_alipay' => 'alipay',
        'stripe_wechat_pay' => 'wechat_pay',
        'stripe_cashapp' => 'cashapp',
        'stripe_ideal' => 'ideal',
        'stripe_bancontact' => 'bancontact',
        'stripe_eps' => 'eps',
        'stripe_p24' => 'p24',
        'stripe_blik' => 'blik',
        'stripe_sepa_debit' => 'sepa_debit',
        'stripe_us_bank_account' => 'us_bank_account',
    ];

    /** Stripe account capability per payment method type. */
    private const CAPABILITY = [
        'card' => 'card_payments', 'link' => 'link_payments', 'klarna' => 'klarna_payments', 'affirm' => 'affirm_payments',
        'amazon_pay' => 'amazon_pay_payments', 'afterpay_clearpay' => 'afterpay_clearpay_payments', 'alipay' => 'alipay_payments',
        'wechat_pay' => 'wechat_pay_payments', 'cashapp' => 'cashapp_payments', 'ideal' => 'ideal_payments',
        'bancontact' => 'bancontact_payments', 'eps' => 'eps_payments', 'p24' => 'p24_payments', 'blik' => 'blik_payments',
        'sepa_debit' => 'sepa_debit_payments', 'us_bank_account' => 'us_bank_account_ach_payments',
    ];

    /** Test amount per method (some methods have minimums, e.g. Affirm from 50). */
    private const AMOUNTS = ['affirm' => 60, 'klarna' => 20, 'afterpay_clearpay' => 20];

    /** Methods that send the shopper to the provider's page: tested up to that page. */
    private const REDIRECT = ['klarna', 'affirm', 'amazon_pay', 'afterpay_clearpay', 'alipay', 'wechat_pay', 'cashapp', 'ideal', 'bancontact', 'eps', 'p24'];

    public static function init(): void
    {
        add_action(self::HOOK, [__CLASS__, 'run_all']);
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_omg_payment_tests', [__CLASS__, 'admin_post']);
        add_action('init', [__CLASS__, 'schedule']);
        // Test orders are never real orders: no stock, no emails, no fulfilment hand-off.
        add_filter('woocommerce_email_enabled_new_order', [__CLASS__, 'no_email'], 99, 2);
        add_filter('woocommerce_email_enabled_customer_on_hold_order', [__CLASS__, 'no_email'], 99, 2);
        add_filter('woocommerce_email_enabled_customer_processing_order', [__CLASS__, 'no_email'], 99, 2);
        add_filter('woocommerce_email_enabled_failed_order', [__CLASS__, 'no_email'], 99, 2);
        add_filter('woocommerce_email_enabled_cancelled_order', [__CLASS__, 'no_email'], 99, 2);
    }

    /* ------------------------------------------------------------ settings */

    public static function settings(): array
    {
        $s = get_option(self::OPT, []);
        return [
            'email' => is_array($s) && !empty($s['email']) ? (string) $s['email'] : (string) get_option('admin_email'),
            'schedule' => is_array($s) && in_array($s['schedule'] ?? '', ['off', 'daily', 'weekly'], true) ? $s['schedule'] : 'daily',
        ];
    }

    public static function schedule(): void
    {
        $want = self::settings()['schedule'];
        $next = wp_next_scheduled(self::HOOK);
        $current = $next ? wp_get_schedule(self::HOOK) : '';
        if ($want === 'off') {
            if ($next) {
                wp_clear_scheduled_hook(self::HOOK);
            }
            return;
        }
        if ($current !== $want) {
            wp_clear_scheduled_hook(self::HOOK);
            // 04:20 site time, away from the busy hours.
            $start = strtotime('tomorrow 04:20', current_time('timestamp')) - (int) (get_option('gmt_offset') * HOUR_IN_SECONDS);
            wp_schedule_event($start, $want, self::HOOK);
        }
    }

    public static function no_email($enabled, $order)
    {
        return $order instanceof WC_Order && $order->get_meta('_omg_payment_test') ? false : $enabled;
    }

    /* ---------------------------------------------------------------- Stripe */

    private static function stripe_keys(): array
    {
        $s = get_option('woocommerce_stripe_settings', []);
        $s = is_array($s) ? $s : [];
        return [
            'live' => trim((string) ($s['secret_key'] ?? '')),
            'test' => trim((string) ($s['test_secret_key'] ?? '')),
            'testmode' => ($s['testmode'] ?? 'no') === 'yes',
        ];
    }

    /** @return array{0:int,1:array} HTTP status and decoded body. */
    private static function stripe(string $key, string $method, string $path, array $body = [], string $idem = ''): array
    {
        $headers = ['Authorization' => 'Bearer ' . $key];
        if ($idem !== '') {
            $headers['Idempotency-Key'] = $idem;
        }
        $args = ['method' => $method, 'timeout' => 30, 'headers' => $headers];
        $url = self::STRIPE . ltrim($path, '/');
        if ($method === 'GET') {
            $url = $body ? add_query_arg($body, $url) : $url;
        } else {
            $args['body'] = $body;
        }
        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            return [0, ['error' => ['message' => $res->get_error_message()]]];
        }
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        return [(int) wp_remote_retrieve_response_code($res), is_array($data) ? $data : []];
    }

    private static function err(array $body): string
    {
        $e = $body['error'] ?? [];
        if (!$e && isset($body['status'])) {
            return 'Stripe returned payment status "' . $body['status'] . '"' . (!empty($body['next_action']['type']) ? ' with next step "' . $body['next_action']['type'] . '"' : '') . '.';
        }
        return trim((string) ($e['message'] ?? 'Unknown error') . (!empty($e['code']) ? ' (' . $e['code'] . ')' : ''));
    }

    /* --------------------------------------------------------------- the run */

    /** Enabled payment methods: [gateway_id => title]. */
    public static function methods(): array
    {
        $out = [];
        foreach (WC()->payment_gateways()->payment_gateways() as $id => $g) {
            if ($g->enabled === 'yes') {
                $out[$id] = wp_strip_all_tags((string) $g->get_title()) ?: $id;
            }
        }
        return $out;
    }

    public static function run_all(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $log = [];
        foreach (self::methods() as $id => $title) {
            $r = self::run_one($id, $title);
            $log[] = $r;
            self::email($r);
        }
        update_option(self::LOG, ['at' => time(), 'results' => $log], false);
    }

    /** One payment method: test order → checks → email (by caller) → order deleted. */
    public static function run_one(string $gateway_id, string $title): array
    {
        $r = ['gateway' => $gateway_id, 'title' => $title, 'steps' => [], 'ok' => true, 'at' => time()];
        $add = static function (array &$r, bool $ok, string $label, string $detail = '', bool $warn = false): void {
            $r['steps'][] = ['ok' => $ok, 'warn' => $warn, 'label' => $label, 'detail' => $detail];
            if (!$ok && !$warn) {
                $r['ok'] = false;
            }
        };
        $type = self::TYPES[$gateway_id] ?? null;
        $currency = get_woocommerce_currency();
        $amount = (float) (self::AMOUNTS[$type ?? ''] ?? 10);
        $created = []; // [key, payment_intent_id] to cancel

        // 1. Test order (one "Payment test" line, never paid: no stock, no OS hand-off, no customer email).
        $order = null;
        try {
            $order = wc_create_order(['status' => 'pending', 'created_via' => 'omg-payment-test']);
            $fee = new WC_Order_Item_Fee();
            $fee->set_name('Payment test – not a real order');
            $fee->set_amount($amount);
            $fee->set_total($amount);
            $fee->set_tax_status('none');
            $order->add_item($fee);
            $order->set_currency($currency);
            $order->set_billing_email(self::settings()['email']);
            $order->set_billing_first_name('Payment');
            $order->set_billing_last_name('Test');
            $order->set_billing_country('US');
            $order->set_payment_method($gateway_id);
            $order->set_payment_method_title($title);
            $order->update_meta_data('_omg_payment_test', 1);
            $order->calculate_totals(false);
            $order->save();
            $add($r, true, 'Test order created', sprintf('#%d, %s %s, payment method %s', $order->get_id(), wc_format_decimal($order->get_total(), 2), $currency, $title));
        } catch (Throwable $e) {
            $add($r, false, 'Test order created', $e->getMessage());
        }
        $r['order_id'] = $order ? $order->get_id() : 0;

        // 2. The method is offered at checkout.
        $gw = WC()->payment_gateways()->payment_gateways()[$gateway_id] ?? null;
        $add($r, $gw && $gw->enabled === 'yes', 'Enabled in WooCommerce', $gw ? get_class($gw) : 'Gateway not found');

        if ($type === null) {
            // Offline methods (bank transfer, cheque, cash): nothing to pay online; check they have instructions.
            $instr = $gw ? trim((string) ($gw->get_option('instructions') ?: $gw->get_option('description'))) : '';
            $add($r, $instr !== '', 'Payment instructions set', $instr !== '' ? 'Shown to the buyer at checkout and in the email.' : 'No instructions: buyers will not know how to pay.', $instr === '');
        } else {
            $keys = self::stripe_keys();
            $amount_minor = (int) round($amount * (10 ** wc_get_price_decimals()));
            $cur = strtolower($currency);
            $desc = 'OMGGamer payment test (not a real order)';
            $meta = ['metadata[omg_payment_test]' => '1', 'metadata[omg_test_order]' => (string) $r['order_id']];

            // 3. Full payment in Stripe TEST mode (test cards / test redirects; no real money).
            if ($keys['test'] === '') {
                $add($r, false, 'Test-mode payment', 'No Stripe test secret key in WooCommerce → Settings → Payments → Stripe. Add one to run full payments with test cards.', true);
            } elseif ($type === 'card') {
                [$c, $b] = self::stripe($keys['test'], 'POST', 'payment_intents', $meta + [
                    'amount' => $amount_minor, 'currency' => $cur, 'payment_method_types[]' => 'card', 'payment_method' => 'pm_card_visa',
                    'capture_method' => 'manual', 'confirm' => 'true', 'description' => $desc,
                ], 'omgpt-' . $r['order_id'] . '-card');
                $ok = $c === 200 && ($b['status'] ?? '') === 'requires_capture';
                $add($r, $ok, 'Test-mode card payment (Visa) authorised', $ok ? 'Authorised, then cancelled: nothing captured.' : self::err($b));
                if (!empty($b['id'])) {
                    $created[] = [$keys['test'], $b['id']];
                }
                [$c, $b] = self::stripe($keys['test'], 'POST', 'payment_intents', $meta + [
                    'amount' => $amount_minor, 'currency' => $cur, 'payment_method_types[]' => 'card', 'payment_method' => 'pm_card_chargeDeclined',
                    'confirm' => 'true', 'description' => $desc,
                ]);
                $declined = ($b['error']['code'] ?? '') === 'card_declined';
                $add($r, $declined, 'Declined card is refused', $declined ? 'Stripe refused the test card as expected.' : 'Unexpected: ' . ($c === 200 ? 'payment status ' . ($b['status'] ?? '?') : self::err($b)));
                [$c, $b] = self::stripe($keys['test'], 'POST', 'payment_intents', $meta + [
                    'amount' => $amount_minor, 'currency' => $cur, 'payment_method_types[]' => 'card', 'payment_method' => 'pm_card_authenticationRequired',
                    'confirm' => 'true', 'return_url' => home_url('/checkout/'), 'description' => $desc,
                ]);
                $sca = $c === 200 && ($b['status'] ?? '') === 'requires_action';
                $add($r, $sca, '3-D Secure card asks for authentication', $sca ? 'The bank check (3-D Secure) step is triggered.' : self::err($b));
                if (!empty($b['id'])) {
                    $created[] = [$keys['test'], $b['id']];
                }
            } elseif (in_array($type, self::REDIRECT, true)) {
                $pmd = ['payment_method_data[type]' => $type, 'payment_method_data[billing_details][email]' => self::settings()['email'],
                    'payment_method_data[billing_details][name]' => 'Payment Test', 'payment_method_data[billing_details][address][country]' => 'US',
                    'payment_method_data[billing_details][address][line1]' => '1 Test Street', 'payment_method_data[billing_details][address][city]' => 'New York',
                    'payment_method_data[billing_details][address][state]' => 'NY', 'payment_method_data[billing_details][address][postal_code]' => '10001'];
                $ship = in_array($type, ['affirm', 'afterpay_clearpay'], true) ? [
                    'shipping[name]' => 'Payment Test', 'shipping[address][line1]' => '1 Test Street', 'shipping[address][city]' => 'New York',
                    'shipping[address][state]' => 'NY', 'shipping[address][postal_code]' => '10001', 'shipping[address][country]' => 'US',
                ] : [];
                [$c, $b] = self::stripe($keys['test'], 'POST', 'payment_intents', $meta + $pmd + $ship + [
                    'amount' => $amount_minor, 'currency' => $cur, 'payment_method_types[]' => $type, 'confirm' => 'true',
                    'return_url' => home_url('/checkout/'), 'description' => $desc,
                ]);
                $redirect = (string) ($b['next_action']['redirect_to_url']['url'] ?? '');
                $ok = $c === 200 && ($b['status'] ?? '') === 'requires_action' && $redirect !== '';
                $add($r, $ok, 'Test-mode payment reaches the ' . $title . ' payment page', $ok ? 'Stripe created the payment and returned the provider page the buyer is sent to.' : self::err($b));
                if (!empty($b['id'])) {
                    $created[] = [$keys['test'], $b['id']];
                }
            } else {
                [$c, $b] = self::stripe($keys['test'], 'POST', 'payment_intents', $meta + [
                    'amount' => $amount_minor, 'currency' => $cur, 'payment_method_types[]' => $type, 'description' => $desc,
                ]);
                $ok = $c === 200 && !empty($b['id']);
                $add($r, $ok, 'Test-mode payment created for ' . $title, $ok ? 'Stripe accepts this method in test mode (completing it needs the buyer\'s browser).' : self::err($b));
                if (!empty($b['id'])) {
                    $created[] = [$keys['test'], $b['id']];
                }
            }

            // 4. LIVE readiness: never confirms a payment, so nobody is charged.
            if ($keys['live'] === '') {
                $add($r, false, 'Live Stripe key', 'No live secret key in the Stripe settings: the store cannot take payments.');
            } else {
                static $account = null;
                if ($account === null) {
                    $account = self::stripe($keys['live'], 'GET', 'account');
                }
                [$c, $acct] = $account;
                if ($c !== 200) {
                    $add($r, false, 'Live Stripe account reachable', self::err($acct));
                } else {
                    $add($r, !empty($acct['charges_enabled']), 'Live account can take payments', !empty($acct['charges_enabled']) ? 'Charges enabled.' : 'Stripe has disabled charges on this account: check the Stripe dashboard.');
                    $add($r, !empty($acct['payouts_enabled']), 'Payouts to the bank are on', !empty($acct['payouts_enabled']) ? 'Payouts enabled.' : 'Payouts are paused: money collects in Stripe until this is fixed.', empty($acct['payouts_enabled']));
                    $cap = self::CAPABILITY[$type] ?? '';
                    if ($cap !== '' && isset($acct['capabilities'])) {
                        $state = (string) ($acct['capabilities'][$cap] ?? 'not requested');
                        $add($r, $state === 'active', $title . ' switched on in Stripe', 'Stripe capability "' . $cap . '": ' . $state . '.');
                    }
                }
                [$c, $b] = self::stripe($keys['live'], 'POST', 'payment_intents', $meta + [
                    'amount' => $amount_minor, 'currency' => $cur, 'payment_method_types[]' => $type, 'description' => $desc,
                ]);
                $ok = $c === 200 && !empty($b['id']);
                $add($r, $ok, 'Live payment accepted for ' . $title . ' in ' . $currency, $ok ? 'Created (not confirmed) and cancelled straight away: no charge.' : self::err($b));
                if (!empty($b['id'])) {
                    $created[] = [$keys['live'], $b['id']];
                }

                // Webhook: Stripe tells the store about payments; without it, orders stay "pending payment".
                static $hooks = null;
                if ($hooks === null) {
                    $hooks = self::stripe($keys['live'], 'GET', 'webhook_endpoints', ['limit' => 100]);
                }
                [$c, $b] = $hooks;
                $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
                $found = null;
                foreach ((array) ($b['data'] ?? []) as $w) {
                    if (str_contains((string) ($w['url'] ?? ''), $host)) {
                        $found = $w;
                        break;
                    }
                }
                $ok = $found && ($found['status'] ?? '') === 'enabled';
                $add($r, (bool) $ok, 'Stripe webhook to the store is on', $found ? (string) $found['url'] . ' (' . ($found['status'] ?? '?') . ')' : ($c === 200 ? 'No Stripe webhook points at ' . $host . ': paid orders may stay "pending payment".' : self::err($b)));

                if ($type === 'card') {
                    // Apple Pay / Google Pay buttons need the domain registered with Stripe.
                    [$c, $b] = self::stripe($keys['live'], 'GET', 'payment_method_domains', ['domain_name' => $host]);
                    $d = $b['data'][0] ?? null;
                    $apple = (string) ($d['apple_pay']['status'] ?? 'not registered');
                    $google = (string) ($d['google_pay']['status'] ?? 'not registered');
                    $add($r, $apple === 'active', 'Apple Pay domain registered', $host . ': ' . $apple, $apple !== 'active');
                    $add($r, $google === 'active', 'Google Pay domain registered', $host . ': ' . $google, $google !== 'active');
                }
            }
        }

        // 5. Clean up: cancel every payment created, then delete the test order.
        foreach ($created as [$key, $pi]) {
            [$c, $b] = self::stripe($key, 'POST', 'payment_intents/' . rawurlencode($pi) . '/cancel', ['cancellation_reason' => 'abandoned']);
            if ($c !== 200 && !in_array($b['status'] ?? '', ['canceled', 'succeeded'], true) && !str_contains(self::err($b), 'status of canceled')) {
                $add($r, false, 'Stripe test payment cancelled', $pi . ': ' . self::err($b), true);
            }
        }
        if ($created) {
            $add($r, true, 'Stripe payments cancelled', count($created) . ' cancelled; nothing was charged.');
        }
        if ($order) {
            $id = $order->get_id();
            $order->delete(true);
            $gone = !wc_get_order($id);
            $add($r, $gone, 'Test order deleted', $gone ? '#' . $id . ' permanently deleted.' : '#' . $id . ' could not be deleted: remove it in WooCommerce → Orders.');
        }
        return $r;
    }

    /* ----------------------------------------------------------------- email */

    public static function email(array $r): void
    {
        $fails = array_filter($r['steps'], static fn ($s) => !$s['ok'] && !$s['warn']);
        $warns = array_filter($r['steps'], static fn ($s) => !$s['ok'] && $s['warn']);
        $verdict = $fails ? 'FAILED' : ($warns ? 'PASSED with warnings' : 'PASSED');
        $subject = sprintf('[%s] Payment test: %s – %s', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $r['title'], $verdict);
        $rows = '';
        foreach ($r['steps'] as $s) {
            $icon = $s['ok'] ? '&#9989;' : ($s['warn'] ? '&#9888;&#65039;' : '&#10060;');
            $rows .= '<tr><td style="padding:6px 8px;vertical-align:top">' . $icon . '</td><td style="padding:6px 8px"><strong>' . esc_html($s['label'])
                . '</strong>' . ($s['detail'] !== '' ? '<br><span style="color:#555">' . esc_html($s['detail']) . '</span>' : '') . '</td></tr>';
        }
        $color = $fails ? '#b42318' : ($warns ? '#b54708' : '#067647');
        $body = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#111;max-width:640px">'
            . '<h2 style="margin:0 0 4px;color:' . $color . '">' . esc_html($r['title']) . ': ' . esc_html($verdict) . '</h2>'
            . '<p style="margin:0 0 12px;color:#555">' . esc_html(wp_date('j M Y, H:i', $r['at'])) . ' · ' . esc_html(home_url()) . ' · gateway <code>' . esc_html($r['gateway']) . '</code></p>'
            . '<table style="border-collapse:collapse;width:100%">' . $rows . '</table>'
            . '<p style="margin-top:16px;color:#555;font-size:12px">Automatic payment test by the OMGGamer Payment Tests plugin. Test-mode payments use Stripe test cards; live checks create a payment and cancel it at once, so nobody is charged. The test order was deleted after the test.</p></div>';
        wp_mail(self::settings()['email'], $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
    }

    /* ----------------------------------------------------------------- admin */

    public static function menu(): void
    {
        add_submenu_page('woocommerce', 'Payment tests', 'Payment tests', 'manage_woocommerce', 'omg-payment-tests', [__CLASS__, 'page']);
    }

    public static function admin_post(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('omg_payment_tests');
        $do = sanitize_key($_POST['do'] ?? '');
        if ($do === 'save') {
            $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
            $sched = sanitize_key($_POST['schedule'] ?? 'daily');
            update_option(self::OPT, ['email' => is_email($email) ? $email : '', 'schedule' => in_array($sched, ['off', 'daily', 'weekly'], true) ? $sched : 'daily']);
            wp_clear_scheduled_hook(self::HOOK);
            self::schedule();
        } elseif ($do === 'run') {
            self::run_all();
        } elseif ($do === 'run_one') {
            $id = sanitize_key($_POST['gateway'] ?? '');
            $methods = self::methods();
            if (isset($methods[$id])) {
                $r = self::run_one($id, $methods[$id]);
                self::email($r);
                update_option(self::LOG, ['at' => time(), 'results' => [$r]], false);
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=omg-payment-tests&done=' . $do));
        exit;
    }

    public static function page(): void
    {
        $s = self::settings();
        $log = get_option(self::LOG, []);
        $next = wp_next_scheduled(self::HOOK);
        $keys = self::stripe_keys();
        $nonce = wp_nonce_field('omg_payment_tests', '_wpnonce', true, false);
        $action = esc_url(admin_url('admin-post.php'));
        echo '<div class="wrap"><h1>Payment tests</h1>';
        echo '<p>Tests each enabled payment method: a test order, a full payment in Stripe <strong>test mode</strong> (test cards), and live-account checks that create a payment and cancel it at once, so <strong>nobody is charged</strong>. You get an email after every test; the test order is then deleted.</p>';
        if ($keys['test'] === '') {
            echo '<div class="notice notice-warning"><p>No Stripe <strong>test</strong> secret key is saved, so only the live checks run. Add your test keys under WooCommerce → Settings → Payments → Stripe (you can keep live mode on).</p></div>';
        }
        echo '<h2>Settings</h2><form method="post" action="' . $action . '">' . $nonce . '<input type="hidden" name="action" value="omg_payment_tests"><input type="hidden" name="do" value="save">';
        echo '<table class="form-table"><tr><th><label for="omgpt-email">Send results to</label></th><td><input id="omgpt-email" type="email" class="regular-text" name="email" value="' . esc_attr($s['email']) . '"></td></tr>';
        echo '<tr><th><label for="omgpt-s">Run automatically</label></th><td><select id="omgpt-s" name="schedule">';
        foreach (['daily' => 'Every day (04:20)', 'weekly' => 'Every week', 'off' => 'Off (manual only)'] as $k => $l) {
            echo '<option value="' . $k . '"' . selected($s['schedule'], $k, false) . '>' . esc_html($l) . '</option>';
        }
        echo '</select>' . ($next ? '<p class="description">Next run: ' . esc_html(wp_date('j M Y, H:i', $next)) . '</p>' : '') . '</td></tr></table>';
        submit_button('Save settings');
        echo '</form><h2>Run now</h2><form method="post" action="' . $action . '" style="display:inline-block;margin-right:12px">' . $nonce
            . '<input type="hidden" name="action" value="omg_payment_tests"><input type="hidden" name="do" value="run">';
        submit_button('Test every payment method', 'primary', 'submit', false);
        echo '</form><form method="post" action="' . $action . '" style="display:inline-block">' . $nonce
            . '<input type="hidden" name="action" value="omg_payment_tests"><input type="hidden" name="do" value="run_one"><select name="gateway">';
        foreach (self::methods() as $id => $title) {
            echo '<option value="' . esc_attr($id) . '">' . esc_html($title) . '</option>';
        }
        echo '</select> ';
        submit_button('Test this method', 'secondary', 'submit', false);
        echo '</form>';
        if (!empty($log['results'])) {
            echo '<h2>Last results <span style="font-weight:400;color:#666">(' . esc_html(wp_date('j M Y, H:i', (int) $log['at'])) . ')</span></h2>';
            foreach ($log['results'] as $r) {
                $fails = array_filter($r['steps'], static fn ($x) => !$x['ok'] && !$x['warn']);
                echo '<h3>' . ($fails ? '&#10060; ' : '&#9989; ') . esc_html($r['title']) . '</h3><ul style="margin-left:18px;list-style:disc">';
                foreach ($r['steps'] as $x) {
                    echo '<li>' . ($x['ok'] ? '' : ($x['warn'] ? '<strong>Warning:</strong> ' : '<strong>Failed:</strong> ')) . esc_html($x['label']) . ($x['detail'] !== '' ? ' – <span style="color:#555">' . esc_html($x['detail']) . '</span>' : '') . '</li>';
                }
                echo '</ul>';
            }
        }
        echo '</div>';
    }
}

register_deactivation_hook(__FILE__, static fn () => wp_clear_scheduled_hook('omg_payment_tests_run'));

add_action('plugins_loaded', static function () {
    if (class_exists('WooCommerce')) {
        OMG_Payment_Tests::init();
    }
});

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('omg-payment-tests', static function ($args) {
        if (!empty($args[0])) {
            $m = OMG_Payment_Tests::methods();
            if (!isset($m[$args[0]])) {
                WP_CLI::error('Unknown or disabled gateway: ' . $args[0]);
            }
            $r = OMG_Payment_Tests::run_one($args[0], $m[$args[0]]);
            OMG_Payment_Tests::email($r);
            foreach ($r['steps'] as $s) {
                WP_CLI::log(($s['ok'] ? 'OK   ' : ($s['warn'] ? 'WARN ' : 'FAIL ')) . $s['label'] . ($s['detail'] ? ' – ' . $s['detail'] : ''));
            }
            return;
        }
        OMG_Payment_Tests::run_all();
        WP_CLI::success('Done; results emailed.');
    });
}
