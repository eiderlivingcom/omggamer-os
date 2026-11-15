<?php
/**
 * Creates/updates the legal pages from ~/omg-scripts/legal/*.html, links them in WooCommerce settings and in the
 * Bricks footer (template "OMG Footer"), adds the payment row and operator block, removes the "Sell" column and
 * unpublishes the vendor onboarding page. Safe to run again. Run: wp eval-file ~/omg-scripts/legal-setup.php
 */

if (!defined('ABSPATH')) {
    exit;
}

$dir = __DIR__ . '/legal';
$ids = [];
$dry = getenv('OMG_DRY') === '1';
$reuse = ['privacy-policy' => 3, 'refund-policy' => 13];
foreach (OMG_Legal::PAGES as $slug) {
    $html = (string) file_get_contents("$dir/$slug.html");
    if ($html === '') {
        echo "missing $slug.html\n";
        continue;
    }
    preg_match('~<h1>(.*?)</h1>~s', $html, $m);
    $title = html_entity_decode(wp_strip_all_tags($m[1] ?? $slug));
    $page = get_page_by_path($slug, OBJECT, 'page');
    if (!$page && isset($reuse[$slug])) {
        $page = get_post($reuse[$slug]);
    }
    if ($dry) {
        echo "dry: $slug -> ", $page ? $page->ID : 'new', " ($title)\n";
        continue;
    }
    $data = ['post_title' => $title, 'post_name' => $slug, 'post_content' => $html, 'post_status' => 'publish', 'post_type' => 'page', 'comment_status' => 'closed'];
    if ($page) {
        if (!get_post_meta($page->ID, '_omg_legal_backup', true)) {
            update_post_meta($page->ID, '_omg_legal_backup', wp_slash(['title' => $page->post_title, 'name' => $page->post_name, 'status' => $page->post_status, 'content' => $page->post_content]));
        }
        $data['ID'] = $page->ID;
        $id = wp_update_post(wp_slash($data), true);
    } else {
        $id = wp_insert_post(wp_slash($data), true);
    }
    if (is_wp_error($id)) {
        echo "error $slug: ", $id->get_error_message(), "\n";
        continue;
    }
    update_post_meta($id, '_omg_legal', 1);
    $ids[$slug] = $id;
    echo "page $slug => $id\n";
}
if (isset($ids['terms-and-conditions'])) {
    update_option('woocommerce_terms_page_id', $ids['terms-and-conditions']);
}
if (isset($ids['privacy-policy'])) {
    update_option('wp_page_for_privacy_policy', $ids['privacy-policy']);
}
if (isset($ids['refund-policy'])) {
    update_option('woocommerce_refund_returns_page_id', $ids['refund-policy']);
}

// Selling on OMGGamer is off for now: unpublish the vendor onboarding page (kept as a draft).
$vo = get_page_by_path('vendor-onboarding', OBJECT, 'page');
if (!$dry && $vo && $vo->post_status === 'publish') {
    wp_update_post(['ID' => $vo->ID, 'post_status' => 'draft']);
    echo "vendor-onboarding => draft\n";
}

/* ---------------------------------------------------------------- footer */
$tpl = 0;
foreach (get_posts(['post_type' => 'bricks_template', 'posts_per_page' => -1, 'post_status' => 'any', 'meta_key' => '_bricks_template_type', 'meta_value' => 'footer']) as $t) {
    $tpl = $t->ID;
}
$key = '_bricks_page_footer_2';
$els = $tpl ? get_post_meta($tpl, $key, true) : null;
if (!is_array($els)) {
    echo "no footer template\n";
    return;
}
if (!$dry && !get_option('omg_footer_backup_legal')) {
    add_option('omg_footer_backup_legal', ['tpl' => $tpl, 'elements' => $els], '', false);
}
$by = [];
foreach ($els as $i => $e) {
    $by[$e['id']] = $i;
}
$used = array_flip(array_keys($by));
$newid = static function () use (&$used): string {
    do {
        $id = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 6);
    } while (isset($used[$id]) || ctype_digit($id[0]));
    $used[$id] = 1;
    return $id;
};
$find = static function (callable $fn) use (&$els) {
    foreach ($els as $i => $e) {
        if ($fn($e)) {
            return $i;
        }
    }
    return null;
};
$link = static function (array $proto, string $text, string $url) use ($newid): array {
    $e = $proto;
    $e['id'] = $newid();
    $e['settings']['text'] = $text;
    $e['settings']['link'] = array_merge((array) ($proto['settings']['link'] ?? []), ['type' => 'external', 'url' => $url]);
    unset($e['children']);
    $e['children'] = [];
    return $e;
};
$set_children = static function (string $parent, array $children) use (&$els) {
    foreach ($els as &$e) {
        if ($e['id'] === $parent) {
            $e['children'] = $children;
        }
    }
};
$el_index = static function (string $id) use (&$els) {
    foreach ($els as $i => $e) {
        if ($e['id'] === $id) {
            return $i;
        }
    }
    return null;
};

