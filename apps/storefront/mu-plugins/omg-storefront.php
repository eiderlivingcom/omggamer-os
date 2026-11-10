<?php
/**
 * Plugin Name: OMGGamer storefront tweaks
 * Description: Brand logo and favicon, and the boxed checkout layout. Works on every site in the network (store and /blogs/).
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Storefront
{
    public static function brand_url(string $file): string
    {
        // Brand files live in the network's main uploads folder (copied there by apps/storefront/install.sh).
        return content_url('uploads/omg-brand/' . $file);
    }

    public static function init(): void
    {
        add_action('wp_head', [__CLASS__, 'favicon'], 1);
        add_action('admin_head', [__CLASS__, 'favicon'], 1);
        add_action('login_head', [__CLASS__, 'favicon'], 1);
        add_action('wp_head', [__CLASS__, 'css'], 99);
    }

    /** The OMGGamer favicon, unless a site icon has been set in Settings, which then wins. */
    public static function favicon(): void
    {
        if (has_site_icon()) {
            return;
        }
        printf(
            '<link rel="icon" href="%1$s" sizes="32x32"><link rel="icon" href="%2$s" sizes="192x192"><link rel="apple-touch-icon" href="%3$s"><meta name="msapplication-TileImage" content="%2$s">' . "\n",
            esc_url(self::brand_url('favicon-32.png')),
            esc_url(self::brand_url('favicon-192.png')),
            esc_url(self::brand_url('favicon-180.png'))
        );
    }

    public static function css(): void
    {
        $logo = esc_url(self::brand_url('omggamer-logo.svg'));
        ?>
<style id="omg-storefront-css">
/* Logo image (header, footer, blog). --light is the white version for dark backgrounds. */
.omg-brand-img{display:block;height:38px;width:auto;max-width:none}
.omg-brand-img--light{filter:brightness(0) invert(1)}
#omg-logo-text{display:block;line-height:0}
#omg-ftr-logo{line-height:0}
#omg-ftr-logo a{display:inline-block}
.omg-brand-logo{display:inline-block;line-height:0}
@media (max-width:640px){.omg-brand-img{height:30px}}
/* Checkout top bar logo (printed by the checkout plugin as text). */
.omgx-logo .mk,.omgx-logo .wm{display:none!important}
.omgx-logo::before{content:"";display:block;width:128px;height:36px;background:url("<?php echo $logo; ?>") left center/contain no-repeat;filter:brightness(0) invert(1)}
@media (max-width:640px){.omgx-logo::before{width:104px;height:30px}}
/* Boxed checkout: same width as the top bar instead of the full window. */
body.omgx-on .wp-block-woocommerce-checkout.alignwide,body.woocommerce-checkout .wp-block-woocommerce-checkout{width:100%!important;max-width:1320px!important;margin-left:auto!important;margin-right:auto!important;box-sizing:border-box}
body.omgx-on .wc-block-components-sidebar-layout.wc-block-checkout{width:100%!important;max-width:100%!important;box-sizing:border-box}
</style>
        <?php
    }
}

OMG_Storefront::init();
