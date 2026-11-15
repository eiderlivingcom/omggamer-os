<?php
/** Read-only: prints product data from Amazon pages (fetched from this server) and the AI Studio field definitions. */
if (!defined('ABSPATH')) { exit; }
foreach (['hardware', 'accessory'] as $k) {
    echo "== attr_defs $k: ", json_encode(array_map(static fn ($d) => [$d[0], $d[1], $d[2]], OMG_PS::attr_defs($k))), "\n";
    echo "== section_defs $k: ", json_encode(OMG_PS::section_defs($k)), "\n";
}
$asins = array_filter(explode(',', (string) getenv('ASINS')));
foreach ($asins as $a) {
    $r = wp_remote_get("https://www.amazon.com/dp/$a?th=1&psc=1", ['timeout' => 25, 'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', 'Accept-Language' => 'en-US,en;q=0.9', 'Accept' => 'text/html']]);
    $h = is_wp_error($r) ? '' : wp_remote_retrieve_body($r);
    echo "\n######## $a len=", strlen($h), stripos($h, 'captcha') !== false ? ' CAPTCHA' : '', "\n";
    if (!$h || stripos($h, 'captcha') !== false) { sleep(4); continue; }
    preg_match('~id="productTitle"[^>]*>\s*(.*?)\s*</span>~s', $h, $m); echo "TITLE: ", html_entity_decode(trim($m[1] ?? '')), "\n";
    preg_match('~id="bylineInfo"[^>]*>(.*?)</a>~s', $h, $m); echo "BRAND: ", trim(wp_strip_all_tags($m[1] ?? '')), "\n";
    preg_match('~class="a-price aok-align-center[^"]*".*?<span class="a-offscreen">([^<]+)<~s', $h, $m) || preg_match('~<span class="a-offscreen">(\$[\d,.]+)</span>~', $h, $m); echo "PRICE: ", $m[1] ?? '-', "\n";
    if (preg_match('~id="feature-bullets".*?</ul>~s', $h, $fb)) { preg_match_all('~<span class="a-list-item">\s*(.*?)\s*</span>~s', $fb[0], $bs); foreach ($bs[1] as $b) { echo "  * ", trim(html_entity_decode(wp_strip_all_tags($b))), "\n"; } }
    preg_match_all('~"hiRes":"(https://m\.media-amazon\.com/images/I/[^"]+)"~', $h, $im); echo "IMAGES: ", implode(' ', array_slice(array_unique($im[1]), 0, 7)), "\n";
    if (preg_match('~id="productDetails_techSpec_section_1".*?</table>~s', $h, $t) || preg_match('~id="productOverview_feature_div".*?</table>~s', $h, $t)) {
        preg_match_all('~<tr[^>]*>\s*<t[hd][^>]*>(.*?)</t[hd]>\s*<td[^>]*>(.*?)</td>~s', $t[0], $rows, PREG_SET_ORDER);
        foreach ($rows as $row) { echo "  - ", trim(wp_strip_all_tags($row[1])), ": ", trim(preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags($row[2])))), "\n"; }
    }
    if (preg_match('~id="productOverview_feature_div".*?</table>~s', $h, $t)) {
        preg_match_all('~<tr[^>]*>.*?<span class="a-size-base a-text-bold">(.*?)</span>.*?<span class="a-size-base po-break-word">(.*?)</span>~s', $t[0], $rows, PREG_SET_ORDER);
        foreach ($rows as $row) { echo "  o ", trim(wp_strip_all_tags($row[1])), ": ", trim(html_entity_decode(wp_strip_all_tags($row[2]))), "\n"; }
    }
    sleep(3);
}