// 1. "Sell" column becomes "Legal".
$head = $find(static fn ($e) => $e['name'] === 'heading' && in_array(trim(wp_strip_all_tags((string) ($e['settings']['text'] ?? ''))), ['Sell', 'Legal'], true));
if ($head !== null) {
    $col = $els[$head]['parent'];
    $els[$head]['settings']['text'] = 'Legal';
    $proto = null;
    foreach ($els as $e) {
        if ($e['parent'] === $col && $e['name'] === 'text-link') {
            $proto = $proto ?? $e;
        }
    }
    if ($proto) {
        $els = array_values(array_filter($els, static fn ($e) => !($e['parent'] === $col && $e['name'] === 'text-link')));
        $hid = null;
        foreach ($els as $e) {
            if ($e['parent'] === $col && $e['name'] === 'heading') {
                $hid = $e['id'];
            }
        }
        $kids = [$hid];
        $pos = $el_index($hid) + 1;
        $new = [];
        foreach ([
            ['Terms and conditions', '/terms-and-conditions/'],
            ['Privacy policy', '/privacy-policy/'],
            ['Cookie policy', '/cookie-policy/'],
            ['Refund policy', '/refund-policy/'],
            ['PRO membership terms', '/pro-terms/'],
            ['DSA & GPSR', '/dsa-gpsr/'],
            ['Legal notice', '/legal-notice/'],
        ] as [$t, $u]) {
            $l = $link($proto, $t, $u);
            $l['parent'] = $col;
            $new[] = $l;
            $kids[] = $l['id'];
        }
        array_splice($els, $pos, 0, $new);
        $set_children($col, $kids);
        echo "legal column done\n";
    }
}

// 2. Support column: add Buyer protection once.
$sup = $find(static fn ($e) => $e['name'] === 'heading' && trim(wp_strip_all_tags((string) ($e['settings']['text'] ?? ''))) === 'Support');
if ($sup !== null && $find(static fn ($e) => ($e['settings']['link']['url'] ?? '') === '/buyer-protection/') === null) {
    $col = $els[$sup]['parent'];
    $last = null;
    foreach ($els as $e) {
        if ($e['parent'] === $col && $e['name'] === 'text-link') {
            $last = $e;
        }
    }
    if ($last) {
        $l = $link($last, 'Buyer protection', '/buyer-protection/');
        $l['parent'] = $col;
        array_splice($els, $el_index($last['id']) + 1, 0, [$l]);
        foreach ($els as &$e) {
            if ($e['id'] === $col) {
                $e['children'][] = $l['id'];
            }
        }
        unset($e);
        echo "buyer protection link added\n";
    }
}

// 3. Wording: we sell directly now.
foreach ($els as &$e) {
    if (isset($e['settings']['text']) && is_string($e['settings']['text'])) {
        $e['settings']['text'] = str_replace('from verified sellers', 'from verified partners', $e['settings']['text']);
    }
}
unset($e);

