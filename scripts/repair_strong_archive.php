<?php
/** Repair the first Strong archive import. Default mode reports only. */
declare(strict_types=1);
require_once __DIR__ . '/../core/db_helper.php';

$apply = in_array('--apply', $argv, true);
$db = get_db_connection();
$era_id = (int)$db->query("SELECT id FROM training_eras WHERE source = 'strong-import' LIMIT 1")->fetchColumn();
if (!$era_id) throw new RuntimeException('Strong archive era not found');

$invalid = $db->prepare("SELECT COUNT(*) FROM workout_sets ws JOIN workouts w ON w.id = ws.workout_id WHERE w.era_id = ? AND ws.set_number = 0 AND ws.weight_kg IS NULL AND ws.reps IS NULL");
$invalid->execute([$era_id]);
$invalid_count = (int)$invalid->fetchColumn();
$weights = $db->prepare("SELECT id, started_at, bodyweight_kg FROM workouts WHERE era_id = ? AND name = 'Strong bodyweight'");
$weights->execute([$era_id]);
$sessions = $db->prepare("SELECT id, started_at FROM workouts WHERE era_id = ? AND name != 'Strong bodyweight'");
$sessions->execute([$era_id]);
$session_rows = $sessions->fetchAll(PDO::FETCH_ASSOC);
$matches = [];
foreach ($weights->fetchAll(PDO::FETCH_ASSOC) as $weight) {
    $nearest = null; $nearest_delta = PHP_INT_MAX;
    foreach ($session_rows as $session) {
        $delta = abs(strtotime($weight['started_at']) - strtotime($session['started_at']));
        if ($delta < $nearest_delta) { $nearest = $session; $nearest_delta = $delta; }
    }
    if ($nearest && $nearest_delta <= 12 * 3600) {
        $matches[] = ['weight_id' => (int)$weight['id'], 'session_id' => (int)$nearest['id'], 'kg' => (float)$weight['bodyweight_kg']];
    }
}
printf("%d invalid Rest Timer sets; %d matched weigh-ins.\n", $invalid_count, count($matches));
$bodyweight_sets = $db->prepare("SELECT ws.id, w.started_at, e.bw_factor FROM workout_sets ws JOIN workouts w ON w.id = ws.workout_id JOIN exercises e ON e.id = ws.exercise_id WHERE w.era_id = ? AND ws.weight_kg = 0 AND e.bw_factor > 0");
$bodyweight_sets->execute([$era_id]);
$nearest_weight = $db->prepare("SELECT bodyweight_kg FROM workouts WHERE era_id = ? AND bodyweight_kg IS NOT NULL AND bodyweight_kg > 0 ORDER BY ABS(strftime('%s', started_at) - strftime('%s', ?)) LIMIT 1");
$effective_sets = [];
foreach ($bodyweight_sets->fetchAll(PDO::FETCH_ASSOC) as $set) {
    $nearest_weight->execute([$era_id, $set['started_at']]);
    $bw = $nearest_weight->fetchColumn();
    if ($bw !== false) $effective_sets[] = ['id' => (int)$set['id'], 'weight' => round((float)$bw * (float)$set['bw_factor'], 1)];
}
printf("%d Strong bodyweight sets will receive Forge effective load.\n", count($effective_sets));
if (!$apply) { echo "Dry run only. Re-run with --apply to repair the archive.\n"; exit; }

$db->beginTransaction();
try {
    $delete_sets = $db->prepare("DELETE FROM workout_sets WHERE id IN (SELECT ws.id FROM workout_sets ws JOIN workouts w ON w.id = ws.workout_id WHERE w.era_id = ? AND ws.set_number = 0 AND ws.weight_kg IS NULL AND ws.reps IS NULL)");
    $delete_sets->execute([$era_id]);
    $set_weight = $db->prepare('UPDATE workouts SET bodyweight_kg = ? WHERE id = ?');
    $delete_weight = $db->prepare("DELETE FROM workouts WHERE id = ? AND name = 'Strong bodyweight'");
    foreach ($matches as $match) {
        $set_weight->execute([$match['kg'], $match['session_id']]);
        $delete_weight->execute([$match['weight_id']]);
    }
    $set_effective = $db->prepare('UPDATE workout_sets SET weight_kg = ?, added_weight_kg = 0 WHERE id = ?');
    foreach ($effective_sets as $set) $set_effective->execute([$set['weight'], $set['id']]);
    $db->commit();
    echo "Strong archive repaired.\n";
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
