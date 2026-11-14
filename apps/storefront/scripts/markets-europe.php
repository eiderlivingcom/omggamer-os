<?php
/**
 * Adds every European market (OMG_Markets::EUROPE) that is not in the saved settings yet. Existing markets keep
 * their settings. Run: wp eval-file ~/omg-scripts/markets-europe.php
 */
if (!class_exists('OMG_Markets')) {
    echo "OMG Markets is not active\n";
    return;
}
$saved = get_option(OMG_Markets::OPTION, []);
$saved = is_array($saved) ? $saved : [];
$markets = isset($saved['markets']) && is_array($saved['markets']) ? $saved['markets'] : OMG_Markets::defaults()['markets'];
$added = [];
foreach (OMG_Markets::EUROPE as $cc => $cur) {
    if (!isset($markets[$cc])) {
        $markets[$cc] = ['currency' => $cur, 'enabled' => 1, 'markup' => 0, 'rounding' => 'none'];
        $added[] = "$cc ($cur)";
    }
}
$saved['markets'] = $markets;
update_option(OMG_Markets::OPTION, $saved);
$r = new ReflectionProperty('OMG_Markets', 'settings');
$r->setAccessible(true);
$r->setValue(null, null);
echo 'Added: ' . ($added ? implode(', ', $added) : 'none') . "\n";
$priced = OMG_Markets::enabled();
$missing = [];
foreach (OMG_Markets::EUROPE as $cc => $cur) {
    if (!isset($priced[$cc])) {
        $missing[$cc] = $cur;
    }
}
echo 'No exchange rate yet (hidden until one exists): ' . ($missing ? implode(', ', array_map(fn ($c, $k) => "$k ($c)", $missing, array_keys($missing))) : 'none') . "\n";
echo 'Markets live: ' . count($priced) . "\n";