// 4. Payment row (before the link columns) and operator block (before the bottom bar).
$root = $find(static fn ($e) => ($e['settings']['_cssId'] ?? '') === 'omg-ftr');
$cols = $find(static fn ($e) => ($e['settings']['_cssId'] ?? '') === 'omg-ftr-cols');
$bar = $find(static fn ($e) => ($e['settings']['_cssId'] ?? '') === 'omg-ftr-legal');
$pad = ['_width' => '100%', '_widthMax' => '1600px', '_padding' => ['left' => '40px', 'right' => '40px'], '_padding:tablet_portrait' => ['left' => '24px', 'right' => '24px'], '_padding:mobile_portrait' => ['left' => '16px', 'right' => '16px']];
$add_block = static function (string $cssId, string $shortcode, int $beforeIndex) use (&$els, $root, $newid, $pad, $el_index) {
    foreach ($els as $e) {
        if (($e['settings']['_cssId'] ?? '') === $cssId) {
            return;
        }
    }
    $rootId = $els[$root]['id'];
    $beforeId = $els[$beforeIndex]['id'];
    $c = ['id' => $newid(), 'name' => 'container', 'parent' => $rootId, 'children' => [], 'settings' => array_merge($pad, ['_cssId' => $cssId])];
    $s = ['id' => $newid(), 'name' => 'shortcode', 'parent' => $c['id'], 'children' => [], 'settings' => ['shortcode' => $shortcode]];
    $c['children'] = [$s['id']];
    array_splice($els, $el_index($beforeId), 0, [$c, $s]);
    foreach ($els as &$e) {
        if ($e['id'] === $rootId) {
            $k = array_search($beforeId, $e['children'], true);
            array_splice($e['children'], $k === false ? count($e['children']) : $k, 0, [$c['id']]);
        }
    }
    unset($e);
    echo "added $cssId\n";
};
if ($root !== null && $cols !== null) {
    $add_block('omg-ftr-pay', '[omg_footer_pay]', $cols);
}
$bar = $find(static fn ($e) => ($e['settings']['_cssId'] ?? '') === 'omg-ftr-legal');
if ($root !== null && $bar !== null) {
    $add_block('omg-ftr-company', '[omg_footer_company]', $bar);
}

// 5. Bottom bar: copyright with the operator, and the main legal links.
$bar = $find(static fn ($e) => ($e['settings']['_cssId'] ?? '') === 'omg-ftr-legal');
$barId = $els[$bar]['id'];
foreach ($els as &$e) {
    if ($e['parent'] === $barId && $e['name'] === 'text-basic') {
        $e['settings']['text'] = '© ' . gmdate('Y') . ' OMGGamer, operated by ' . OMG_Legal::get('name') . '. Product names and artwork belong to their respective owners.';
    }
}
unset($e);
$lk = $find(static fn ($e) => ($e['settings']['_cssId'] ?? '') === 'omg-ftr-legal-links');
if ($lk !== null) {
    $lkId = $els[$lk]['id'];
    $proto = null;
    foreach ($els as $e) {
        if ($e['parent'] === $lkId && $e['name'] === 'text-link') {
            $proto = $proto ?? $e;
        }
    }
    if ($proto) {
        $els = array_values(array_filter($els, static fn ($e) => !($e['parent'] === $lkId && $e['name'] === 'text-link')));
        $kids = [];
        $new = [];
        foreach ([['Terms', '/terms-and-conditions/'], ['Privacy', '/privacy-policy/'], ['Cookies', '/cookie-policy/'], ['Refunds', '/refund-policy/'], ['Legal notice', '/legal-notice/']] as [$t, $u]) {
            $l = $link($proto, $t, $u);
            $l['parent'] = $lkId;
            $new[] = $l;
            $kids[] = $l['id'];
        }
        array_splice($els, $el_index($lkId) + 1, 0, $new);
        $set_children($lkId, $kids);
        echo "bottom links done\n";
    }
}

$dry = getenv('OMG_DRY') === '1';
if (!$dry) {
    update_post_meta($tpl, $key, wp_slash($els));
}
// Every child must exist and point back to its parent.
$chk = $dry ? $els : get_post_meta($tpl, $key, true);
$map = [];
foreach ($chk as $e) {
    $map[$e['id']] = $e;
}
$bad = 0;
foreach ($chk as $e) {
    foreach ((array) ($e['children'] ?? []) as $c) {
        if (!isset($map[$c]) || $map[$c]['parent'] !== $e['id']) {
            $bad++;
            echo "bad child $c of {$e['id']}\n";
        }
    }
}
if ($dry) { foreach ($chk as $e) { echo $e['id'], ' ', $e['name'], ' p=', $e['parent'], ' ', substr(wp_strip_all_tags((string) ($e['settings']['text'] ?? ($e['settings']['shortcode'] ?? ''))), 0, 60), ' ', $e['settings']['link']['url'] ?? '', "\n"; } }
echo "footer saved: ", count($chk), " elements, $bad problems\n";
if (function_exists('wp_cache_flush')) {
    wp_cache_flush();
}
