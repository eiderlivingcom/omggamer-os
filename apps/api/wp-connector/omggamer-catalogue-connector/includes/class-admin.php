<?php
if (!defined('ABSPATH')) {
    exit;
}

/** WooCommerce → OMGGamer Connector: shared secret and the vendor that owns new products. */
class OMG_Connector_Admin
{
    public static function menu()
    {
        add_submenu_page('woocommerce', 'OMGGamer Connector', 'OMGGamer Connector', 'manage_woocommerce', 'omg-connector', [__CLASS__, 'page']);
    }

    public static function register_settings()
    {
        register_setting('omg_connector', 'omg_connector_secret', [
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                $value = trim((string) $value);
                // An empty field keeps the stored secret, so saving the page never wipes it.
                return $value === '' ? (string) get_option('omg_connector_secret', '') : $value;
            },
        ]);
        register_setting('omg_connector', 'omg_connector_vendor_id', ['type' => 'integer', 'sanitize_callback' => 'absint']);
    }

    public static function page()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $has_secret = strlen((string) get_option('omg_connector_secret', '')) >= 32;
        ?>
        <div class="wrap">
            <h1>OMGGamer Catalogue Connector</h1>
            <p>Receives approved products from OMGGamer OS. Endpoint: <code><?php echo esc_html(rest_url(OMG_CONNECTOR_NS)); ?></code></p>
            <form method="post" action="options.php">
                <?php settings_fields('omg_connector'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="omg_connector_secret">Shared secret</label></th>
                        <td>
                            <input type="password" id="omg_connector_secret" name="omg_connector_secret" class="regular-text" autocomplete="off" value="" placeholder="<?php echo $has_secret ? esc_attr('Stored — paste a new one to replace it') : esc_attr('Paste the secret from OMGGamer OS'); ?>">
                            <p class="description"><?php echo $has_secret ? 'A secret is stored.' : 'Not set yet. Requests are refused until it is.'; ?> Generate it in OMGGamer OS → Publishing → Storefronts.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="omg_connector_vendor_id">Vendor user ID</label></th>
                        <td>
                            <input type="number" min="0" id="omg_connector_vendor_id" name="omg_connector_vendor_id" value="<?php echo esc_attr((string) get_option('omg_connector_vendor_id', 0)); ?>">
                            <p class="description">Owner of products created by the connector (the Dokan store). 0 = first administrator.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
