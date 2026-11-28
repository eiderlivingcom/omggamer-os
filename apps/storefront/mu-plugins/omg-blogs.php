<?php
/**
 * Plugin Name: OMGGamer blog links
 * Description: The blog lives at omggamer.com/blogs/ (site 2 of the network). /blog and anything under it redirect
 *              there (301), the footer "Blog" link points straight at it, and product pages show "From the blog":
 *              up to four articles from /blogs/ related to the product: the game, its series, genres, publisher /
 *              developer, then platform. No related article: no section.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Blogs
{
    private const BLOG_PATH = '/blogs/';

    public static function init(): void
    {
        add_action('template_redirect', [__CLASS__, 'redirect_blog'], 0);
        add_action('wp_footer', [__CLASS__, 'product_posts'], 97);
        add_action('wp_footer', [__CLASS__, 'footer_link'], 98);
        add_action('wp_body_open', [__CLASS__, 'retire_old_section'], 1);
    }

    private static function blog_id(): int
    {
        if (!is_multisite()) {
            return 0;
        }
        $site = get_site_by_path(wp_parse_url(home_url(), PHP_URL_HOST), self::BLOG_PATH);
        return $site && (int) $site->blog_id !== get_current_blog_id() ? (int) $site->blog_id : 0;
    }

    /** /blog, /blog/, /blog/anything → /blogs/ (the main store site only). */
    public static function redirect_blog(): void
    {
        $path = (string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (is_main_site() && preg_match('#^/blog(/.*)?$#', $path)) {
            $rest = ltrim((string) preg_replace('#^/blog/?#', '', $path), '/');
            wp_redirect(home_url(self::BLOG_PATH . $rest), 301);
            exit;
        }
    }

    /** "Euro Truck Simulator 2 (Latin America) (Steam) Gift" → "Euro Truck Simulator 2". */
    private static function game_name(string $title): string
    {
        $n = html_entity_decode(wp_strip_all_tags($title), ENT_QUOTES);
        $n = (string) preg_replace('/\s*[\(\[].*$/u', '', $n);                       // (Region) (Platform) ...
        $n = (string) preg_replace('/\s+[-\x{2013}\x{2014}|]\s+.*$/u', '', $n);      // " – Digital Key", " - Steam"
        $n = (string) preg_replace('/\b(pc|steam|xbox( one| series x\|?s)?|playstation ?[45]?|ps[45]|nintendo( switch)?|switch|epic games|gog|ea app|ubisoft connect|microsoft store|ms store)\b.*$/i', '', $n);
        $n = (string) preg_replace('/\b(cd )?key|account|gift|altergift|activation|global|europe|or mystery\b.*$/i', '', $n);
        $n = (string) preg_replace('/\b(gold|deluxe|ultimate|premium|standard|complete|definitive|goty|game of the year|digital|collector\'?s|enhanced|anniversary)( edition)?\b.*$/i', '', $n);
        return trim($n, " \t-–—:|,");
    }

    /** The series: the name before a subtitle or sequel number ("Assetto Corsa Rally" stays, "Fallout 4" → "Fallout"). */
    private static function series(string $name): string
    {
        $s = trim((string) preg_split('/\s*[:\x{2013}\x{2014}]\s*/u', $name)[0]);
        $s = trim((string) preg_replace('/\s+(\d+|[IVX]{1,4})$/u', '', $s));
        return mb_strlen($s) >= 4 && strcasecmp($s, $name) !== 0 ? $s : '';
    }

    private static function term_names(int $product_id, string $tax, int $n = 3): array
    {
        if (!taxonomy_exists($tax)) {
            return [];
        }
        $t = wp_get_post_terms($product_id, $tax, ['fields' => 'names']);
        return is_wp_error($t) ? [] : array_slice(array_map(static fn ($x) => html_entity_decode((string) $x, ENT_QUOTES), $t), 0, $n);
    }

    /**
     * Up to 4 blog posts related to the product, most specific first: the game, its series, its genres, its
     * publisher / developer, and only then its platform. Nothing related → no section (never unrelated posts).
     * Cached for 6 hours per product.
     */
    private static function posts_for(int $product_id): array
    {
        $blog = self::blog_id();
        if (!$blog || !$product_id) {
            return [];
        }
        $key = 'omg_blog_rel_v5_' . $product_id;
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
        $name = self::game_name((string) get_the_title($product_id));
        $tiers = [];
        if (mb_strlen($name) >= 3) {
            $tiers[] = [$name];
            if ($series = self::series($name)) {
                $tiers[] = [$series];
            }
        }
        $genre_tier = self::term_names($product_id, 'pa_genres', 3);
        $tiers[] = $genre_tier;
        $maker_tier = array_merge(self::term_names($product_id, 'pa_publishers', 1), self::term_names($product_id, 'pa_developers', 1));
        $tiers[] = $maker_tier;
        $platform_tier = self::term_names($product_id, 'pa_platform', 1);

        switch_to_blog($blog);
        $out = [];
        $seen = [];
        // $in_title: broad words (genre, publisher / developer, platform) only count when the title has them as a whole word.
        $find = static function (array $phrases, bool $in_title = false) use (&$out, &$seen): void {
            foreach ($phrases as $phrase) {
                $phrase = trim((string) $phrase);
                if (count($out) >= 4 || mb_strlen($phrase) < 3) {
                    continue;
                }
                // Whole-phrase search ("Euro Truck Simulator", not any of its words); titles rank first.
                $q = ['s' => $phrase, 'sentence' => true, 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => $in_title ? 12 : 4, 'fields' => 'ids', 'orderby' => 'relevance'];
                if ($in_title) {
                    $q['search_columns'] = ['post_title'];
                }
                foreach (get_posts($q) as $id) {
                    if (count($out) >= 4 || isset($seen[$id])) {
                        continue;
                    }
                    if ($in_title && !preg_match('/(?<![\\p{L}\\p{N}])' . preg_quote($phrase, '/') . '(?![\\p{L}\\p{N}])/iu', html_entity_decode(get_the_title($id), ENT_QUOTES))) {
                        continue;
                    }
                    $seen[$id] = 1;
                    $out[] = [
                        'title' => html_entity_decode(get_the_title($id), ENT_QUOTES),
                        'url' => get_permalink($id),
                        'image' => get_the_post_thumbnail_url($id, 'medium_large') ?: '',
                        'date' => get_the_date('M j, Y', $id),
                    ];
                }
            }
        };
        foreach ($tiers as $i => $phrases) {
            $find($phrases, $phrases && ($phrases === $genre_tier || $phrases === $maker_tier));
        }
        if (!$out) {
            $find($platform_tier, true); // the platform only when nothing more specific exists
        }
        restore_current_blog();
        set_transient($key, $out, 6 * HOUR_IN_SECONDS);
        return $out;
    }

    public static function product_posts(): void
    {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        $posts = self::posts_for((int) get_queried_object_id());
        if (!$posts) {
            return;
        }
        $all = home_url(self::BLOG_PATH);
        ?>
<section class="omg-blogs" id="omg-blogs" aria-labelledby="omg-blogs-h" hidden>
  <div class="omg-blogs-hd"><h2 id="omg-blogs-h">From the blog</h2><a href="<?php echo esc_url($all); ?>">All articles &rsaquo;</a></div>
  <div class="omg-blogs-grid">
    <?php foreach ($posts as $p) : ?>
    <a class="omg-blogs-card" href="<?php echo esc_url($p['url']); ?>">
      <span class="im"><?php if ($p['image']) : ?><img src="<?php echo esc_url($p['image']); ?>" alt="" loading="lazy"><?php endif; ?></span>
      <span class="tx"><b><?php echo esc_html($p['title']); ?></b></span>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<style id="omg-blogs-css">
.omg-blogs{max-width:1280px;margin:40px auto 56px;padding:0 16px;font-family:Poppins,system-ui,sans-serif}
.omg-blogs-hd{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:16px}
.omg-blogs-hd h2{margin:0;color:#fff;font:700 clamp(22px,2.6vw,28px)/1.2 Poppins,sans-serif}
.omg-blogs-hd a{color:#c4b5fd;font-weight:600;text-decoration:none}
.omg-blogs-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.omg-blogs-card{display:flex;flex-direction:column;border-radius:16px;overflow:hidden;background:#141121;border:1px solid #2a2540;text-decoration:none;transition:border-color .2s,transform .2s}
.omg-blogs-card:hover{border-color:#8b5cf6;transform:translateY(-2px)}
.omg-blogs-card .im{display:block;aspect-ratio:16/9;overflow:hidden;background:#1d1930}
.omg-blogs-card .im img{width:100%;height:100%;object-fit:cover;display:block}
.omg-blogs-card .tx{display:block;padding:14px 16px 16px}
.omg-blogs-card small{color:#8d84a8;font-size:12px}
.omg-blogs-card b{color:#f4f2fb;font-size:15px;line-height:1.35}
.omg-blogs-card .tx span{color:#a79ec0;font-size:13px;line-height:1.5}
@media (max-width:1000px){.omg-blogs-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:560px){.omg-blogs-grid{grid-template-columns:1fr}}
</style>
<script id="omg-blogs-js">
(function(){
  var s = document.getElementById('omg-blogs'); if (!s) return;
  // Under the related products (where the template's old blog row was), else at the end of the page content.
  var slot = document.getElementById('omg-dg-related-slot');
  var main = document.getElementById('brx-content') || document.querySelector('main');
  if (slot && slot.parentNode) slot.parentNode.insertBefore(s, slot.nextSibling); else if (main) main.appendChild(s);
  s.hidden = false;
})();
</script>
        <?php
    }

    /**
     * The product template's own "From the blog" read posts from the store site (only "Hello world!"). Its script
     * stops when an element with class omg-blog exists, so an empty one retires it; the /blogs/ section replaces it.
     */
    public static function retire_old_section(): void
    {
        if (function_exists('is_product') && is_product()) {
            echo '<div class="omg-blog" hidden aria-hidden="true"></div>';
        }
    }

    /** The footer "Blog" link goes straight to /blogs/. */
    public static function footer_link(): void
    {
        $url = esc_url(home_url(self::BLOG_PATH));
        echo '<script id="omg-blog-link">document.querySelectorAll(\'a[href="/blog/"],a[href="/blog"],a[href$="//' . esc_js(wp_parse_url(home_url(), PHP_URL_HOST)) . '/blog/"]\').forEach(function(a){a.href=' . wp_json_encode($url) . ';});</script>';
    }
}

OMG_Blogs::init();
