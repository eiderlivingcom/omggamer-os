<?php
/**
 * Plugin Name: OMGGamer dark scheme
 * Description: The checkout page's dark colours across the whole store (home, collections, product pages, cart,
 *              account, PRO, 404). Generated from an audit of the light colours in the templates, plus our components.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_head', static function (): void {
    if (is_admin() || (function_exists('bricks_is_builder') && bricks_is_builder())) {
        return;
    }
    echo "<meta name=\"color-scheme\" content=\"dark\">\n<style id=\"omg-dark-css\">\n";
    ?>
/* OMGGamer dark scheme: the checkout page's colours across the whole store. */
:root{--d-bg:#0b0a12;--d-bg2:#12101c;--d-card:#171424;--d-card2:#1e1a2e;--d-line:#2a2540;--d-ink:#f4f2fb;--d-mut:#a29fb6;--d-dim:#77738c;--d-violet:#b79cff;color-scheme:dark}
html body{background-color:var(--d-bg)!important;color:var(--d-ink)}
html body #brx-content,html body main{background-color:transparent}
html body .brxe-section:not([style*="background"]){background-color:transparent}
/* Logo: white on dark. */
html body .omg-brand-img{filter:brightness(0) invert(1)}
/* Header */
html body #omg-hdr{border-bottom-color:var(--d-line)!important;backdrop-filter:blur(10px)}
html body #omg-hdr .omg-nav-link,html body #omg-hdr-nav a{color:var(--d-mut)!important}
html body #omg-hdr .omg-nav-link:hover{color:var(--d-ink)!important}
html body #omg-hdr-nav{border-color:var(--d-line)!important}
html body #omg-hdr-locale{color:var(--d-ink)!important;border:1px solid var(--d-line)}
html body #omg-hdr-cart,html body #omg-hdr-cart *{color:var(--d-ink)!important}
html body #omg-hdr-login{background:var(--d-card2)!important;color:var(--d-ink)!important;border:1px solid var(--d-line)!important}
html body #omg-hdr-sep{background:var(--d-line)!important}
html body #omg-hdr-search input,html body .brxe-search input{background:var(--d-card2)!important;color:var(--d-ink)!important;border-color:var(--d-line)!important}
html body ::placeholder{color:var(--d-dim)!important;opacity:1}
/* Footer sits on the same background: a hairline separates it. */
html body #omg-ftr{border-top:1px solid var(--d-line)}
/* Form fields */
html body input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):not([type=range]),html body select,html body textarea{background-color:var(--d-card2)!important;color:var(--d-ink)!important;border-color:var(--d-line)!important}
html body select option{background:var(--d-card);color:var(--d-ink)}
/* Generic WooCommerce text */
html body .woocommerce-Price-amount,html body .price{color:var(--d-ink)}
html body .price del,html body del .woocommerce-Price-amount{color:var(--d-dim)!important}
html body .woocommerce-tabs ul.tabs li{background:var(--d-card2)!important;border-color:var(--d-line)!important}
/* Product cards + quick buy */
html body .omg-ac-card{border-color:var(--d-line)!important}
html body .omg-qb-host>.omg-ac-card .omg-ac-body{background:var(--d-card)!important}
html body .omg-qb .omg-qb-add{background:var(--d-card2)!important;color:var(--d-ink)!important;border-color:var(--d-line)!important}
html body .omg-qb .omg-qb-add:hover{background:#2b2542!important;color:#fff!important}
html body .omg-ac-old,html body .omg-ac-card del{color:var(--d-dim)!important}
/* Buy box */
html body #omg-dg .omg-bb-opt+.omg-bb-opt,html body #omg-dg #omg-dg-buy .omg-bb-two .omg-bb-opt:not(.is-active){border-top-color:var(--d-line)!important}
html body #omg-dg #omg-dg-buy .omg-bb-two .omg-bb-opt:not(.is-active):hover{background:var(--d-card2)!important}
html body .omg-bb-opt .omg-radio{background:var(--d-bg2)!important;border-color:#4a4466!important}
html body .omg-bb-opt.is-active .omg-radio{background:#8b5cf6!important;border-color:#8b5cf6!important;box-shadow:inset 0 0 0 4px var(--d-card)!important}
html body .omg-bb-tip,html body .omg-tip{background:#fff!important;color:#14112a!important}
html body .omg-ed-card.is-active,html body .omg-ed-card[aria-pressed=true],html body .omg-ed-card.is-on{border-color:#8b5cf6!important;background:rgba(139,92,246,.10)!important}
/* Mobile buy bar, currency menu */
html body .omg-mb{background:var(--d-card)!important;border-top-color:var(--d-line)!important;color:var(--d-ink)!important}
html body .omg-mb-seller{color:var(--d-mut)!important}html body .omg-mb-seller b{color:var(--d-ink)!important}
html body .omg-mb-opt{background:var(--d-card2)!important;border-color:var(--d-line)!important;color:var(--d-ink)!important}
html body .omg-mb-opt[aria-checked=true]{border-color:#8b5cf6!important;background:rgba(139,92,246,.12)!important}
html body .omg-mb-tag{background:var(--d-card)!important}
html body .omg-mb-opt[aria-checked=true] .omg-mb-tag{background:linear-gradient(var(--d-card) 55%,#221a3a 55%)!important}
html body .omg-mk-pop{background:var(--d-card)!important;border-color:var(--d-line)!important;color:var(--d-ink)!important}
html body .omg-mk-pop a:hover,html body .omg-mk-pop a[aria-current=true]{background:var(--d-card2)!important}
html body .omg-mk-pop a[aria-current=true]{color:var(--d-violet)!important}
/* Cart drawer */
html body .omgd-panel,html body .omgd-head,html body .omgd-foot,html body .omgd-recs{background:var(--d-card)!important;color:var(--d-ink)!important;border-color:var(--d-line)!important}
html body .omgd-panel *{border-color:var(--d-line)}
html body .omgd-head h2,html body .omgd-item .nm,html body .omgd-line b,html body .omgd-recs h3{color:var(--d-ink)!important}
html body .omgd-sub,html body .omgd-note,html body .omgd-line span{color:var(--d-mut)!important}
html body .omgd-x,html body .omgd-item .ctl,html body .omgd-item button,html body .omgd-pay span,html body .omgd-recs .list>*{background:var(--d-card2)!important;color:var(--d-ink)!important;border-color:var(--d-line)!important}
html body .omgd-free,html body .omgd-added{background:var(--d-card2)!important}
html body .omgd-view{color:var(--d-ink)!important}
html body .omgd-skel i{background:var(--d-card2)!important}
/* Cart page (block cart) on the checkout's dark page */
html body.omgx-cart .wc-block-cart,html body.omgx-cart .wc-block-cart *{border-color:var(--d-line)}
html body.omgx-cart .wc-block-cart-items__header,html body.omgx-cart .wc-block-cart-item__product a,html body.omgx-cart .wc-block-components-product-name,html body.omgx-cart .wc-block-components-totals-item__label,html body.omgx-cart .wc-block-components-totals-item__value,html body.omgx-cart .wc-block-formatted-money-amount{color:var(--d-ink)!important}
html body.omgx-cart .wc-block-components-product-metadata,html body.omgx-cart .wc-block-cart-item__remove-link{color:var(--d-mut)!important}
html body.omgx-cart .wc-block-components-quantity-selector{background:var(--d-card2)!important;border-color:var(--d-line)!important}
html body.omgx-cart .wc-block-components-quantity-selector input,html body.omgx-cart .wc-block-components-quantity-selector button{color:var(--d-ink)!important;background:transparent!important}
html body.omgx-cart .wc-block-components-sidebar,html body.omgx-cart .wp-block-woocommerce-cart-totals-block{background:var(--d-card)!important;border-radius:16px}
html body #omg-about,
html body #omg-accessories,
html body #omg-accounts,
html body #omg-ar,
html body #omg-best,
html body #omg-dg,
html body #omg-flash,
html body #omg-hardware,
html body #omg-hero,
html body #omg-pp,
html body #omg-promos,
html body #omg-trust,
html body section.brxe-section.omg-404,
html body section.brxe-section.omg-pro{background-color:var(--d-bg)!important}
html body #omg-code-card,
html body #omg-dg-buy,
html body #omg-dg-ed,
html body #omg-dg-gallery,
html body #omg-dg-hero,
html body #omg-dg-offers,
html body #omg-dg-tabs-slot,
html body #omg-dg-trailer,
html body #omg-pp-buy,
html body #omg-pp-gallery-slot,
html body #omg-pp-seller,
html body #omg-pp-tabs-slot,
html body #omg-trust-grid,
html body a.brxe-div.omg-ac-card,
html body a.brxe-div.omg-ar-chip,
html body a.next.page-numbers,
html body a.omg-ac-card,
html body a.omg-btn-light.bricks-button,
html body a.omg-pro-explore,
html body a.page-numbers,
html body aside.omgd-panel,
html body button.brxe-block.omg-ed-card,
html body button.brxe-text-basic.omg-cart-btn,
html body button.omg-rel-arrow,
html body button.omgd-x,
html body details.brxe-div.omg-ar-side,
html body div.bricks-posts-nothing-found,
html body div.brxe-block.omg-stat,
html body div.brxe-div.omg-ac-body,
html body div.brxe-div.omg-cart-trust,
html body div.brxe-text-basic.omg-cart-pay>span,
html body div.brxe-text-basic.omg-pro-faq>details,
html body div.brxe-text-basic.omg-pro-table,
html body div.omg-404-chips>a,
html body div.omg-ac-body,
html body div.omg-pro-benefit,
html body div.omg-pro-calc,
html body div.omg-pro-step,
html body div.wc-block-grid__product-onsale,
html body select.orderby,
html body span.brxe-text-basic.omg-radio,
html body span.omg-radio,
html body span.page-numbers.dots{background-color:var(--d-card)!important}
html body #omg-dg-note,
html body #omg-dg-of-feat,
html body #omg-dg-pills-custom,
html body #omg-dg-trust,
html body #omg-dg-warrantybox,
html body #omg-hdr-locale,
html body #omg-pp-region,
html body #omg-pp-trust,
html body a.omg-btn-soft.bricks-button,
html body a.reset-button,
html body button.single_add_to_cart_button.button,
html body div.bar,
html body div.brxe-block.omg-dg-chip,
html body div.brxe-div.omg-ac-pro,
html body div.brxe-text-basic.omg-pro-table>table>tbody>tr>td,
html body div.brxe-text-basic.omg-pro-table>table>thead>tr>th,
html body div.omg-bar,
html body div.omgd-free,
html body span.brxe-text-basic.omg-dg-count,
html body span.brxe-text-basic.omg-dg-pill,
html body span.omg-pro-ic{background-color:var(--d-card2)!important}
html body #omg-dg-fastbox{background-color:rgba(34,197,94,.12)!important}
html body #omg-dg-warning{background-color:rgba(245,158,11,.10)!important}
html body #omg-hdr{background-color:rgba(18,16,28,.92)!important}
html body #omg-code-card,
html body #omg-dg-buy,
html body #omg-dg-ed,
html body #omg-dg-gallery,
html body #omg-dg-hero,
html body #omg-dg-offers,
html body #omg-dg-ovgrid,
html body #omg-dg-pills-custom,
html body #omg-dg-tabs-slot,
html body #omg-dg-trailer,
html body #omg-dg-warning,
html body #omg-pp-buy,
html body #omg-pp-gallery-slot,
html body #omg-pp-seller,
html body #omg-pp-tabs-slot,
html body #omg-trust-grid,
html body #pa_quantity,
html body #pa_region,
html body a.brxe-div.omg-ac-card,
html body a.brxe-div.omg-ar-chip,
html body a.next.page-numbers,
html body a.omg-ac-card,
html body a.omg-pro-explore,
html body a.page-numbers,
html body button.brxe-block.omg-ed-card,
html body button.brxe-text-basic.omg-cart-btn,
html body button.omg-rel-arrow,
html body details.brxe-div.omg-ar-side,
html body div.brxe-block.omg-stat,
html body div.brxe-div.omg-cart-trust,
html body div.brxe-text-basic.omg-cart-pay>span,
html body div.brxe-text-basic.omg-pro-faq>details,
html body div.brxe-text-basic.omg-pro-table,
html body div.brxe-text-basic.omg-pro-table>table>tbody>tr>td,
html body div.omg-404-chips>a,
html body div.omg-bb-free,
html body div.omg-pro-benefit,
html body div.omg-pro-calc,
html body div.omg-pro-step,
html body div.woocommerce-tabs.wc-tabs-wrapper,
html body label.omg-bb-opt,
html body select.orderby,
html body span.action.minus,
html body span.action.plus,
html body span.brxe-text-basic.omg-dg-pill,
html body span.brxe-text-basic.omg-radio,
html body span.omg-radio,
html body span.page-numbers.dots,
html body table.variations>tbody>tr{border-color:var(--d-line)!important}
html body a.brxe-div.omg-pf,
html body a.omg-pf{border-color:var(--d-bg)!important}
html body #omg-code-title,
html body #omg-dg-ed-label,
html body #omg-dg-fastbox>span,
html body #omg-dg-incl>ul>li,
html body #omg-dg-of-price>strong,
html body #omg-dg-of-summary>strong,
html body #omg-dg-warrantybox>span,
html body #omg-hdr-locale,
html body #omg-pp-seller-name,
html body #omg-pp-seller-name>a,
html body #omgd-title,
html body #tab-description>h3,
html body #tab-description>p,
html body #tab-description>ul>li,
html body #tab-title-additional_information>a,
html body #tab-title-description>a,
html body #tab-title-reviews>a,
html body a.brxe-text-link.omg-nav-link,
html body a.next.page-numbers,
html body a.omg-auth-logo>span>b,
html body a.omg-btn-light.bricks-button,
html body a.omg-btn-soft.bricks-button,
html body a.page-numbers,
html body button.single_add_to_cart_button.button>span,
html body div.brxe-product-content>p,
html body div.brxe-product-content>p>strong,
html body div.brxe-text-basic,
html body div.brxe-text-basic.omg-404-cats>h2,
html body div.brxe-text-basic.omg-pro-faq>details>summary,
html body div.brxe-text-basic.omg-pro-table>table>tbody>tr>td,
html body div.omg-404-chips>a,
html body div.omg-404-code,
html body div.omg-pro-benefit>strong,
html body div.omg-pro-calc-v>span,
html body div.omg-pro-step>strong,
html body div.wc-block-grid__product-price.price>span,
html body h1.brxe-heading.omg-cart-title,
html body h1.brxe-product-title,
html body h1.omg-pro-h1>span,
html body h2.brxe-heading.omg-h2,
html body h2.brxe-heading.omg-pp-h2,
html body h2.omg-pro-h2,
html body h3.brxe-heading.omg-ac-title,
html body h3.omg-ac-title,
html body h6.title-tag,
html body p.brxe-text-basic.omg-dg-spec-v,
html body p.brxe-text-basic.omg-dg-spec-v>a,
html body p.brxe-text-basic.omg-pp-trust-a,
html body p.brxe-text-basic.omg-pp-trust-b,
html body p.brxe-text-basic.omg-stat-num,
html body p.brxe-text-basic.omg-trust-t,
html body span.brxe-text-basic.omg-ac-pro-t>strong,
html body span.brxe-text-basic.omg-ed-name,
html body span.brxe-text-basic.omg-ed-price,
html body span.brxe-text-basic.omg-promo-kicker,
html body span.brxe-text-basic.omg-promo-link,
html body span.brxe-text-basic.omg-promo-text,
html body span.brxe-text-basic.omg-promo-title,
html body span.navigation,
html body span.navigation>a,
html body span.omg-bb-big,
html body span.page-numbers.dots,
html body span.whitespace-normal,
html body span.woocommerce-Price-amount.amount,
html body span.woocommerce-Price-amount.amount>bdi,
html body span.woocommerce-Price-currencySymbol,
html body strong.brxe-text-basic,
html body strong.omg-stat-avg,
html body summary.brxe-text-basic.omg-ar-side-h{color:var(--d-ink)!important}
html body #omg-code-text,
html body #omg-code-text>strong,
html body #omg-dg-help,
html body #omg-dg-of-pro,
html body #omg-dg-pills-custom,
html body #omg-dg-share>span,
html body div.bricks-posts-nothing-found,
html body div.brxe-text-basic.omg-cart-pay>span,
html body div.brxe-text-basic.omg-pro-faq>details>p,
html body div.brxe-text>p,
html body div.brxe-text>p>strong,
html body div.omg-bb-free-row>span>span,
html body div.omg-pro-benefit>span,
html body div.omg-pro-step>span,
html body div.wc-block-grid__product-onsale>span,
html body div.woocommerce-product-details__short-description>p,
html body p.brxe-text-basic.omg-trust-d,
html body p.omg-pro-sub,
html body span.brxe-text-basic,
html body span.brxe-text-basic.omg-ac-pro-t,
html body span.brxe-text-basic.omg-dg-pill,
html body span.omg-bb-note,
html body span.value.lower,
html body span.value.upper,
html body ul.checkboxes>li>label{color:var(--d-mut)!important}
html body #omg-dg-ed-all,
html body #omg-dg-more,
html body #omg-dg-of-pro>strong,
html body #omg-pp-kicker,
html body #omg-pp-region,
html body a.brxe-text-basic.omg-spec-link,
html body a.brxe-text-link.omg-cart-back,
html body a.brxe-text-link.omg-seller-link,
html body a.omg-link,
html body a.reset-button,
html body button.omg-chip-more,
html body div.brxe-text-basic.omg-pro-table>table>thead>tr>th,
html body span.omg-ac-region,
html body span.omg-pro-explore-pill,
html body span.omg-pro-explore-t,
html body span.omg-pro-kicker,
html body span.omgd-n{color:var(--d-violet)!important}
html body #omg-dg-warning-text,
html body #omg-dg-warning-title{color:#fbbf24!important}
/* Second pass */
html body #omg-dg-cover-img{background-color:var(--d-card2)!important}
html body #omg-dg .omg-dg-spec,html body #omg-dg .tab-menu,html body ul.tabs.wc-tabs,html body .omg-pp-trust-item,html body .omg-cart-trust>*,html body #omg-dg #omg-dg-langs,html body #omg-dg #omg-dg-ovgrid{border-color:var(--d-line)!important}
html body [id^="b_pa_"],html body [id^="b_product_cat"],html body .brxe-filter-checkbox input+span,html body input[type=checkbox]{border-color:#4a4466!important;background-color:var(--d-card2)}
html body #omg-dg-desc::after{background:linear-gradient(0deg,var(--d-card) 8%,rgba(23,20,36,0))!important}
html body #omg-hdr-search::after{background:var(--d-card)!important;color:var(--d-mut)!important;border-color:var(--d-line)!important}
html body .omg-cart-btn svg,html body .omg-rel-arrow svg,html body #omg-hdr-cart svg{stroke:var(--d-ink)!important;color:var(--d-ink)!important}
html body .omg-cart-btn,html body .omg-rel-arrow{color:var(--d-ink)!important}
html body #omg-hdr-cart img{filter:brightness(0) invert(1)}
html body #omg-hdr-cart svg *,html body #omg-hdr-cart i,html body #omg-hdr-cart::before{stroke:var(--d-ink)!important;color:var(--d-ink)!important}
html body #omg-hdr-cart [style*="mask"],html body #omg-hdr-cart::before{background-color:var(--d-ink)!important}
html body .brxe-heading:not([class*="omg-hero"]):not([class*="grad"]){color:var(--d-ink)}
html body [class*="omg-sl-"]:is(a,button,details,div,span):not(.omg-sl-logo):not([class*="btn"]:not(.is-ghost)){border-color:var(--d-line)!important}
html body .omg-sl-hero,html body .omg-sl-tabs,html body .omg-sl-filters,html body .omg-sl-chip,html body .omg-sl-pager>button,html body .omg-sl-sort>button{background-color:var(--d-card)!important;color:var(--d-ink)!important}
html body .omg-sl-btn.is-ghost,html body .omg-sl-badge{background-color:var(--d-card2)!important;color:var(--d-ink)!important}
html body a.brxe-text-link{color:var(--d-violet)}
/* Cart page: the site font (the theme loads it with the header we hide there). */
html body.omgx-cart,html body.omgx-cart :is(h1,h2,h3,h4,p,a,span,button,div,li,label,input):not(.star-rating):not(.star-rating *):not([class*="icon"]){font-family:Poppins,system-ui,-apple-system,"Segoe UI",sans-serif!important}
<?php
    echo "</style>\n";
}, 120);
