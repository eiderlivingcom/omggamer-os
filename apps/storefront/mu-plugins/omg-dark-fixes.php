<?php
/**
 * Plugin Name: OMGGamer dark scheme fixes
 * Description: Second pass on the dark scheme. Re-maps the light theme's dark text colours inside the Bricks styles
 *              (so text that only shows in tabs, drawers and popups is readable too), payment method tiles, the
 *              buy box discount ribbon, edition cards with a clear selected state, and a dark region dropdown.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Dark_Fixes
{
    /** Light-theme text colours => dark-theme equivalents (only for the CSS `color` property). */
    private const TEXT = [
        '0d0619' => '#f4f2fb',
        '1a1330' => '#f4f2fb',
        '2e2843' => '#d9d6e8',
        '4a4560' => '#a29fb6',
    ];

    public static function init(): void
    {
        add_action('template_redirect', [__CLASS__, 'buffer'], 1);
        add_action('wp_head', [__CLASS__, 'css'], 99);
        add_action('wp_footer', [__CLASS__, 'js'], 70);
    }

    private static function front(): bool
    {
        return !is_admin() && !wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST)
            && !(function_exists('bricks_is_builder') && bricks_is_builder()) && !is_feed();
    }

    public static function buffer(): void
    {
        if (self::front()) {
            ob_start([__CLASS__, 'filter']);
        }
    }

    public static function filter($html)
    {
        if (!is_string($html) || stripos($html, '<html') === false) {
            return $html;
        }
        // 1. Dark text colours inside the Bricks-generated styles.
        $out = preg_replace_callback('~(<style\b[^>]*\bid=["\']bricks-[^"\']*["\'][^>]*>)(.*?)(</style>)~is', static function ($m) {
            $css = preg_replace_callback('~(?<![-\w])color\s*:\s*#([0-9a-f]{6})\b~i', static function ($c) {
                $k = strtolower($c[1]);
                return isset(self::TEXT[$k]) ? 'color:' . self::TEXT[$k] : $c[0];
            }, $m[2]);
            return $m[1] . (is_string($css) ? $css : $m[2]) . $m[3];
        }, $html);
        $html = is_string($out) ? $out : $html;
        // 2. Payment method rows become logo tiles.
        $out = preg_replace_callback('~<div\b([^>]*)>((?:\s*<span>[^<]{1,24}</span>){3,8})\s*~i', static function ($m) {
            $a = $m[1];
            if (!preg_match('~\bomgd-pay\b|\bomg-cart-pay\b|id="omg-dg-pay"~', $a)) {
                return $m[0];
            }
            $a = preg_match('~\bclass="~', $a) ? preg_replace('~\bclass="~', 'class="omg-paytiles ', $a, 1) : $a . ' class="omg-paytiles"';
            return '<div' . $a . ' aria-label="Payment methods">' . self::tiles();
        }, $html);
        return is_string($out) ? $out : $html;
    }

    public static function tiles(): string
    {
        $t = static fn ($label, $svg) => '<span class="omg-pt" role="img" aria-label="' . $label . '" title="' . $label . '">' . $svg . '</span>';
        return $t('Apple Pay', '<svg viewBox="0 0 44 18" width="40" height="17" aria-hidden="true"><path fill="#fff" d="M11.6 9.6c0-1.8 1.5-2.7 1.6-2.8-.9-1.3-2.2-1.4-2.7-1.5-1.1-.1-2.2.7-2.8.7-.6 0-1.5-.7-2.4-.7-1.3 0-2.4.7-3.1 1.9-1.3 2.3-.3 5.6 1 7.4.6.9 1.3 1.9 2.3 1.9.9 0 1.3-.6 2.4-.6s1.4.6 2.4.6c1 0 1.6-.9 2.2-1.8.7-1 1-2 1-2.1 0 0-1.9-.7-1.9-3zM9.8 4.1c.5-.6.8-1.4.7-2.3-.7 0-1.6.5-2.1 1.1-.5.5-.9 1.4-.8 2.2.8.1 1.7-.4 2.2-1z"/><text x="16" y="14" fill="#fff" font-family="-apple-system,BlinkMacSystemFont,Poppins,Arial,sans-serif" font-size="13" font-weight="600">Pay</text></svg>')
            . $t('Google Pay', '<svg viewBox="0 0 46 18" width="42" height="17" aria-hidden="true"><g transform="translate(0 1) scale(.333)"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 13 4 4 13 4 24s9 20 20 20 20-9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></g><text x="19" y="14" fill="#fff" font-family="Poppins,Arial,sans-serif" font-size="13" font-weight="500">Pay</text></svg>')
            . $t('Visa', '<svg viewBox="0 0 44 16" width="38" height="14" aria-hidden="true"><text x="22" y="13.5" text-anchor="middle" fill="#fff" font-family="Arial Black,Arial,sans-serif" font-size="15" font-style="italic" font-weight="900" letter-spacing=".5">VISA</text></svg>')
            . $t('Mastercard', '<svg viewBox="0 0 34 22" width="32" height="21" aria-hidden="true"><circle cx="12" cy="11" r="9" fill="#EB001B"/><circle cx="22" cy="11" r="9" fill="#F79E1B"/><path fill="#FF5F00" d="M17 3.5a9 9 0 0 1 0 15 9 9 0 0 1 0-15z"/></svg>')
            . $t('American Express', '<svg viewBox="0 0 48 16" width="42" height="14" aria-hidden="true"><text x="24" y="13" text-anchor="middle" fill="#3B8BEB" stroke="#cfe3ff" stroke-width=".6" paint-order="stroke" font-family="Arial Black,Arial,sans-serif" font-size="14" font-style="italic" font-weight="900" letter-spacing=".3">AMEX</text></svg>');
    }

    public static function css(): void
    {
        if (!self::front()) {
            return;
        }
        if ((function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout())) {
            echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" id="omg-poppins-css" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">' . "\n";
        }
        ?>
<style id="omg-dark-fixes-css">
/* Rich text from suppliers (descriptions, activation steps): readable on dark. */
html body #omg-dg .omg-pp-rich,html body #omg-dg .omg-pp-rich :is(p,li,span,em,td,th,div,blockquote),html body #omg-dg #omg-dg-desc :is(p,li,span,em,td,th),html body .brxe-post-content :is(p,li,td,th){color:#d9d6e8!important}
html body #omg-dg .omg-pp-rich :is(h1,h2,h3,h4,h5,h6,strong,b),html body #omg-dg #omg-dg-desc :is(h1,h2,h3,h4,h5,h6,strong,b){color:#f4f2fb!important}
html body #omg-dg .omg-pp-rich a,html body #omg-dg #omg-dg-desc a{color:#b79cff!important}
html body #omg-dg #omg-dg-tabs .tab-title{color:#a29fb6!important}
html body #omg-dg #omg-dg-tabs .tab-title.brx-open,html body #omg-dg #omg-dg-tabs .tab-title:hover{color:#f4f2fb!important}
html body #omg-dg-note-text,html body #omg-dg-note p,html body #omg-dg-note-text strong{color:#a29fb6!important}
html body #omg-dg-note-text strong{color:#f4f2fb!important}
/* White text on coloured chips and pills. */
html:root body #brx-content .omg-chip-sale.omg-chip-sale,html:root body .omg-chip-sale.omg-chip-sale.omg-chip-sale.omg-chip-sale,html:root body #brx-content #omg-dg-disc,html:root body #omg-dg-disc,html:root body #brx-content .omg-pill-grad.omg-pill-grad,html:root body .omg-pill-grad.omg-pill-grad.omg-pill-grad.omg-pill-grad,html:root body #brx-content .omg-ac-pro-p.omg-ac-pro-p,html:root body .omg-ac-pro-p.omg-ac-pro-p.omg-ac-pro-p.omg-ac-pro-p,html:root body #brx-content .omg-of-tag.omg-of-tag,html:root body .omg-of-tag.omg-of-tag.omg-of-tag.omg-of-tag,html:root body #brx-content .omg-pro-pill.omg-pro-pill,html:root body .omg-pro-pill.omg-pro-pill.omg-pro-pill.omg-pro-pill{color:#fff!important}
html:root body #brx-content .omg-chip-glass.omg-chip-glass,html:root body .omg-chip-glass.omg-chip-glass.omg-chip-glass.omg-chip-glass{color:#fff!important;background:rgba(255,255,255,.18)!important}
html body #omg-hdr-locale .omg-dot{opacity:1!important;color:#77738c!important}
html body .omg-mb-tag>b{color:#c4b5fd!important}
html body .omgx-steps .is-next .n,html body .omgx-steps .is-next .l,html body .is-next>span.n,html body .is-next>span.l{color:#8f8aa8!important}
/* Gradient headline words keep their gradient. */
html body .omg-pro-h1>span,html body .omg-404-code,html body .omg-grad-text{color:transparent!important;-webkit-text-fill-color:transparent!important}
/* Home: accessories promo was a light card. */
html body #omg-promo-acc{background:linear-gradient(135deg,#3a0f2f 0%,#2a1446 100%)!important;border:1px solid #3b2a52}
html body #omg-promo-acc *{color:#f4f2fb!important}
html body #omg-promo-acc .brxe-text-basic:first-child{color:#f9a8d4!important}

/* Buy box discount: green ribbon tab that hangs over the panel edge with a folded corner. */
html body #omg-dg #omg-dg-buy .omg-bb .omg-bb-off:not(:empty),html body .omg-bb .omg-bb-opt .omg-bb-off:not(:empty){background:linear-gradient(120deg,#7C3AED,#C026D3 52%,#E0115F)!important;color:#fff!important;font-weight:700!important;box-shadow:0 6px 14px -4px rgba(192,38,211,.45)!important;border-radius:10px 10px 0 10px!important;padding:6px 12px!important;right:-28px!important;overflow:visible!important}
html body #omg-dg #omg-dg-buy .omg-bb .omg-bb-off:not(:empty)::after,html body .omg-bb .omg-bb-opt .omg-bb-off:not(:empty)::after{content:"";position:absolute;right:0;top:100%;width:0;height:0;border-left:8px solid #7a0f3d;border-bottom:8px solid transparent}
html body #omg-dg #omg-dg-buy .omg-bb .omg-bb-opts:has(.omg-bb-opt-pro[hidden]) .omg-bb-off:not(:empty){position:relative!important;right:auto!important;margin-right:-28px!important}

/* Language/feature chips: the extra ones shown by "+N more" line up with the rest. */
html body .omg-dg-chips.is-open .omg-dg-chip.is-extra{display:flex!important;align-items:center}
html body .omg-dg-chips .omg-dg-chip>.brxe-text-basic{display:block!important;line-height:1.2!important}
/* Price history: stat cards and chart on dark. */
html body #omg-dg .omg-ph-stat,html body .omg-ph-stat{background:#1e1a2e!important;border:1px solid #2a2540!important}
html body .omg-ph-stat.is-now{background:rgba(139,92,246,.12)!important;border-color:rgba(139,92,246,.4)!important}
html body .omg-ph-stat.is-low{background:rgba(16,185,129,.10)!important;border-color:rgba(16,185,129,.35)!important}
html body .omg-ph-stat>span{color:#a29fb6!important}
html body .omg-ph-stat.is-now>span{color:#c4b5fd!important}
html body .omg-ph-stat.is-low>span{color:#6ee7b7!important}
html body .omg-ph-stat strong{color:#f4f2fb!important}
html body .omg-ph-chart{background-image:linear-gradient(#2a2540 1px,transparent 1px)!important}
html body .omg-ph-axis{border-top-color:#2a2540!important}
html body #omg-dg .omg-seg{background:#1e1a2e!important;border:1px solid #2a2540!important}
html body #omg-dg .omg-seg button{background:transparent!important;color:#a29fb6!important}
html body #omg-dg .omg-seg button.is-on{background:#2a2540!important;color:#f4f2fb!important}

/* Cart page (block cart): dark cards, readable text, aligned prices. */
html body .omg-cart-free{background:#171424!important;border:1px solid #2a2540!important;box-shadow:none!important}
html body .omg-cart-free *{color:#d9d6e8}
html body .omg-cart-free b,html body .omg-cart-free strong{color:#f4f2fb!important}
html body .omg-cart-bar{background:#2a2540!important}
html body .wc-block-cart .wc-block-cart__main,html body .wc-block-cart .wc-block-components-main{background:#171424!important;border:1px solid #2a2540!important;box-shadow:none!important}
html body .wc-block-cart .wc-block-cart-items th,html body .wc-block-cart .wc-block-cart-items td{border-color:#2a2540!important}
html body .wc-block-cart .wc-block-cart-items__header span{color:#a29fb6!important}
html body .wc-block-cart .wc-block-cart__totals-title,html body .wc-block-cart .wc-block-components-panel__button,html body .wc-block-cart .wc-block-components-totals-item__label{color:#f4f2fb!important}
html body .wc-block-cart .wc-block-components-totals-wrapper,html body .wc-block-cart .wc-block-components-panel{border-color:#2a2540!important}
html body .wc-block-cart .wc-block-components-product-name{color:#f4f2fb!important}
html body .wc-block-cart .wc-block-components-product-metadata,html body .wc-block-cart .wc-block-components-product-metadata *{color:#a29fb6!important}
html body .wc-block-cart .wc-block-cart-item__prices del,html body .wc-block-cart .wc-block-cart-item__prices del *{color:#77738c!important}
html body .wc-block-cart .wc-block-cart-item__prices ins,html body .wc-block-cart .wc-block-cart-item__prices ins *{color:#f4f2fb!important;text-decoration:none}
html body .wc-block-cart .wc-block-components-sale-badge{background:rgba(16,185,129,.14)!important;border:1px solid rgba(16,185,129,.35)!important;color:#6ee7b7!important}
html body .wc-block-cart .wc-block-components-sale-badge *{color:#6ee7b7!important}
html body .wc-block-cart .wc-block-cart-item__product{padding-left:16px!important}
html body .wc-block-cart .wc-block-cart-item__total{text-align:right!important;vertical-align:top}
html body .wc-block-cart .wc-block-cart-item__total-price-and-sale-badge-wrapper{display:flex!important;flex-direction:column;align-items:flex-end;gap:6px}
html body .wc-block-cart .wc-block-components-formatted-money-amount,html body .wc-block-cart .wc-block-components-totals-item__value{font-family:Poppins,system-ui,sans-serif!important;font-variant-numeric:tabular-nums}
html body .wc-block-cart,html body .wc-block-cart *:not(.star-rating):not([class*="icon"]){font-family:Poppins,system-ui,sans-serif}

/* Payment method tiles. */
html body .omg-paytiles{display:flex!important;flex-wrap:wrap;gap:8px;align-items:center}
html body .omg-paytiles>.omg-pt{display:inline-flex!important;align-items:center;justify-content:center;width:58px;height:38px;padding:0!important;margin:0!important;border-radius:9px!important;background:#1f1c2c!important;border:1px solid #2f2a45!important;box-shadow:none!important;font-size:0!important;line-height:0}
html body .omg-paytiles>.omg-pt svg{display:block;max-width:44px;height:auto}
@media (max-width:420px){html body .omg-paytiles{gap:6px}html body .omg-paytiles>.omg-pt{width:52px;height:34px}}

/* Edition cards: name + radio, subtitle, then a darker price strip; selected = violet ring. */
html body #omg-dg-ed-cards .omg-ed-card{display:grid!important;grid-template-columns:1fr auto;grid-template-areas:"n r" "s s" "p p";align-content:start;gap:0!important;padding:0!important;overflow:hidden;text-align:left;background:#1e1a2e!important;border:1px solid #2a2540!important;border-radius:14px!important;cursor:pointer;transition:border-color .15s,box-shadow .15s}
html body #omg-dg-ed-cards .omg-ed-card:hover{border-color:#4b3f78!important}
html body #omg-dg-ed-cards .omg-ed-name{grid-area:n;padding:14px 8px 0 16px!important;margin:0!important;color:#f4f2fb!important;font-weight:600!important}
html body #omg-dg-ed-cards .omg-ed-card>.omg-radio{grid-area:r;align-self:start;margin:14px 14px 0 0!important;width:20px!important;height:20px!important;border-radius:50%!important;border:2px solid #4a4560!important;background:transparent!important;box-sizing:border-box;position:relative!important;inset:auto!important}
html body #omg-dg-ed-cards .omg-ed-card>.omg-radio::before,html body #omg-dg-ed-cards .omg-ed-card>.omg-radio::after{display:none!important}
html body #omg-dg-ed-cards .omg-ed-sub{grid-area:s;padding:2px 16px 12px!important;margin:0!important;color:#a29fb6!important}
html body #omg-dg-ed-cards .omg-ed-price{grid-area:p;align-self:end;padding:10px 16px!important;margin:0!important;background:#13111e!important;border-top:1px solid #2a2540;color:#a29fb6!important}
html body #omg-dg-ed-cards .omg-ed-card.is-active{border-color:#8b5cf6!important;box-shadow:0 0 0 1px #8b5cf6,0 10px 28px -14px rgba(139,92,246,.7)!important}
html body #omg-dg-ed-cards .omg-ed-card.is-active>.omg-radio{border-color:#a78bfa!important;background:radial-gradient(circle,#a78bfa 0 4.5px,transparent 5.5px)!important}
html body #omg-dg-ed-cards .omg-ed-card.is-active .omg-ed-price{color:#f4f2fb!important;background:#16122a!important;border-top-color:#3b2f66}

/* Region dropdown. */
html body #omg-dg-region.omg-dd-on{position:relative}
html body #omg-dg-region.omg-dd-on::after,html body #omg-dg-region.omg-dd-on::before{display:none!important}
html body #omg-dg-region.omg-dd-on select{position:absolute!important;inset:0;opacity:0!important;pointer-events:none!important;width:100%!important}
.omg-dd-btn{display:inline-flex;align-items:center;gap:10px;min-width:180px;height:40px;padding:0 12px 0 14px;border-radius:12px;background:#1e1a2e;border:1px solid #2a2540;color:#f4f2fb;font:500 14px/1 Poppins,system-ui,sans-serif;cursor:pointer;transition:border-color .15s,box-shadow .15s}
.omg-dd-btn:hover{border-color:#4b3f78}
.omg-dd-btn[aria-expanded=true],.omg-dd-btn:focus-visible{border-color:#8b5cf6;box-shadow:0 0 0 3px rgba(139,92,246,.25);outline:none}
.omg-dd-btn .g{flex:none;color:#a29fb6}
.omg-dd-btn .v{flex:1 1 auto;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.omg-dd-btn .c{flex:none;color:#a29fb6;transition:transform .15s}
.omg-dd-btn[aria-expanded=true] .c{transform:rotate(180deg)}
.omg-dd-list{position:absolute;z-index:60;top:calc(100% + 8px);left:0;min-width:100%;max-height:280px;overflow:auto;margin:0;padding:6px;list-style:none;background:#1e1a2e;border:1px solid #2a2540;border-radius:14px;box-shadow:0 24px 48px -16px rgba(0,0,0,.7)}
.omg-dd-list[hidden]{display:none}
.omg-dd-list li{margin:0;padding:10px 12px;border-radius:9px;color:#d9d6e8;font:500 14px/1.2 Poppins,system-ui,sans-serif;cursor:pointer;white-space:nowrap}
.omg-dd-list li:hover,.omg-dd-list li.is-hl{background:#2a2540;color:#f4f2fb}
.omg-dd-list li[aria-selected=true]{background:rgba(139,92,246,.18);color:#f4f2fb;font-weight:600}
.omg-dd-list li[aria-disabled=true]{opacity:.45;cursor:not-allowed}
</style>
        <?php
    }

    public static function js(): void
    {
        if (!self::front()) {
            return;
        }
        $money = function_exists('get_woocommerce_currency_symbol') ? [
            'sym' => html_entity_decode(get_woocommerce_currency_symbol()),
            'fmt' => get_woocommerce_price_format(),
            'dec' => wc_get_price_decimals(),
            'ds' => wc_get_price_decimal_separator(),
            'ts' => wc_get_price_thousand_separator(),
        ] : null;
        ?>
<script id="omg-dark-fixes-js">
(function(){
  var M = <?php echo wp_json_encode($money); ?>;
  function money(v){
    if (!M) return String(v);
    var n = Number(v).toFixed(M.dec).split('.'); n[0] = n[0].replace(/\B(?=(\d{3})+(?!\d))/g, M.ts);
    return M.fmt.replace('%1$s', M.sym).replace('%2$s', n.join(M.ds)).replace(/&nbsp;/g, ' ');
  }
  /* Products without editions (gift cards: region + amount): region dropdown and one card per amount. */
  function optionCards(){
    var ed = document.getElementById('omg-dg-ed'), wrap = document.getElementById('omg-dg-ed-cards'), holder = document.getElementById('omg-dg-region');
    var form = document.querySelector('form.variations_form');
    if (!ed || !wrap || !form || wrap.querySelector('.omg-ed-card')) return;
    var vars = []; try { vars = JSON.parse(form.getAttribute('data-product_variations') || '[]'); } catch (e) {}
    if (!vars || !vars.length) return;
    var sels = Array.prototype.slice.call(form.querySelectorAll('select[name^="attribute_"]'));
    var rg = sels.filter(function(x){ return /region/.test(x.name); })[0] || null;
    var opt = sels.filter(function(x){ return x !== rg; })[0] || null;
    var set = function(sel, v){ if (sel && sel.value !== v) { sel.value = v; if (window.jQuery) jQuery(sel).trigger('change'); else sel.dispatchEvent(new Event('change', {bubbles: true})); } };
    var labelEl = document.getElementById('omg-dg-ed-label');
    if (rg && holder && !holder.querySelector('select')) {
      if (labelEl) labelEl.textContent = 'Region';
      var rs = document.createElement('select'); rs.setAttribute('aria-label', 'Region');
      Array.prototype.forEach.call(rg.options, function(o){ if (o.value) rs.appendChild(new Option(o.textContent, o.value, false, o.value === rg.value)); });
      holder.appendChild(rs);
      if (!rg.value && rs.value) set(rg, rs.value);
      rs.addEventListener('change', function(){ set(rg, rs.value); price(); });
    }
    if (!opt) return;
    var lab = form.querySelector('label[for="' + opt.id + '"]'); var attrName = lab ? lab.textContent.trim() : '';
    var title = (document.querySelector('h1') || {}).textContent || '';
    var unitM = title.match(/\d[\d,.]*\s+([A-Za-z][A-Za-z-]+)/); var unit = unitM ? unitM[1] : '';
    var cards = [];
    Array.prototype.forEach.call(opt.options, function(o){
      if (!o.value) return;
      var b = document.createElement('button'); b.type = 'button'; b.className = 'omg-ed-card'; b.setAttribute('data-value', o.value);
      b.innerHTML = '<span class="omg-ed-name"></span><span class="omg-radio"></span><span class="omg-ed-sub"></span><span class="omg-ed-price"></span>';
      b.querySelector('.omg-ed-name').textContent = o.textContent.trim() + (unit && !/[A-Za-z]/.test(o.textContent) ? ' ' + unit : '');
      b.querySelector('.omg-ed-sub').textContent = attrName || 'Option';
      b.addEventListener('click', function(){ if (!b.classList.contains('is-disabled')) { set(opt, o.value); mark(); } });
      wrap.appendChild(b); cards.push(b);
    });
    function price(){
      var r = rg ? rg.value : '';
      cards.forEach(function(c){
        var v = c.getAttribute('data-value'), min = null;
        vars.forEach(function(x){ var a = x.attributes || {}; if ((!a[opt.name] || a[opt.name] === v) && (!rg || !r || !a[rg.name] || a[rg.name] === r) && x.is_in_stock && x.is_purchasable !== false) min = min === null ? x.display_price : Math.min(min, x.display_price); });
        c.querySelector('.omg-ed-price').textContent = min === null ? 'Unavailable' : 'from ' + money(min);
        c.classList.toggle('is-disabled', min === null);
      });
    }
    function mark(){ cards.forEach(function(c){ var on = c.getAttribute('data-value') === opt.value; c.classList.toggle('is-active', on); c.setAttribute('aria-pressed', on ? 'true' : 'false'); }); }
    if (!opt.value) {
      var pick = cards.filter(function(c){ return new RegExp('(^|\\D)' + c.getAttribute('data-value') + '(\\D|$)').test(title); })[0] || cards[0];
      if (pick) set(opt, pick.getAttribute('data-value'));
    }
    opt.addEventListener('change', mark);
    price(); mark();
  }
  var globe = '<svg class="g" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>';
  var chev = '<svg class="c" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
  function dropdown(wrap){
    var sel = wrap.querySelector('select'); if (!sel || wrap.dataset.omgDd) return; wrap.dataset.omgDd = '1';
    wrap.classList.add('omg-dd-on'); sel.tabIndex = -1; sel.setAttribute('aria-hidden', 'true');
    var id = 'omg-dd-' + Math.random().toString(36).slice(2, 8);
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'omg-dd-btn'; btn.setAttribute('aria-haspopup', 'listbox'); btn.setAttribute('aria-expanded', 'false'); btn.setAttribute('aria-controls', id);
    var lab = document.getElementById('omg-dg-ed-label'); if (lab) { lab.id = lab.id || 'omg-dg-ed-label'; btn.setAttribute('aria-labelledby', lab.id + ' ' + id + '-v'); }
    var list = document.createElement('ul'); list.className = 'omg-dd-list'; list.id = id; list.setAttribute('role', 'listbox'); list.hidden = true;
    wrap.appendChild(btn); wrap.appendChild(list);
    var hl = -1;
    function opts(){ return Array.prototype.slice.call(sel.options).filter(function(o){ return o.value !== '' || sel.options.length === 1; }); }
    function render(){
      var o = sel.options[sel.selectedIndex];
      btn.innerHTML = globe + '<span class="v" id="' + id + '-v"></span>' + chev;
      btn.querySelector('.v').textContent = o ? o.text : 'Choose';
      list.innerHTML = '';
      opts().forEach(function(op, i){
        var li = document.createElement('li'); li.setAttribute('role', 'option'); li.textContent = op.text; li.dataset.v = op.value;
        li.setAttribute('aria-selected', op.selected ? 'true' : 'false'); if (op.disabled) li.setAttribute('aria-disabled', 'true');
        li.addEventListener('click', function(e){ e.stopPropagation(); pick(op.value); });
        li.addEventListener('mousemove', function(){ setHl(i); });
        list.appendChild(li);
      });
    }
    function setHl(i){ var lis = list.children; for (var k = 0; k < lis.length; k++) lis[k].classList.toggle('is-hl', k === i); hl = i; if (lis[i]) lis[i].scrollIntoView({block:'nearest'}); }
    function open(){ render(); list.hidden = false; btn.setAttribute('aria-expanded', 'true'); var lis = list.children, s = 0; for (var k = 0; k < lis.length; k++) if (lis[k].getAttribute('aria-selected') === 'true') s = k; setHl(s); }
    function close(){ list.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
    function pick(v){ close(); if (sel.value !== v) { sel.value = v; sel.dispatchEvent(new Event('change', {bubbles:true})); if (window.jQuery) jQuery(sel).trigger('change'); } render(); btn.focus(); }
    btn.addEventListener('click', function(e){ e.stopPropagation(); list.hidden ? open() : close(); });
    btn.addEventListener('keydown', function(e){
      var n = list.children.length;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (list.hidden) { open(); return; } setHl((hl + (e.key === 'ArrowDown' ? 1 : -1) + n) % n); }
      else if ((e.key === 'Enter' || e.key === ' ') && !list.hidden) { e.preventDefault(); var li = list.children[hl]; if (li && li.getAttribute('aria-disabled') !== 'true') pick(li.dataset.v); }
      else if (e.key === 'Escape' || e.key === 'Tab') close();
    });
    document.addEventListener('click', function(e){ if (!wrap.contains(e.target)) close(); });
    sel.addEventListener('change', render);
    if (window.MutationObserver) new MutationObserver(render).observe(sel, {childList:true, subtree:true, attributes:true});
    render();
  }
  function start(){
    try { optionCards(); } catch (e) {}
    document.querySelectorAll('#omg-dg-region').forEach(dropdown);
    // Gradient words whose fill was flattened to a solid colour.
    document.querySelectorAll('h1 span,h1 b,h2 span,h2 b,span > b,.omg-404-code').forEach(function(e){
      if (getComputedStyle(e).webkitBackgroundClip === 'text') { e.style.setProperty('-webkit-text-fill-color', 'transparent', 'important'); e.style.setProperty('color', 'transparent', 'important'); }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
</script>
        <?php
    }
}

OMG_Dark_Fixes::init();
