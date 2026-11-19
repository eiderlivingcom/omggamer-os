<?php
/**
 * Plugin Name: OMGGamer game currency product layout
 * Description: Game currency products (Robux, V-Bucks, coins...) get their own product layout: the buy box shows the
 *              region and then the amounts (200 Robux, 400 Robux...) instead of an edition; the specs say
 *              "Game currency" and the note explains the top-up code. Detection: omg_is_currency() (omg-titles).
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Currency_Product
{
    public static function init(): void
    {
        add_filter('body_class', [__CLASS__, 'body_class']);
        add_action('wp_footer', [__CLASS__, 'script'], 80);
    }

    private static function current(): int
    {
        if (!function_exists('is_product') || !is_product() || !function_exists('omg_is_currency')) {
            return 0;
        }
        $id = (int) get_queried_object_id();
        return omg_is_currency($id) ? $id : 0;
    }

    public static function body_class(array $classes): array
    {
        if (self::current()) {
            $classes[] = 'omg-currency';
        }
        return $classes;
    }

    public static function script(): void
    {
        $id = self::current();
        if (!$id) {
            return;
        }
        // "Roblox – 200 Robux (Global) – Digital Key" → game "Roblox".
        $name = html_entity_decode(get_the_title($id), ENT_QUOTES);
        $game = trim((string) preg_split('/\s+[-\x{2013}\x{2014}]\s+|\s+\d/u', $name)[0]) ?: $name;
        ?>
<style id="omg-currency-css">
body.omg-currency #omg-dg-ed-cards .omg-ed-card .omg-ed-name{font-size:17px}
</style>
<script id="omg-currency-js">
(function () {
  var GAME = <?php echo wp_json_encode($game); ?>;
  function apply() {
    // The currency's own word from the amounts ("200 Robux" → "Robux"), else a neutral one.
    var first = document.querySelector('#omg-dg-ed-cards .omg-ed-name');
    var m = first ? first.textContent.match(/[0-9][0-9.,]*\s+(.+)$/) : null;
    var cur = m ? m[1].trim() : 'credit';
    var tv = document.querySelector('#omg-dg-spec-type .omg-dg-spec-v');
    if (tv) tv.textContent = 'Game currency';
    var nt = document.getElementById('omg-dg-note-text');
    if (nt) nt.innerHTML = 'This is a <strong>' + GAME + ' top-up code</strong>. You receive the code by email and in your dashboard; redeem it in ' + GAME + ' to add ' + cur + ' to your account. Check the region above before you buy.';
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(apply, 0); }); else setTimeout(apply, 0);
})();
</script>
        <?php
    }
}

OMG_Currency_Product::init();
