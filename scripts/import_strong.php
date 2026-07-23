<?php
/**
 * Import a Strong CSV archive into an isolated Forge training era.
 *
 * Default mode is a dry run. Run with --import only after every exercise has
 * been resolved in the report. The importer never creates exercises silently.
 *
 * php scripts/import_strong.php /path/to/strong_data
 * php scripts/import_strong.php /path/to/strong_data --import
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db_helper.php';

$source_dir = $argv[1] ?? '';
$commit = in_array('--import', $argv, true);
if (!$source_dir || !is_dir($source_dir)) {
    fwrite(STDERR, "Usage: php scripts/import_strong.php /path/to/strong_data [--import]\n");
    exit(2);
}

$workout_file = glob(rtrim($source_dir, '/') . '/strong*.csv')[0] ?? null;
if (!$workout_file) throw new RuntimeException('Could not find Strong workout CSV');

// Deliberately explicit aliases: do not guess or create an exercise when the
// match is uncertain. The dry-run report gives us the remaining decisions.
$aliases = [
    'Deadlift (Barbell)' => 'Deadlift', 'Bench Press (Barbell)' => 'Barbell Bench Press',
    'Pendlay Row (Barbell)' => 'Pendlay Row',
    'Bicep Curl (Barbell)' => 'Barbell Bicep Curl', 'Squat (Barbell)' => 'Barbell Back Squat',
    'Overhead Press (Barbell)' => 'Overhead Press', 'Pull Up' => 'Pull-Up',
    'Chest Dip' => 'Dip', 'Skullcrusher (Barbell)' => 'Skullcrushers',
    'Lateral Raise (Dumbbell)' => 'Dumbbell Lateral Raise',
    'Reverse Fly (Dumbbell)' => 'Dumbbell Rear Delt Fly',
    'Hanging Leg Raise' => 'Hanging Leg Raise', 'Chin Up' => 'Chin-Up',
    'Cable Crunch' => 'Cable Crunch', 'Leg Extension (Machine)' => 'Leg Extension',
    'Lying Leg Curl (Machine)' => 'Leg Curl', 'Plank' => 'Plank',
    'Arnold Press (Dumbbell)' => 'Dumbbell Shoulder Press',
    'Rowing (Machine)' => 'Rowing Machine', 'Running (Treadmill)' => 'Treadmill',
    'Cycling (Indoor)' => 'Stationary Bike', 'Lat Pulldown (Cable)' => 'Cable Lat Pulldown',
    'Face Pull (Cable)' => 'Cable Face Pull', 'Leg Press' => 'Leg Press',
    'Seated Leg Curl (Machine)' => 'Leg Curl', 'Calf Press on Leg Press' => 'Leg Press',
    'Seated Overhead Press (Dumbbell)' => 'Dumbbell Shoulder Press',
    'Reverse Fly (Machine)' => 'Dumbbell Rear Delt Fly',
    'Incline Chest Fly (Cable)' => 'Cable Fly', 'Triceps Extension' => 'Cable Tricep Pushdown',
    'Decline Bench Press (Barbell)' => 'Decline Barbell Bench',
    'Decline Bench Press (Smith Machine)' => 'Decline Smith Bench',
    'Glute Ham Raise' => 'Glute Ham Raise', 'Incline Chest Fly (Dumbbell)' => 'Incline Dumbbell Fly',
    'Farmer\'s Walks' => 'Farmer’s Walk', 'Shrug (Dumbbell)' => 'Dumbbell Shrug',
    'Shrug (Barbell)' => 'Barbell Shrug',
];

$canonical_exercises = [
    ['Decline Barbell Bench', 'Barbell', 'Chest', 'Shoulders'],
    ['Decline Smith Bench', 'Machine', 'Chest', 'Shoulders'],
    ['Glute Ham Raise', 'Bodyweight', 'Legs', 'Glutes'],
    ['Incline Dumbbell Fly', 'Dumbbell', 'Chest', null],
    ['Farmer’s Walk', 'Dumbbell', 'Arms', 'Core'],
    ['Dumbbell Shrug', 'Dumbbell', 'Back', 'Shoulders'],
    ['Barbell Shrug', 'Barbell', 'Back', 'Shoulders'],
];

function rows(string $file): array {
    $handle = fopen($file, 'r');
    if (!$handle) throw new RuntimeException("Cannot read {$file}");
    $headers = fgetcsv($handle, 0, ';');
    $result = [];
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        if (count($row) < count($headers)) continue;
        $result[] = array_combine($headers, $row);
    }
    fclose($handle);
    return $result;
}

$db = get_db_connection();
$exercise_ids = [];
foreach ($db->query('SELECT id, name FROM exercises')->fetchAll(PDO::FETCH_ASSOC) as $exercise) {
    $exercise_ids[$exercise['name']] = (int)$exercise['id'];
}
$known_exercises = $exercise_ids;
foreach ($canonical_exercises as [$name]) $known_exercises[$name] = true;

$strong_rows = rows($workout_file);
$unresolved = [];
$resolved = [];
foreach ($strong_rows as $row) {
    $strong_name = trim($row['Exercise Name']);
    $forge_name = $exercise_ids[$strong_name] ?? null ? $strong_name : ($aliases[$strong_name] ?? null);
    if (!$forge_name || !isset($known_exercises[$forge_name])) {
        $unresolved[$strong_name] = ($unresolved[$strong_name] ?? 0) + 1;
    } else {
        $resolved[$strong_name] = $forge_name;
    }
}

$workout_count = count(array_unique(array_column($strong_rows, 'Workout #')));
echo sprintf("Strong archive: %d workouts, %d rows, %d resolved exercise names, %d unresolved.\n", $workout_count, count($strong_rows), count(array_unique($resolved)), count($unresolved));
foreach ($unresolved as $name => $count) echo "UNRESOLVED ({$count} sets): {$name}\n";
if (!$commit) {
    echo "Dry run only. Resolve every name above before running with --import.\n";
    exit($unresolved ? 1 : 0);
}
if ($unresolved) throw new RuntimeException('Import aborted: unresolved exercise names');
if ($db->query("SELECT 1 FROM training_eras WHERE source = 'strong-import' LIMIT 1")->fetchColumn()) {
    throw new RuntimeException('A Strong archive era already exists; refusing to import twice');
}

$db->beginTransaction();
try {
    // These are genuinely distinct historical movements. Add their canonical
    // Forge entries explicitly, only as part of the confirmed import.
    $add_exercise = $db->prepare('INSERT OR IGNORE INTO exercises (name, category, muscle_primary, muscle_secondary, is_custom) VALUES (?, ?, ?, ?, 1)');
    foreach ($canonical_exercises as [$name, $category, $primary, $secondary]) {
        $add_exercise->execute([$name, $category, $primary, $secondary]);
    }
    $exercise_ids = [];
    foreach ($db->query('SELECT id, name FROM exercises')->fetchAll(PDO::FETCH_ASSOC) as $exercise) {
        $exercise_ids[$exercise['name']] = (int)$exercise['id'];
    }
    $first_date = min(array_column($strong_rows, 'Date'));
    $last_date = max(array_column($strong_rows, 'Date'));
    $db->prepare("INSERT INTO training_eras (name, starts_at, ends_at, source, notes) VALUES (?, ?, ?, 'strong-import', ?)")
       ->execute(['Strong archive (2021–2025)', $first_date, $last_date, 'Imported from Strong; excluded from the active Forge era.']);
    $era_id = (int)$db->lastInsertId();
    $workout_ids = [];
    $add_workout = $db->prepare('INSERT INTO workouts (name, started_at, finished_at, notes, type, era_id) VALUES (?, ?, ?, ?, ?, ?)');
    $add_set = $db->prepare('INSERT INTO workout_sets (workout_id, exercise_id, set_number, set_type, weight_kg, reps, rpe, distance_km, duration_sec, notes, logged_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($strong_rows as $row) {
        if (!ctype_digit(trim($row['Set Order']))) continue; // Strong exports rest intervals as pseudo-sets.
        $strong_id = $row['Workout #'];
        if (!isset($workout_ids[$strong_id])) {
            $started = $row['Date'];
            $duration = (int)($row['Duration (sec)'] ?: 0);
            $finished = date('Y-m-d H:i:s', strtotime($started) + $duration);
            $add_workout->execute([$row['Workout Name'] ?: null, $started, $finished, $row['Workout Notes'] ?: null, 'strength', $era_id]);
            $workout_ids[$strong_id] = (int)$db->lastInsertId();
        }
        $exercise_name = $resolved[trim($row['Exercise Name'])];
        $add_set->execute([
            $workout_ids[$strong_id], $exercise_ids[$exercise_name], (int)$row['Set Order'], 'working',
            $row['Weight (kg)'] !== '' ? (float)$row['Weight (kg)'] : null,
            $row['Reps'] !== '' ? (int)$row['Reps'] : null,
            $row['RPE'] !== '' ? (float)$row['RPE'] : null,
            $row['Distance (meters)'] !== '' ? (float)$row['Distance (meters)'] / 1000 : null,
            $row['Seconds'] !== '' ? (int)$row['Seconds'] : null,
            $row['Notes'] ?: null, $row['Date'],
        ]);
    }
    foreach (['weight.csv' => 'weight', 'body_fat_percentage.csv' => 'body_fat'] as $file => $kind) {
        $path = rtrim($source_dir, '/') . '/' . $file;
        if (!is_file($path)) continue;
        foreach (rows($path) as $row) {
            if ($kind === 'weight') {
                $db->prepare('INSERT INTO workouts (name, started_at, finished_at, bodyweight_kg, type, era_id) VALUES (?, ?, ?, ?, ?, ?)')
                   ->execute(['Strong bodyweight', $row['Date'], $row['Date'], (float)$row['Weight (kg)'], 'imported', $era_id]);
            } else {
                $db->prepare('INSERT INTO body_measurements (measured_at, caliper_bf_pct, era_id) VALUES (?, ?, ?)')
                   ->execute([$row['Date'], (float)$row['Body fat percentage'], $era_id]);
            }
        }
    }
    $db->commit();
    echo "Imported into the Strong archive era.\n";
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
