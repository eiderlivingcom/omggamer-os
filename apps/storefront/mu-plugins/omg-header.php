<?php
/**
 * Plugin Name: OMGGamer header
 * Description: Floating rounded header bar (logo, search, currency, cart, log in) and a platform row with icons
 *              (Steam, PlayStation, Xbox, Nintendo, Epic Games, Gift cards, Discover all, Today's deals).
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Header
{
    public static function nav(): string
    {
        $home = untrailingslashit(home_url());
        $nav = <<<'HTML'
<nav id="omg-hdr-nav" class="brxe-container omg-pnav-row" aria-label="Platforms">
<a class="omg-pnav" href="/platform/steam/"><span class="omg-pf omg-pnav-ic" data-pf="steam" aria-hidden="true"></span>Steam</a>
<a class="omg-pnav" href="/platform/playstation-5/"><span class="omg-pf omg-pnav-ic" data-pf="playstation-5" aria-hidden="true"></span>PlayStation</a>
<a class="omg-pnav" href="/platform/xbox-one/"><span class="omg-pf omg-pnav-ic" data-pf="xbox-one" aria-hidden="true"></span>Xbox</a>
<a class="omg-pnav" href="/platform/nintendo/"><span class="omg-pf omg-pnav-ic" data-pf="nintendo" aria-hidden="true"></span>Nintendo</a>
<a class="omg-pnav" href="/platform/epic-games/"><span class="omg-pf omg-pnav-ic" data-pf="epic-games" aria-hidden="true"></span>Epic Games</a>
<a class="omg-pnav" href="/collections/gift-cards/"><span class="omg-pnav-ic is-gift" aria-hidden="true"></span>Gift cards</a>
<a class="omg-pnav" href="/shop/"><span class="omg-pnav-ic is-all" aria-hidden="true"></span>Discover all</a>
<a class="omg-pnav is-deals" href="/shop/?on_sale=1"><span class="omg-pnav-ic" aria-hidden="true"></span>Today's deals</a>
</nav>
HTML;
        return str_replace('href="/', 'href="' . esc_url($home) . '/', $nav);
    }

    public static function init(): void
    {
        add_filter('bricks/frontend/render_data', [__CLASS__, 'swap_nav'], 20, 3);
        add_action('wp_head', [__CLASS__, 'css'], 130);
        add_action('wp_footer', [__CLASS__, 'fallback'], 5);
    }

    /** Replace the header's text navigation with the platform row (server side, so nothing flashes). */
    public static function swap_nav($html, $post = null, $area = '')
    {
        if (!is_string($html) || strpos($html, 'id="omg-hdr-nav"') === false || strpos($html, 'omg-pnav') !== false) {
            return $html;
        }
        $out = preg_replace('~<nav[^>]*id="omg-hdr-nav"[^>]*>.*?</nav>~s', self::nav(), $html, 1);
        return is_string($out) ? $out : $html;
    }

    public static function css(): void
    {
        if (is_admin() || (function_exists('bricks_is_builder') && bricks_is_builder())) {
            return;
        }
        echo "<style id=\"omg-header-css\">\n";
        ?>
/* OMGGamer header: floating rounded bar + platform row (dark scheme). */
html body #omg-hdr{background:transparent!important;border:0!important;box-shadow:none!important;padding:14px 24px 0!important;backdrop-filter:none!important}
html body #omg-hdr-top{max-width:1320px!important;margin:0 auto!important;min-height:76px;padding:10px 0!important;gap:22px!important;background:transparent!important;border:0!important;border-radius:0!important;box-shadow:none!important;align-items:center!important;box-sizing:border-box}
html body #omg-logo .omg-brand-img{height:40px!important}
html body #omg-hdr-search{flex:1 1 auto!important;max-width:none!important;width:auto!important}
html body #omg-hdr-search form{position:relative}
html body #omg-hdr-search input[type=search],html body #omg-hdr-search input{height:54px!important;border-radius:999px!important;padding:0 58px 0 26px!important;font-size:16px!important;background:#1b1828!important;border:1px solid transparent!important;color:#f4f2fb!important;box-shadow:none!important}
html body #omg-hdr-search input:focus{border-color:#8b5cf6!important;box-shadow:0 0 0 3px rgba(139,92,246,.25)!important}
html body #omg-hdr-search::after{display:none!important}
html body #omg-hdr-search form::after{content:"";position:absolute;right:20px;top:50%;width:24px;height:24px;margin-top:-12px;pointer-events:none;background:linear-gradient(135deg,#a78bfa,#e879f9);-webkit-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2.4' stroke-linecap='round'%3E%3Ccircle cx='11' cy='11' r='7.5'/%3E%3Cpath d='m20.5 20.5-4.2-4.2'/%3E%3C/svg%3E") center/contain no-repeat;mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2.4' stroke-linecap='round'%3E%3Ccircle cx='11' cy='11' r='7.5'/%3E%3Cpath d='m20.5 20.5-4.2-4.2'/%3E%3C/svg%3E") center/contain no-repeat}
html body #omg-hdr-search svg,html body #omg-hdr-search .bricks-search-icon,html body #omg-hdr-search button{display:none!important}
html body #omg-hdr-actions{gap:18px!important;align-items:center!important}
html body #omg-hdr-sep{display:none!important}
html body #omg-hdr-locale{background:transparent!important;border:0!important;height:48px;padding:0 6px!important;font-size:15px!important;font-weight:600}
html body #omg-hdr-locale:hover{color:#c4b5fd!important}
html body #omg-hdr-cart{position:relative;width:48px!important;height:48px!important;padding:0!important;display:grid!important;place-items:center;border-radius:999px}
html body #omg-hdr-cart:hover{background:#1b1828!important}
html body #omg-hdr-cart>span:not(#omg-cart-count){position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
html body #omg-hdr-cart::before{transform:scale(1.25)}
html body #omg-cart-count{position:absolute!important;top:2px!important;right:0!important;left:auto!important;min-width:22px;height:22px;padding:0 6px;border-radius:999px;display:grid!important;place-items:center;font:700 12px/1 Poppins,system-ui,sans-serif!important;color:#fff!important;background:linear-gradient(135deg,#7c3aed,#e0115f)!important;box-shadow:0 0 0 3px #06050b;width:auto!important}
html body #omg-cart-count:empty,html body #omg-cart-count[data-count="0"]{display:none!important}
html body #omg-hdr-login{height:54px!important;padding:0 34px!important;border-radius:999px!important;border:0!important;background:linear-gradient(110deg,#7c3aed,#c026d3 60%,#e0115f)!important;color:#fff!important;font:700 17px/1 Poppins,system-ui,sans-serif!important;box-shadow:0 10px 26px -12px rgba(192,38,211,.9)!important}
html body #omg-hdr-login:hover{filter:brightness(1.08)}
html body #omg-hdr-login svg,html body #omg-hdr-login i{display:none!important}
/* Platform row */
html body #omg-hdr-nav{max-width:1320px!important;margin:0 auto!important;padding:12px 0 10px!important;gap:6px 34px!important;border:0!important;border-top:1px solid #2a2540!important;background:transparent!important;display:flex!important;align-items:center!important;flex-wrap:nowrap!important;overflow-x:auto;scrollbar-width:none;box-sizing:border-box}
html body #omg-hdr-nav::-webkit-scrollbar{display:none}
html body .omg-pnav{display:inline-flex!important;align-items:center;gap:10px;flex:none;height:40px;color:#f4f2fb!important;font:600 16px/1 Poppins,system-ui,sans-serif!important;text-decoration:none!important;white-space:nowrap;opacity:.92;transition:opacity .15s,color .15s}
html body .omg-pnav:hover{opacity:1;color:#c4b5fd!important}
html body .omg-pnav-ic{position:relative!important;inset:auto!important;top:auto!important;left:auto!important;right:auto!important;width:26px!important;height:26px!important;min-width:26px;border-radius:50%!important;margin:0!important;flex:none;box-shadow:none!important;border:0!important;background-size:cover;display:inline-block!important;transform:none!important}
html body .omg-pnav-ic>span{display:none!important}
html body .omg-pnav-ic.is-gift,html body .omg-pnav-ic.is-all{background:#1b1828!important;display:inline-grid!important;place-items:center}
html body .omg-pnav-ic.is-gift::before,html body .omg-pnav-ic.is-all::before{content:"";width:15px;height:15px;background:#c4b5fd;-webkit-mask:var(--ic) center/contain no-repeat;mask:var(--ic) center/contain no-repeat}
html body .omg-pnav-ic.is-gift{--ic:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='8' width='18' height='13' rx='2'/%3E%3Cpath d='M12 8v13M3 12h18M12 8S10 3 7.5 4 9 8 12 8Zm0 0s2-5 4.5-4S15 8 12 8Z'/%3E%3C/svg%3E")}
html body .omg-pnav-ic.is-all{--ic:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='black'%3E%3Crect x='3' y='3' width='8' height='8' rx='2'/%3E%3Crect x='13' y='3' width='8' height='8' rx='2'/%3E%3Crect x='3' y='13' width='8' height='8' rx='2'/%3E%3Crect x='13' y='13' width='8' height='8' rx='2'/%3E%3C/svg%3E")}
html body .omg-pnav.is-deals{margin-left:auto;color:#fbbf24!important}
html body .omg-pnav.is-deals .omg-pnav-ic{display:none!important}
/* Tablet and phone */
@media (max-width:1024px){
  html body #omg-hdr{padding:10px 12px 0!important}
  html body #omg-hdr-top{flex-wrap:wrap!important;padding:10px 0!important;gap:12px!important;min-height:0}
  html body #omg-logo{order:1}
  html body #omg-hdr-actions{order:2;margin-left:auto;gap:8px!important}
  html body #omg-hdr-search{order:3;flex:1 1 100%!important}
  html body #omg-hdr-search input{height:48px!important}
  html body #omg-hdr-login{height:44px!important;padding:0 18px!important;font-size:15px!important}
  html body #omg-hdr-nav{padding:8px 0 6px!important;gap:6px 22px!important}
  html body .omg-pnav{font-size:14px!important;height:36px}
  html body .omg-pnav.is-deals{margin-left:0}
}
@media (max-width:480px){html body #omg-hdr-locale{display:none!important}html body #omg-logo .omg-brand-img{height:30px!important}}
/* details */
/* Not sticky: the header scrolls away with the page on every screen size. */
html body #brx-header,html body #brx-header.sticky,html body #brx-header.brx-sticky,html body #brx-header.scrolling{position:relative!important;top:auto!important;transform:none!important;box-shadow:none!important}
html body #omg-hdr{padding:6px 24px 0!important}
@media (max-width:1024px){html body #omg-hdr{padding:6px 16px 0!important}}
html body #omg-hdr-search::before,html body #omg-hdr-search>div::before,html body #omg-hdr-search form::before{display:none!important;content:none!important}
html body #omg-hdr-top{height:auto!important;max-height:none!important}
html body #omg-hdr-cart::before{background-color:transparent!important;filter:brightness(0) invert(1)}
html body #omg-hdr-search form>span{display:none!important}
html body #omg-hdr-search input{background-image:none!important}
html body #omg-hdr-nav .omg-pnav{color:#f4f2fb!important}
html body #omg-hdr-nav .omg-pnav:hover{color:#c4b5fd!important}
html body #omg-hdr-nav .omg-pnav.is-deals{color:#fbbf24!important}
html body .omg-pnav-ic[data-pf="xbox-one"]{background:#107c10!important;display:inline-grid!important;place-items:center}
html body .omg-pnav-ic[data-pf="xbox-one"]::before{content:"";width:16px;height:16px;background:#fff;-webkit-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='3.2' stroke-linecap='round'%3E%3Cpath d='M5 4c4 3 10 13 14 16M19 4C15 7 9 17 5 20'/%3E%3C/svg%3E") center/contain no-repeat;mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='3.2' stroke-linecap='round'%3E%3Cpath d='M5 4c4 3 10 13 14 16M19 4C15 7 9 17 5 20'/%3E%3C/svg%3E") center/contain no-repeat}
<?php
        echo "</style>\n";
    }

    /** If the header was not rendered through Bricks' filter, swap the row in the browser. */
    public static function fallback(): void
    {
        ?>
<script id="omg-header-js">(function(){var n=document.getElementById('omg-hdr-nav');if(n&&!n.querySelector('.omg-pnav')){var t=document.createElement('template');t.innerHTML=<?php echo wp_json_encode(self::nav()); ?>;n.replaceWith(t.content.firstElementChild);}var c=document.getElementById('omg-cart-count');function s(){if(c)c.setAttribute('data-count',(c.textContent||'').trim()||'0');}s();if(c&&window.MutationObserver)new MutationObserver(s).observe(c,{childList:true,characterData:true,subtree:true});})();</script>
        <?php
    }
}

OMG_Header::init();
