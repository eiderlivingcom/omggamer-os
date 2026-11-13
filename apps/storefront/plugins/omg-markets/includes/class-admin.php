<?php
/**
 * WooCommerce → Markets: markets, product regions, Google categories, exchange rates and feed URLs.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Markets_Admin
{
    private const SLUG = 'omg-markets';

    public static function init(): void
    {
        add_action('admin_menu', static function (): void {
            add_submenu_page('woocommerce', 'Markets & feeds', 'Markets & feeds', 'manage_woocommerce', self::SLUG, [__CLASS__, 'page']);
        }, 60);
        add_action('admin_post_omg_markets_save', [__CLASS__, 'save']);
        add_action('admin_post_omg_markets_rates', [__CLASS__, 'refresh_rates']);
        add_action('admin_notices', [__CLASS__, 'notice']);
    }

    private static function back(array $args = []): void
    {
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=' . self::SLUG)));
        exit;
    }

    public static function notice(): void
    {
        $d = OMG_Markets_Rates::data();
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if (empty($d['rates'])) {
            echo '<div class="notice notice-warning"><p><strong>OMGGamer Markets:</strong> no exchange rates yet, so only ' . esc_html(OMG_Markets::base_currency()) . ' prices are shown. <a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">Fetch rates</a>.</p></div>';
        } elseif (!empty($d['fetched']) && time() - (int) $d['fetched'] > 5 * DAY_IN_SECONDS) {
            echo '<div class="notice notice-warning"><p><strong>OMGGamer Markets:</strong> exchange rates were last updated ' . esc_html(human_time_diff((int) $d['fetched'])) . ' ago' . (!empty($d['error']) ? ' (last error: ' . esc_html($d['error']) . ')' : '') . '.</p></div>';
        }
    }

    public static function refresh_rates(): void
    {
        check_admin_referer('omg_markets_rates');
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Not allowed');
        }
        $r = OMG_Markets_Rates::refresh();
        self::back($r === true ? ['rates' => 'ok'] : ['rates' => 'fail']);
    }

    public static function save(): void
    {
        check_admin_referer('omg_markets_save');
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Not allowed');
        }
        $in = wp_unslash($_POST);
        $countries = WC()->countries->get_countries();
        $currencies = array_keys(get_woocommerce_currencies());
        $markets = [];
        foreach ((array) ($in['m'] ?? []) as $row) {
            $cc = strtoupper(sanitize_text_field($row['cc'] ?? ''));
            $cur = strtoupper(sanitize_text_field($row['currency'] ?? ''));
            if (!isset($countries[$cc]) || !in_array($cur, $currencies, true) || !empty($row['remove'])) {
                continue;
            }
            $markets[$cc] = [
                'currency' => $cur,
                'enabled' => empty($row['enabled']) ? 0 : 1,
                'markup' => max(-50, min(100, (float) ($row['markup'] ?? 0))),
                'rounding' => ($row['rounding'] ?? '') === 'charm' ? 'charm' : 'none',
            ];
        }
        $regions = [];
        foreach ((array) ($in['r'] ?? []) as $slug => $val) {
            $regions[sanitize_title($slug)] = preg_replace('/[^A-Za-z,*]/', '', (string) $val);
        }
        $gpc = [];
        foreach ((array) ($in['g'] ?? []) as $slug => $val) {
            $gpc[sanitize_title($slug)] = preg_replace('/\D/', '', (string) $val);
        }
        $default = strtoupper(sanitize_text_field($in['default'] ?? 'US'));
        OMG_Markets::save([
            'default' => isset($markets[$default]) ? $default : (array_key_first($markets) ?: 'US'),
            'geo' => empty($in['geo']) ? 0 : 1,
            'markets' => $markets ?: OMG_Markets::defaults()['markets'],
            'regions' => $regions,
            'gpc' => $gpc,
        ]);
        OMG_Markets_Feed::flush();
        self::back(['saved' => 1]);
    }

    public static function page(): void
    {
        $s = OMG_Markets::settings();
        $base = OMG_Markets::base_currency();
        $rates = OMG_Markets_Rates::data();
        $countries = WC()->countries->get_countries();
        $wc_currencies = get_woocommerce_currencies();
        $ecb = array_values(array_unique(array_merge(OMG_Markets_Rates::currencies(), array_column($s['markets'], 'currency'), [$base])));
        sort($ecb);
        $check = isset($_GET['check']);
        ?>
<div class="wrap">
  <h1>Markets &amp; Google feeds</h1>
  <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>Saved.</p></div><?php endif; ?>
  <?php if (($_GET['rates'] ?? '') === 'ok') : ?><div class="notice notice-success is-dismissible"><p>Exchange rates updated.</p></div><?php endif; ?>
  <?php if (($_GET['rates'] ?? '') === 'fail') : ?><div class="notice notice-error is-dismissible"><p>Could not fetch rates: <?php echo esc_html($rates['error'] ?? 'unknown error'); ?></p></div><?php endif; ?>

  <h2>Exchange rates</h2>
  <p>Store prices are kept in <strong><?php echo esc_html($base); ?></strong>. Other currencies use the European Central Bank's daily reference rates, refreshed twice a day.
    <?php if (!empty($rates['rates'])) : ?>
      Current set: <strong><?php echo esc_html($rates['date'] ?? '?'); ?></strong> (fetched <?php echo esc_html(human_time_diff((int) $rates['fetched'])); ?> ago).
    <?php else : ?>
      <strong>No rates fetched yet.</strong>
    <?php endif; ?>
    <?php if (!empty($rates['error'])) : ?><br><span style="color:#b32d2e">Last attempt failed: <?php echo esc_html($rates['error']); ?></span><?php endif; ?>
  </p>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('omg_markets_rates'); ?><input type="hidden" name="action" value="omg_markets_rates">
    <?php submit_button('Fetch rates now', 'secondary', 'submit', false); ?>
  </form>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('omg_markets_save'); ?><input type="hidden" name="action" value="omg_markets_save">

    <h2>Markets</h2>
    <p>Each market is a country with its own currency and its own Google feed. A market whose currency has no ECB rate is not offered.</p>
    <table class="widefat striped" style="max-width:1100px">
      <thead><tr><th>On</th><th>Country</th><th>Currency</th><th>1 <?php echo esc_html($base); ?> =</th><th>FX markup %</th><th>Rounding</th><th>Google feed URL</th><?php if ($check) : ?><th>Items</th><?php endif; ?><th>Remove</th></tr></thead>
      <tbody>
      <?php $i = 0; foreach ($s['markets'] as $cc => $m) :
          $rate = OMG_Markets_Rates::rate($base, $m['currency']);
          $usable = $rate !== null;
          ?>
        <tr>
          <td><input type="checkbox" name="m[<?php echo $i; ?>][enabled]" value="1" <?php checked(!empty($m['enabled'])); ?>></td>
          <td><input type="hidden" name="m[<?php echo $i; ?>][cc]" value="<?php echo esc_attr($cc); ?>"><?php echo OMG_Markets::flag($cc) . ' ' . esc_html(OMG_Markets::country_name($cc) . ' (' . $cc . ')'); ?></td>
          <td><select name="m[<?php echo $i; ?>][currency]"><?php foreach ($ecb as $c) : if (!isset($wc_currencies[$c])) { continue; } ?><option value="<?php echo esc_attr($c); ?>" <?php selected($m['currency'], $c); ?>><?php echo esc_html($c); ?></option><?php endforeach; ?><?php if (!in_array($m['currency'], $ecb, true)) : ?><option value="<?php echo esc_attr($m['currency']); ?>" selected><?php echo esc_html($m['currency']); ?></option><?php endif; ?></select></td>
          <td><?php echo $usable ? esc_html(rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.') . ' ' . $m['currency']) : '<span style="color:#b32d2e">no rate</span>'; ?></td>
          <td><input type="number" step="0.1" min="-50" max="100" style="width:80px" name="m[<?php echo $i; ?>][markup]" value="<?php echo esc_attr($m['markup'] ?? 0); ?>"></td>
          <td><select name="m[<?php echo $i; ?>][rounding]"><option value="none" <?php selected(($m['rounding'] ?? 'none'), 'none'); ?>>Exact</option><option value="charm" <?php selected(($m['rounding'] ?? 'none'), 'charm'); ?>>Up to .99</option></select></td>
          <td><?php if (!empty($m['enabled']) && $usable) : ?><input type="text" readonly class="regular-text code" onclick="this.select()" value="<?php echo esc_attr(OMG_Markets_Feed::url($cc)); ?>"> <a href="<?php echo esc_url(OMG_Markets_Feed::url($cc)); ?>" target="_blank" rel="noopener">open</a><?php else : ?>—<?php endif; ?></td>
          <?php if ($check) : ?><td><?php if (!empty($m['enabled']) && $usable) { $b = OMG_Markets_Feed::build($cc); echo (int) $b['items']; if ($b['skipped']) { echo '<br><small>left out: ' . esc_html(implode('; ', array_map(static fn ($k, $v) => "$v $k", array_keys($b['skipped']), $b['skipped']))) . '</small>'; } } ?></td><?php endif; ?>
          <td><input type="checkbox" name="m[<?php echo $i; ?>][remove]" value="1"></td>
        </tr>
      <?php $i++; endforeach; ?>
        <tr>
          <td><input type="checkbox" name="m[<?php echo $i; ?>][enabled]" value="1" checked></td>
          <td><select name="m[<?php echo $i; ?>][cc]"><option value="">Add a country…</option><?php foreach ($countries as $code => $name) : if (isset($s['markets'][$code])) { continue; } ?><option value="<?php echo esc_attr($code); ?>"><?php echo esc_html(html_entity_decode($name)); ?></option><?php endforeach; ?></select></td>
          <td><select name="m[<?php echo $i; ?>][currency]"><?php foreach ($ecb as $c) : if (!isset($wc_currencies[$c])) { continue; } ?><option value="<?php echo esc_attr($c); ?>" <?php selected($c, $base); ?>><?php echo esc_html($c); ?></option><?php endforeach; ?></select></td>
          <td></td>
          <td><input type="number" step="0.1" min="-50" max="100" style="width:80px" name="m[<?php echo $i; ?>][markup]" value="0"></td>
          <td><select name="m[<?php echo $i; ?>][rounding]"><option value="none">Exact</option><option value="charm">Up to .99</option></select></td>
          <td colspan="<?php echo $check ? 3 : 2; ?>"></td>
        </tr>
      </tbody>
    </table>
    <p><a class="button" href="<?php echo esc_url(add_query_arg('check', 1)); ?>">Count feed items</a> <span class="description">Builds every feed once and shows how many products each one has.</span></p>

    <h2>Visitors</h2>
    <p><label>Default market <select name="default"><?php foreach ($s['markets'] as $cc => $m) : ?><option value="<?php echo esc_attr($cc); ?>" <?php selected($s['default'], $cc); ?>><?php echo esc_html(OMG_Markets::country_name($cc)); ?></option><?php endforeach; ?></select></label></p>
    <p><label><input type="checkbox" name="geo" value="1" <?php checked(!empty($s['geo'])); ?>> Pick the market from the visitor's country (Cloudflare) until they choose one</label></p>
    <p class="description">Links with <code>?market=gb</code> (or <code>uk</code>, <code>au</code>, …) open the store in that market; the choice is remembered in a cookie.</p>

    <h2>Product regions</h2>
    <p>Which countries a key's region (<code>pa_region</code>) can be activated in. <code>*</code> = everywhere, a comma-separated list of country codes, or empty to keep it out of every feed. Products without a region go into every feed; a region not listed here is left out until it is mapped.</p>
    <table class="widefat striped" style="max-width:700px"><tbody>
      <?php
      $slugs = array_unique(array_merge(array_keys($s['regions']), taxonomy_exists('pa_region') ? get_terms(['taxonomy' => 'pa_region', 'hide_empty' => false, 'fields' => 'slugs']) : []));
      foreach ($slugs as $slug) :
          $term = get_term_by('slug', $slug, 'pa_region');
          ?>
        <tr><td style="width:40%"><?php echo esc_html($term ? html_entity_decode($term->name) : $slug); ?> <code><?php echo esc_html($slug); ?></code></td>
          <td><input type="text" class="regular-text" name="r[<?php echo esc_attr($slug); ?>]" value="<?php echo esc_attr($s['regions'][$slug] ?? ''); ?>" placeholder="<?php echo array_key_exists($slug, $s['regions']) ? '' : 'not mapped yet'; ?>"></td></tr>
      <?php endforeach; ?>
    </tbody></table>

    <h2>Google product categories</h2>
    <p>Product category → <a href="https://support.google.com/merchants/answer/6324436" target="_blank" rel="noopener">Google product category ID</a>. Leave empty to let Google decide; every item also carries its store category as <code>product_type</code>.</p>
    <table class="widefat striped" style="max-width:700px"><tbody>
      <?php
      $cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
      foreach ($cats as $cat) : ?>
        <tr><td style="width:40%"><?php echo esc_html(html_entity_decode($cat->name)); ?> <code><?php echo esc_html($cat->slug); ?></code></td>
          <td><input type="text" inputmode="numeric" class="small-text" name="g[<?php echo esc_attr($cat->slug); ?>]" value="<?php echo esc_attr($s['gpc'][$cat->slug] ?? ''); ?>"></td></tr>
      <?php endforeach; ?>
    </tbody></table>

    <?php submit_button('Save markets'); ?>
  </form>

  <h2>Adding the feeds to Google Merchant Center</h2>
  <ol>
    <li>Merchant Center → Products → Add products → <em>Add products from a file</em> → <em>Enter a link to your file</em>.</li>
    <li>Paste one feed URL from the table above, set the country to that market's country and the language to English, and schedule a daily fetch.</li>
    <li>Repeat for each country. Shipping for physical items and returns are set in Merchant Center (digital items already carry free delivery).</li>
  </ol>
</div>
        <?php
    }
}
