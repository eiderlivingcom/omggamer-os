<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Routes (all signed, see OMG_Connector_Auth):
 *   GET  /status            plugin, WooCommerce and Dokan status
 *   POST /products/upsert   create or update one product
 *   POST /products/status   change one product's status
 *   POST /products/prices   update prices and stock of variants
 *   GET  /products/{id}     look up the WooCommerce product mapped to an OMGGamer product
 */
class OMG_Connector_REST
{
    public static function register()
    {
        $auth = ['OMG_Connector_Auth', 'verify'];
        register_rest_route(OMG_CONNECTOR_NS, '/status', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'status'], 'permission_callback' => $auth,
        ]);
        register_rest_route(OMG_CONNECTOR_NS, '/products/upsert', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'upsert'], 'permission_callback' => $auth,
        ]);
        register_rest_route(OMG_CONNECTOR_NS, '/products/status', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'set_status'], 'permission_callback' => $auth,
        ]);
        register_rest_route(OMG_CONNECTOR_NS, '/products/prices', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'prices'], 'permission_callback' => $auth,
        ]);
        register_rest_route(OMG_CONNECTOR_NS, '/orders/deliver', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'deliver'], 'permission_callback' => $auth,
        ]);
        register_rest_route(OMG_CONNECTOR_NS, '/orders/note', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'note'], 'permission_callback' => $auth,
        ]);
        register_rest_route(OMG_CONNECTOR_NS, '/products/(?P<external_id>[A-Za-z0-9-]{1,80})', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'lookup'], 'permission_callback' => $auth,
        ]);
    }

    private static function ready()
    {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('omg_no_woocommerce', 'WooCommerce is not active.', ['status' => 503]);
        }
        return true;
    }

    private static function body(WP_REST_Request $request)
    {
        $data = json_decode($request->get_body(), true);
        return is_array($data) ? $data : null;
    }

    public static function status(WP_REST_Request $request)
    {
        return rest_ensure_response([
            'ok' => true,
            'plugin_version' => OMG_CONNECTOR_VERSION,
            'woocommerce' => defined('WC_VERSION') ? WC_VERSION : null,
            'dokan' => defined('DOKAN_PLUGIN_VERSION') ? DOKAN_PLUGIN_VERSION : null,
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null,
            'site' => home_url(),
            'time' => gmdate('c'),
        ]);
    }

    private static function run(WP_REST_Request $request, $external_id, callable $fn)
    {
        $ready = self::ready();
        if (is_wp_error($ready)) {
            return $ready;
        }
        $cached = OMG_Connector_Auth::cached($request);
        if ($cached !== false) {
            $cached['idempotent_replay'] = true;
            return rest_ensure_response($cached);
        }
        $lock = $external_id !== null ? OMG_Connector_Auth::lock($external_id) : null;
        if ($external_id !== null && !$lock) {
            return new WP_Error('omg_busy', 'Another update for this product is running; retry shortly.', ['status' => 409]);
        }
        try {
            $result = $fn();
        } catch (Throwable $e) {
            $result = new WP_Error('omg_failed', $e->getMessage(), ['status' => 500]);
        } finally {
            OMG_Connector_Auth::unlock($lock);
        }
        if (is_wp_error($result)) {
            return $result;
        }
        OMG_Connector_Auth::remember($request, $result);
        return rest_ensure_response($result);
    }

    public static function upsert(WP_REST_Request $request)
    {
        $data = self::body($request);
        if ($data === null) {
            return new WP_Error('omg_invalid', 'Body must be a JSON object.', ['status' => 400]);
        }
        $ext = (string) ($data['external_id'] ?? '');
        return self::run($request, $ext, function () use ($data) {
            return (new OMG_Connector_Products())->upsert($data);
        });
    }

    public static function set_status(WP_REST_Request $request)
    {
        $data = self::body($request);
        if ($data === null) {
            return new WP_Error('omg_invalid', 'Body must be a JSON object.', ['status' => 400]);
        }
        $ext = (string) ($data['external_id'] ?? '');
        return self::run($request, $ext, function () use ($data, $ext) {
            return (new OMG_Connector_Products())->set_status($ext, (string) ($data['status'] ?? ''));
        });
    }

    public static function prices(WP_REST_Request $request)
    {
        $data = self::body($request);
        if ($data === null || !isset($data['items']) || !is_array($data['items']) || count($data['items']) > 500) {
            return new WP_Error('omg_invalid', 'Send up to 500 items.', ['status' => 400]);
        }
        return self::run($request, null, function () use ($data) {
            return (new OMG_Connector_Products())->update_prices($data['items']);
        });
    }

    public static function deliver(WP_REST_Request $request)
    {
        $data = self::body($request);
        if ($data === null) {
            return new WP_Error('omg_invalid', 'Body must be a JSON object.', ['status' => 400]);
        }
        // Keys are never cached in the idempotency store: replays are handled by de-duplicating serials.
        $ready = self::ready();
        if (is_wp_error($ready)) {
            return $ready;
        }
        $lock = OMG_Connector_Auth::lock('order-' . ($data['order_id'] ?? ''));
        try {
            return rest_ensure_response(OMG_Connector_Orders::deliver($data));
        } finally {
            OMG_Connector_Auth::unlock($lock);
        }
    }

    public static function note(WP_REST_Request $request)
    {
        $data = self::body($request);
        if ($data === null) {
            return new WP_Error('omg_invalid', 'Body must be a JSON object.', ['status' => 400]);
        }
        return self::run($request, null, function () use ($data) {
            return OMG_Connector_Orders::note($data);
        });
    }

    public static function lookup(WP_REST_Request $request)
    {
        $ready = self::ready();
        if (is_wp_error($ready)) {
            return $ready;
        }
        $id = OMG_Connector_Products::find_by_external($request['external_id']);
        if (!$id) {
            return new WP_Error('omg_not_found', 'Not mapped.', ['status' => 404]);
        }
        $product = wc_get_product($id);
        return rest_ensure_response([
            'ok' => true,
            'product_id' => $id,
            'status' => $product ? $product->get_status() : get_post_status($id),
            'type' => $product ? $product->get_type() : null,
            'price' => $product ? $product->get_price() : null,
            'permalink' => get_permalink($id),
        ]);
    }
}
