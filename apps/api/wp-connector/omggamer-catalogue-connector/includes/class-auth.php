<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server-to-server authentication: HMAC-SHA256 over "<timestamp>.<raw body>" with the shared secret.
 *
 * Headers: X-OMG-Timestamp (unix seconds), X-OMG-Signature (hex), X-OMG-Idempotency-Key (optional).
 * Requests older or newer than 5 minutes are refused, so a captured request cannot be replayed later.
 * A repeated idempotency key returns the stored response instead of running the request again.
 */
class OMG_Connector_Auth
{
    const WINDOW = 300;

    public static function verify(WP_REST_Request $request)
    {
        $secret = (string) get_option('omg_connector_secret', '');
        if (strlen($secret) < 32) {
            return new WP_Error('omg_not_configured', 'The connector secret is not set in WooCommerce → OMGGamer Connector.', ['status' => 503]);
        }
        $ts = (string) $request->get_header('x_omg_timestamp');
        $sig = strtolower((string) $request->get_header('x_omg_signature'));
        if ($ts === '' || $sig === '' || !ctype_digit($ts)) {
            return new WP_Error('omg_unsigned', 'Missing signature.', ['status' => 401]);
        }
        if (abs(time() - (int) $ts) > self::WINDOW) {
            return new WP_Error('omg_expired', 'Request timestamp outside the allowed window.', ['status' => 401]);
        }
        $expected = hash_hmac('sha256', $ts . '.' . $request->get_body(), $secret);
        if (!hash_equals($expected, $sig)) {
            return new WP_Error('omg_bad_signature', 'Invalid signature.', ['status' => 401]);
        }
        return true;
    }

    private static function key_name(WP_REST_Request $request)
    {
        $key = (string) $request->get_header('x_omg_idempotency_key');
        if ($key === '' || strlen($key) > 120) {
            return null;
        }
        return 'omg_idem_' . md5($request->get_route() . '|' . $key);
    }

    public static function cached(WP_REST_Request $request)
    {
        $name = self::key_name($request);
        return $name ? get_transient($name) : false;
    }

    public static function remember(WP_REST_Request $request, $response)
    {
        $name = self::key_name($request);
        if ($name) {
            set_transient($name, $response, DAY_IN_SECONDS);
        }
    }

    /** Serialise concurrent writes for the same OMGGamer product (no duplicate creates). */
    public static function lock($external_id)
    {
        global $wpdb;
        $name = 'omg_' . substr(md5((string) $external_id), 0, 32);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 20)', $name));
        return $got === '1' ? $name : null;
    }

    public static function unlock($name)
    {
        global $wpdb;
        if ($name) {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }
}
