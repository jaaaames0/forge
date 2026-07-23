<?php
/** Seed user-recalled body-fat anchors for the imported Strong training era. */
declare(strict_types=1);

require_once __DIR__ . '/../core/db_helper.php';

$apply = in_array('--apply', $argv, true);
$db = get_db_connection();
$era = $db->query("SELECT id, name FROM training_eras WHERE source = 'strong-import' ORDER BY starts_at LIMIT 1")
    ->fetch(PDO::FETCH_ASSOC);
if (!$era) {
    throw new RuntimeException('No Strong-import training era found.');
}

// 2021-10-19 is the first-cut endpoint, rather than the later recomp weigh-in.
$anchors = [
    ['2021-01-13 12:00:00', 15.0, 'Start of recorded training'],
    ['2021-06-21 12:00:00', 30.0, 'First bulk peak'],
    ['2021-10-19 12:00:00', 12.0, 'First cut nadir'],
    ['2022-07-29 12:00:00', 27.5, 'Second bulk peak'],
    ['2022-12-25 12:00:00', 10.0, 'Second cut nadir'],
];

echo "Historical body-fat anchors for {$era['name']}:\n";
foreach ($anchors as [$at, $bodyfat, $label]) {
    echo sprintf("  %s · %.1f%% · %s\n", substr($at, 0, 10), $bodyfat, $label);
}
if (!$apply) {
    echo "Dry run only. Use --apply to save these memory anchors.\n";
    exit;
}

$db->beginTransaction();
try {
    $db->prepare("DELETE FROM body_measurements WHERE era_id = ? AND source = 'memory-anchor'")->execute([(int)$era['id']]);
    $insert = $db->prepare("INSERT INTO body_measurements (measured_at, caliper_bf_pct, era_id, source) VALUES (?, ?, ?, 'memory-anchor')");
    foreach ($anchors as [$at, $bodyfat]) {
        $insert->execute([$at, $bodyfat, (int)$era['id']]);
    }
    $db->commit();
    echo count($anchors) . " historical body-fat anchors saved.\n";
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
