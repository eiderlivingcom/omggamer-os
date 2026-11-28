<?php
/**
 * Public order numbers and customer IDs.
 *
 * - Every order gets a unique random order number of 5 to 9 digits (never the internal sequence), shown to
 *   customers, on receipts, in emails, to Stripe and to OMGGamer OS. Admin order search finds it.
 * - Every customer gets a unique random 9-digit customer ID; the "xg" account has 0000000001.
 * Existing orders and customers: wp omg-ids backfill
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Ids
{
    public const ORDER_META = '_omg_order_number';
    public const USER_META = 'omg_customer_id';
    private const RESERVED_LOGIN = 'xg';
    private const RESERVED_ID = '0000000001';

    public static function init(): void
    {
        add_action('woocommerce_new_order', [__CLASS__, 'assign_order'], 1);
        add_action('woocommerce_checkout_order_created', [__CLASS__, 'assign_order'], 1);
        add_filter('woocommerce_order_number', [__CLASS__, 'order_number'], 20, 2);
        add_filter('woocommerce_shop_order_search_fields', [__CLASS__, 'search_fields']);
        add_filter('woocommerce_order_table_search_query_meta_keys', [__CLASS__, 'search_fields']);
        add_filter('woocommerce_shortcode_order_tracking_order_id', [__CLASS__, 'tracking_lookup']);
        add_action('user_register', [__CLASS__, 'assign_user'], 5);
        add_filter('manage_users_columns', static function ($c) {
            $c['omg_customer_id'] = 'Customer ID';
            return $c;
        });
        add_filter('manage_users_custom_column', static function ($v, $col, $uid) {
            return $col === 'omg_customer_id' ? esc_html(self::customer_id((int) $uid)) : $v;
        }, 10, 3);
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('omg-ids', [__CLASS__, 'cli']);
        }
    }

    /* ---------------------------------------------------------------- orders */

    private static function order_number_taken(string $n): bool
    {
        $found = wc_get_orders(['limit' => 1, 'return' => 'ids', 'type' => ['shop_order', 'shop_subscription'], 'meta_key' => self::ORDER_META, 'meta_value' => $n, 'status' => 'any']);
        return (bool) $found;
    }

    private static function new_order_number(): string
    {
        for ($i = 0; $i < 30; $i++) {
            $len = random_int(5, 9);
            $n = (string) random_int(10 ** ($len - 1), 10 ** $len - 1); // no leading zero
            if (!self::order_number_taken($n)) {
                return $n;
            }
        }
        return (string) random_int(100000000, 999999999);
    }

    /** @param int|WC_Order $order */
    public static function assign_order($order): void
    {
        $order = $order instanceof WC_Order ? $order : wc_get_order($order);
        if (!$order || $order->get_type() !== 'shop_order' || $order->get_meta(self::ORDER_META)) {
            return;
        }
        $order->update_meta_data(self::ORDER_META, self::new_order_number());
        $order->save_meta_data();
    }

    public static function order_number($number, $order)
    {
        if (!$order instanceof WC_Order) {
            return $number;
        }
        $n = (string) $order->get_meta(self::ORDER_META);
        return $n !== '' ? $n : $number;
    }

    public static function search_fields($fields)
    {
        $fields[] = self::ORDER_META;
        return array_values(array_unique($fields));
    }

    /** Order tracking form: customers type the public order number. */
    public static function tracking_lookup($id)
    {
        $id = preg_replace('/\D/', '', (string) $id);
        if ($id === '') {
            return $id;
        }
        $found = wc_get_orders(['limit' => 1, 'return' => 'ids', 'meta_key' => self::ORDER_META, 'meta_value' => $id, 'status' => 'any']);
        return $found ? (int) $found[0] : $id;
    }

    /* ------------------------------------------------------------- customers */

    public static function customer_id(int $uid): string
    {
        $v = (string) get_user_meta($uid, self::USER_META, true);
        if ($v === '' && $uid) {
            self::assign_user($uid);
            $v = (string) get_user_meta($uid, self::USER_META, true);
        }
        return $v;
    }

    public static function assign_user(int $uid): void
    {
        if (!$uid || get_user_meta($uid, self::USER_META, true) !== '') {
            return;
        }
        $u = get_userdata($uid);
        if ($u && strcasecmp($u->user_login, self::RESERVED_LOGIN) === 0) {
            update_user_meta($uid, self::USER_META, self::RESERVED_ID);
            return;
        }
        for ($i = 0; $i < 30; $i++) {
            $id = (string) random_int(100000000, 999999999); // 9 digits
            if (!get_users(['meta_key' => self::USER_META, 'meta_value' => $id, 'number' => 1, 'fields' => 'ids', 'blog_id' => 0])) {
                update_user_meta($uid, self::USER_META, $id);
                return;
            }
        }
    }

    /* ------------------------------------------------------------------- CLI */

    /**
     * Give every existing order a public number and every user a customer ID.
     *
     * ## OPTIONS
     * <backfill>
     */
    public static function cli($args): void
    {
        if (($args[0] ?? '') !== 'backfill') {
            WP_CLI::error('Usage: wp omg-ids backfill');
        }
        $orders = 0;
        $page = 1;
        do {
            $ids = wc_get_orders(['limit' => 200, 'page' => $page, 'return' => 'ids', 'type' => 'shop_order', 'status' => 'any', 'orderby' => 'ID', 'order' => 'ASC']);
            foreach ($ids as $oid) {
                $o = wc_get_order($oid);
                if ($o && !$o->get_meta(self::ORDER_META)) {
                    self::assign_order($o);
                    $orders++;
                }
            }
            $page++;
        } while (count($ids) === 200);
        $users = 0;
        foreach (get_users(['fields' => 'ids', 'blog_id' => 0, 'number' => -1]) as $uid) {
            if (get_user_meta((int) $uid, self::USER_META, true) === '') {
                self::assign_user((int) $uid);
                $users++;
            }
        }
        $xg = get_user_by('login', self::RESERVED_LOGIN);
        if ($xg && get_user_meta($xg->ID, self::USER_META, true) !== self::RESERVED_ID) {
            update_user_meta($xg->ID, self::USER_META, self::RESERVED_ID);
        }
        WP_CLI::success(sprintf('Order numbers added: %d. Customer IDs added: %d.%s', $orders, $users, $xg ? ' xg = ' . self::RESERVED_ID . '.' : ' No user with login "xg" found.'));
    }
}
