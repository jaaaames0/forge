<?php
/** Rebuild Strong bodyweight exercise loads from the original CSVs. */
declare(strict_types=1);
require_once __DIR__ . '/../core/db_helper.php';

$source = $argv[1] ?? '';
$apply = in_array('--apply', $argv, true);
if (!is_dir($source)) throw new RuntimeException('Pass the Strong export directory');
function csv_rows(string $file): array {
    $h = fopen($file, 'r'); $head = fgetcsv($h, 0, ';'); $rows = [];
    while (($row = fgetcsv($h, 0, ';')) !== false) if (count($row) >= count($head)) $rows[] = array_combine($head, $row);
    fclose($h); return $rows;
}
$db = get_db_connection();
$era = (int)$db->query("SELECT id FROM training_eras WHERE source='strong-import'")->fetchColumn();
if (!$era) throw new RuntimeException('Strong archive era not found');
$weights = csv_rows($source . '/weight.csv');
usort($weights, fn($a, $b) => strcmp($a['Date'], $b['Date']));
function interpolated_weight(array $weights, string $at): ?float {
    $ts = strtotime($at); $before = null; $after = null;
    foreach ($weights as $point) {
        $pt = strtotime($point['Date']);
        if ($pt <= $ts) $before = $point;
        if ($pt >= $ts) { $after = $point; break; }
    }
    if (!$before) return $after ? (float)$after['Weight (kg)'] : null;
    if (!$after) return (float)$before['Weight (kg)'];
    $a = strtotime($before['Date']); $b = strtotime($after['Date']);
    $fraction = $a === $b ? 0 : ($ts - $a) / ($b - $a);
    return (float)$before['Weight (kg)'] + $fraction * ((float)$after['Weight (kg)'] - (float)$before['Weight (kg)']);
}
$exercise_ids = [];
foreach ($db->query("SELECT id, name, bw_factor FROM exercises WHERE bw_factor > 0")->fetchAll(PDO::FETCH_ASSOC) as $ex) $exercise_ids[$ex['name']] = $ex;
$names = ['Pull Up' => 'Pull-Up', 'Chin Up' => 'Chin-Up', 'Chest Dip' => 'Dip', 'Hanging Leg Raise' => 'Hanging Leg Raise'];
$workout_file = glob(rtrim($source, '/') . '/strong*.csv')[0] ?? throw new RuntimeException('Strong workout CSV not found');
$updates = [];
foreach (csv_rows($workout_file) as $row) {
    if (!ctype_digit(trim($row['Set Order'])) || !isset($names[$row['Exercise Name']]) || $row['Weight (kg)'] === '') continue;
    $name = $names[$row['Exercise Name']]; $ex = $exercise_ids[$name] ?? null; $bw = interpolated_weight($weights, $row['Date']);
    if (!$ex || $bw === null) continue;
    $updates[] = ['date' => $row['Date'], 'exercise_id' => (int)$ex['id'], 'set_number' => (int)$row['Set Order'], 'added' => (float)$row['Weight (kg)'], 'effective' => round($bw * (float)$ex['bw_factor'] + (float)$row['Weight (kg)'], 1)];
}
printf("%d archived bodyweight sets will be rebuilt using interpolated BW.\n", count($updates));
if (!$apply) { echo "Dry run only. Add --apply to update.\n"; exit; }
$db->beginTransaction();
try {
    $db->prepare("DELETE FROM bodyweight_measurements WHERE era_id = ? AND source = 'strong-import'")->execute([$era]);
    $add_bw = $db->prepare("INSERT INTO bodyweight_measurements (measured_at, weight_kg, era_id, source) VALUES (?, ?, ?, 'strong-import')");
    foreach ($weights as $point) $add_bw->execute([$point['Date'], (float)$point['Weight (kg)'], $era]);
    $update = $db->prepare("UPDATE workout_sets SET weight_kg = ?, added_weight_kg = ? WHERE id IN (SELECT ws.id FROM workout_sets ws JOIN workouts w ON w.id=ws.workout_id WHERE w.era_id=? AND w.started_at=? AND ws.exercise_id=? AND ws.set_number=?)");
    foreach ($updates as $set) $update->execute([$set['effective'], $set['added'], $era, $set['date'], $set['exercise_id'], $set['set_number']]);
    $db->commit(); echo "Strong bodyweight loads rebuilt.\n";
} catch (Throwable $e) { $db->rollBack(); throw $e; }
