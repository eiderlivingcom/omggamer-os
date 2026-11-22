<?php
/**
 * Plugin Name: OMGGamer product schema (Rank Math JSON-LD)
 * Description: Rank Math prints the page's JSON-LD; this completes the Product entity for Google merchant listings:
 *              one Offer (never AggregateOffer) with the SKU, brand (publisher / developer, or the hardware brand),
 *              free instant delivery for digital goods, the return policy from the refund policy, the seller's
 *              logo, https availability URLs, a BreadcrumbList matching the visible breadcrumbs, and ratings
 *              only from real approved reviews. The site is always named "OMGGamer".
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Schema
{
    private const BRAND = 'OMGGamer';

    public static function init(): void
    {
        add_filter('rank_math/json_ld', [__CLASS__, 'graph'], 99, 2);
    }

    private static function logo(): string
    {
        return content_url('uploads/omg-brand/favicon-192.png');
    }

    /** Countries the store sells to (its markets), for shipping and return-policy regions. */
    private static function countries(): array
    {
        $cc = class_exists('OMG_Markets') ? array_keys(OMG_Markets::enabled()) : [];
        $cc = array_values(array_unique(array_filter(array_map(static fn ($c) => strtoupper((string) $c), $cc), static fn ($c) => (bool) preg_match('/^[A-Z]{2}$/', $c))));
        return $cc ?: ['US'];
    }

    public static function graph($data, $jsonld = null)
    {
        if (!is_array($data)) {
            return $data;
        }
        foreach ($data as $key => &$node) {
            if (!is_array($node)) {
                continue;
            }
            $type = $node['@type'] ?? '';
            // The site's name, wherever Rank Math uses the WordPress site title.
            if (in_array($type, ['WebSite', 'Organization'], true) || $key === 'publisher') {
                $node['name'] = self::BRAND;
                if ($type === 'Organization' && empty($node['logo'])) {
                    $node['logo'] = ['@type' => 'ImageObject', 'url' => self::logo()];
                }
            }
            if (in_array($type, ['ItemPage', 'WebPage', 'CollectionPage'], true) && isset($node['name'])) {
                $node['name'] = (string) preg_replace('/\s+[-–|]\s+OMGGamer_2\.0$/u', ' - ' . self::BRAND, (string) $node['name']);
            }
            if ($type === 'Product' && function_exists('is_product') && is_product()) {
                $node = self::product($node);
            }
        }
        unset($node);
        if (function_exists('is_product') && is_product() && !self::has_type($data, 'BreadcrumbList')) {
            $crumbs = self::breadcrumbs();
            if ($crumbs) {
                $data['BreadcrumbList'] = $crumbs;
            }
        }
        return $data;
    }

    private static function has_type(array $data, string $type): bool
    {
        foreach ($data as $n) {
            if (is_array($n) && ($n['@type'] ?? '') === $type) {
                return true;
            }
        }
        return false;
    }

    private static function product(array $node): array
    {
        $p = wc_get_product(get_queried_object_id());
        if (!$p) {
            return $node;
        }
        $id = $p->get_id();
        $physical = class_exists('OMG_SEO') ? OMG_SEO::physical($id) : '';

        // One Offer for the product shown: the cheapest in-stock version of a product with versions.
        $sku = (string) $p->get_sku();
        $offer = isset($node['offers']) && is_array($node['offers']) ? $node['offers'] : [];
        if (isset($offer[0]) && is_array($offer[0])) {
            $offer = $offer[0];
        }
        $item = $p;
        if ($p->is_type('variable')) {
            $best = null;
            foreach ($p->get_available_variations('objects') as $v) {
                if (!$v instanceof WC_Product || !$v->is_in_stock() || $v->get_price() === '') {
                    continue;
                }
                if ($best === null || (float) wc_get_price_to_display($v) < (float) wc_get_price_to_display($best)) {
                    $best = $v;
                }
            }
            if ($best) {
                $item = $best;
                $sku = (string) $best->get_sku() ?: $sku;
            }
        }
        $price = (float) wc_get_price_to_display($item);
        $currency = get_woocommerce_currency();
        if ($price > 0) {
            $offer = array_merge($offer, [
                '@type' => 'Offer',
                'price' => wc_format_decimal($price, wc_get_price_decimals()),
                'priceCurrency' => $currency,
                'availability' => $item->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'url' => get_permalink($id),
            ]);
            unset($offer['lowPrice'], $offer['highPrice'], $offer['offerCount']);
            if ($item !== $p && $item->get_sku()) {
                $offer['sku'] = $item->get_sku();
            }
        }
        if (isset($offer['availability'])) {
            $offer['availability'] = str_replace('http://schema.org/', 'https://schema.org/', (string) $offer['availability']);
        }
        if (empty($offer['itemCondition'])) {
            $offer['itemCondition'] = 'https://schema.org/NewCondition';
        } else {
            $offer['itemCondition'] = (string) preg_replace('#^(https?://schema\.org/)?#', 'https://schema.org/', (string) $offer['itemCondition']);
        }
        $offer['seller'] = ['@type' => 'Organization', 'name' => self::BRAND, 'url' => home_url('/'), 'logo' => self::logo()];

        $countries = self::countries();
        $regions = array_map(static fn ($c) => ['@type' => 'DefinedRegion', 'addressCountry' => $c], $countries);
        if (!$physical) {
            // Digital goods: delivered instantly by email and in the dashboard, no delivery cost.
            $offer['shippingDetails'] = [
                '@type' => 'OfferShippingDetails',
                'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => '0', 'currency' => $currency],
                'shippingDestination' => $regions,
                'deliveryTime' => [
                    '@type' => 'ShippingDeliveryTime',
                    'handlingTime' => ['@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 0, 'unitCode' => 'DAY'],
                    'transitTime' => ['@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 0, 'unitCode' => 'DAY'],
                ],
            ];
            // Refund policy: digital products are replaced or refunded only when faulty, not returned.
            $offer['hasMerchantReturnPolicy'] = [
                '@type' => 'MerchantReturnPolicy',
                'applicableCountry' => $countries,
                'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
                'merchantReturnLink' => home_url('/refund-policy/'),
            ];
        } else {
            // Refund policy: 14-day right of withdrawal by post; the buyer pays return postage unless faulty.
            $offer['hasMerchantReturnPolicy'] = [
                '@type' => 'MerchantReturnPolicy',
                'applicableCountry' => $countries,
                'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                'merchantReturnDays' => 14,
                'returnMethod' => 'https://schema.org/ReturnByMail',
                'returnFees' => 'https://schema.org/ReturnShippingFees',
                'merchantReturnLink' => home_url('/refund-policy/'),
            ];
        }
        $node['offers'] = $offer;
        if ($sku !== '') {
            $node['sku'] = $sku;
        }

        $brand = self::brand($p, $physical);
        if ($brand !== '') {
            $node['brand'] = ['@type' => 'Brand', 'name' => $brand];
        } else {
            unset($node['brand']);
        }

        if (empty($node['image'])) {
            $img = wp_get_attachment_image_url($p->get_image_id(), 'large');
            if ($img) {
                $node['image'] = [$img];
            }
        }
        if (empty($node['description'])) {
            $d = trim(wp_strip_all_tags($p->get_short_description() ?: $p->get_description()));
            if ($d !== '') {
                $node['description'] = wp_trim_words($d, 60, '…');
            }
        }

        // Ratings only from real, approved reviews.
        $count = (int) $p->get_review_count();
        $avg = (float) $p->get_average_rating();
        if ($count > 0 && $avg > 0) {
            $node['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => round($avg, 1), 'reviewCount' => $count, 'bestRating' => 5, 'worstRating' => 1];
        } else {
            unset($node['aggregateRating']);
        }
        return $node;
    }

    /** Gift cards and game currency are issued by the platform or game company. */
    private const ISSUERS = [
        '/\brobux\b|\broblox\b/i' => 'Roblox',
        '/v-?bucks|fortnite/i' => 'Epic Games',
        '/\bsteam\b/i' => 'Valve',
        '/playstation|\bpsn\b|\bps\s?plus\b/i' => 'Sony',
        '/\bxbox\b|game pass|microsoft/i' => 'Microsoft',
        '/nintendo|\beshop\b/i' => 'Nintendo',
        '/apple|itunes|app store/i' => 'Apple',
        '/google play/i' => 'Google',
        '/razer gold/i' => 'Razer',
        '/valorant|league of legends|riot/i' => 'Riot Games',
        '/battle\.?net|blizzard/i' => 'Blizzard Entertainment',
        '/\bea\b|origin|ea app|apex coins/i' => 'Electronic Arts',
        '/minecraft|minecoins/i' => 'Mojang',
        '/spotify/i' => 'Spotify',
        '/netflix/i' => 'Netflix',
    ];

    /** One name per company, the way shoppers and other stores write it. */
    private const BRAND_NAMES = [
        'ubisoft entertainment' => 'Ubisoft',
        'electronic arts inc' => 'Electronic Arts',
        'ea' => 'Electronic Arts',
        'bandai namco entertainment' => 'Bandai Namco',
        'square enix' => 'Square Enix',
        'take-two interactive' => 'Take-Two Interactive',
        'warner bros. interactive entertainment' => 'Warner Bros. Games',
        'warner bros. games' => 'Warner Bros. Games',
        'sega' => 'SEGA',
    ];

    private static function brand_name(string $name): string
    {
        $name = trim(html_entity_decode($name, ENT_QUOTES));
        $name = trim((string) preg_split('/\s*[,;\/]\s*/', $name)[0]); // "Ubisoft, Ubisoft Montreal" → "Ubisoft"
        $name = trim((string) preg_replace('/,?\s+(inc|ltd|llc|gmbh|s\.?a|co|corp|corporation|limited|plc|ab|oy|srl|s\.?r\.?o)\.?$/i', '', $name));
        if ($name === '' || in_array(strtolower($name), ['n/a', 'na', 'unknown', 'none', '-'], true)) {
            return '';
        }
        return self::BRAND_NAMES[strtolower($name)] ?? $name;
    }

    /** Brand: the hardware brand; for games the publisher, else the developer; for gift cards the issuer. */
    public static function brand(WC_Product $p, string $physical = ''): string
    {
        $id = $p->get_id();
        $sources = $physical ? ['product_brand'] : ['pa_publishers', 'pa_developers', 'product_brand'];
        foreach ($sources as $tax) {
            if (!taxonomy_exists($tax)) {
                continue;
            }
            $names = wp_get_post_terms($id, $tax, ['fields' => 'names']);
            if (!is_wp_error($names) && $names && ($b = self::brand_name((string) $names[0])) !== '') {
                return $b;
            }
        }
        if (!$physical) {
            // Older listings may still carry them as plain text attributes.
            foreach (['Publisher', 'Developer'] as $label) {
                if (($b = self::brand_name((string) $p->get_attribute($label))) !== '') {
                    return $b;
                }
            }
            $slugs = wp_get_post_terms($id, 'product_cat', ['fields' => 'slugs']);
            $is_card = (function_exists('omg_is_currency') && omg_is_currency($id))
                || (is_array($slugs) && array_filter($slugs, static fn ($s) => (bool) preg_match('/gift|currenc|top-?up/', (string) $s)));
            if ($is_card) {
                foreach (self::ISSUERS as $re => $issuer) {
                    if (preg_match($re, $p->get_name())) {
                        return $issuer;
                    }
                }
            }
        }
        return '';
    }

    /** The same trail WooCommerce shows on the page (Home › Collection › Product). */
    private static function breadcrumbs(): array
    {
        if (!class_exists('WC_Breadcrumb')) {
            return [];
        }
        $b = new WC_Breadcrumb();
        $b->add_crumb(_x('Home', 'breadcrumb', 'woocommerce'), home_url('/'));
        $trail = apply_filters('woocommerce_get_breadcrumb', $b->generate(), $b);
        $items = [];
        $n = count($trail);
        foreach (array_values($trail) as $i => $c) {
            $name = trim(wp_strip_all_tags((string) ($c[0] ?? '')));
            $url = (string) ($c[1] ?? '');
            if ($name === '') {
                continue;
            }
            if ($url === '' && $i === $n - 1) {
                $url = get_permalink(get_queried_object_id());
            }
            if ($url === '') {
                continue;
            }
            $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'name' => html_entity_decode($name, ENT_QUOTES), 'item' => $url];
        }
        return count($items) > 1 ? ['@type' => 'BreadcrumbList', '@id' => get_permalink(get_queried_object_id()) . '#breadcrumb', 'itemListElement' => $items] : [];
    }
}

OMG_Schema::init();
