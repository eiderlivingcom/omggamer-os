<?php
/**
 * Plugin Name: OMGGamer listing titles
 * Description: One title structure for product pages and the Google Merchant feed, as the big key stores use:
 *              "Buy {Game} ({Region}) ({Devices}) - {Platform} - Digital Key", e.g.
 *              "Buy Hollow Knight: Silksong (Global) (PC) - Steam - Digital Key". No prices in titles.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Devices a platform's keys run on. */
function omg_title_devices(string $platform): string
{
    $p = strtolower(trim($platform));
    $map = [
        'steam' => 'PC', 'epic games' => 'PC', 'epic' => 'PC', 'gog' => 'PC', 'gog.com' => 'PC', 'ea app' => 'PC',
        'origin' => 'PC', 'ubisoft connect' => 'PC', 'uplay' => 'PC', 'ubisoft' => 'PC', 'battle.net' => 'PC',
        'rockstar' => 'PC', 'rockstar games launcher' => 'PC', 'pc' => 'PC', 'windows' => 'PC', 'microsoft store' => 'PC',
        'ms store' => 'PC', 'ms store (pc)' => 'PC', 'mac' => 'Mac',
        'xbox one' => 'Xbox One', 'xbox series x|s' => 'Xbox Series X|S', 'xbox series' => 'Xbox Series X|S',
        'xbox' => 'Xbox One / Xbox Series X|S', 'xbox live' => 'Xbox One / Xbox Series X|S',
        'playstation 4' => 'PS4', 'ps4' => 'PS4', 'playstation 5' => 'PS5', 'ps5' => 'PS5',
        'playstation' => 'PS4 / PS5', 'psn' => 'PS4 / PS5', 'playstation network' => 'PS4 / PS5',
        'nintendo switch' => 'Nintendo Switch', 'switch' => 'Nintendo Switch', 'nintendo' => 'Nintendo Switch',
        'nintendo switch 2' => 'Nintendo Switch 2', 'android' => 'Android', 'ios' => 'iOS', 'meta quest' => 'Meta Quest',
    ];
    return $map[$p] ?? trim($platform);
}

/** The platform (store / network) a key activates on; '' for plain PC. */
function omg_title_store(string $platform): string
{
    $p = strtolower(trim($platform));
    if ($p === '' || in_array($p, ['pc', 'windows', 'mac', 'other', 'any'], true)) {
        return '';
    }
    if (str_starts_with($p, 'xbox')) {
        return 'Xbox Live';
    }
    if (str_starts_with($p, 'playstation') || str_starts_with($p, 'ps') ) {
        return 'PSN';
    }
    if (str_contains($p, 'nintendo') || $p === 'switch') {
        return 'Nintendo eShop';
    }
    $map = [
        'steam' => 'Steam', 'epic games' => 'Epic Games', 'epic' => 'Epic Games', 'gog' => 'GOG', 'gog.com' => 'GOG',
        'ea app' => 'EA App', 'origin' => 'EA App', 'ubisoft connect' => 'Ubisoft Connect', 'uplay' => 'Ubisoft Connect',
        'ubisoft' => 'Ubisoft Connect', 'battle.net' => 'Battle.net', 'rockstar' => 'Rockstar',
        'rockstar games launcher' => 'Rockstar', 'microsoft store' => 'Microsoft Store', 'ms store' => 'Microsoft Store',
        'ms store (pc)' => 'Microsoft Store',
    ];
    return $map[$p] ?? trim($platform);
}

/**
 * @param string[] $platforms platform names the listing (or item) has
 * @param string[] $regions   region names; one region is shown, several are left out
 * @param string   $type      OMGGamer product type (game, dlc, game_account, gift_card...)
 */
function omg_listing_title(string $name, array $platforms, array $regions, string $type = 'game', string $edition = '', bool $buy = true): string
{
    $name = trim(html_entity_decode(wp_strip_all_tags($name), ENT_QUOTES));
    $platforms = array_values(array_unique(array_filter(array_map('trim', $platforms))));
    $regions = array_values(array_unique(array_filter(array_map('trim', $regions), static fn ($r) => strcasecmp($r, 'Any') !== 0)));
    // Per-platform listings are named "Game (Platform)": the platform is said once, further on.
    foreach ($platforms as $pl) {
        $name = trim((string) preg_replace('/\s*\(' . preg_quote($pl, '/') . '\)\s*$/i', '', $name));
    }
    $t = $name;
    if ($edition !== '' && strcasecmp($edition, 'Standard') !== 0 && stripos($name, $edition) === false) {
        $t .= ' ' . $edition;
    }
    if (count($regions) === 1) {
        $t .= ' (' . $regions[0] . ')';
    }
    $devices = [];
    foreach (array_slice($platforms, 0, 4) as $pl) {
        foreach (explode(' / ', omg_title_devices($pl)) as $d) {
            $devices[$d] = true;
        }
    }
    if ($devices) {
        $t .= ' (' . implode(' / ', array_keys($devices)) . ')';
    }
    $store = count($platforms) === 1 ? omg_title_store($platforms[0]) : '';
    if ($store !== '') {
        $t .= ' - ' . $store;
    }
    $what = [
        'game_account' => 'Account', 'gift_card' => 'Gift Card', 'game_currency' => 'Top-up', 'dlc' => 'DLC Key',
        'subscription' => 'Subscription', 'software' => 'Digital Key', 'game' => 'Digital Key', 'gift_link' => 'Gift',
    ][$type] ?? 'Digital Key';
    $t .= ' - ' . $what;
    return ($buy ? 'Buy ' : '') . $t;
}
