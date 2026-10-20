<?php
/**
 * Lightweight regression checks for FORGE's database-domain helpers.
 * Run with: php tests/regression.php
 */

declare(strict_types=1);

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("FAIL: {$message}");
    }
}

function expect_exception(callable $callback, string $message): void {
    try {
        $callback();
    } catch (Exception) {
        return;
    }
    throw new RuntimeException("FAIL: {$message}");
}

$worker = file_get_contents(__DIR__ . '/../sw.js');
$active_bar = file_get_contents(__DIR__ . '/../core/active_workout_bar.php');
$api_client = file_get_contents(__DIR__ . '/../core/api-client.js');
$rest_alerts = file_get_contents(__DIR__ . '/../core/rest-alerts.js');
$hr_monitor = file_get_contents(__DIR__ . '/../workout/hr-monitor.js');
$session_clock = file_get_contents(__DIR__ . '/../workout/session-clock.js');
$warmups = file_get_contents(__DIR__ . '/../workout/warmups.js');
check($worker !== false && str_contains($worker, 'event.waitUntil(fireNotification())'), 'immediate worker notifications extend the event lifetime');
check($worker !== false && str_contains($worker, 'event.waitUntil(clients.claim())'), 'updated worker claims open FORGE pages');
check($active_bar !== false && str_contains($active_bar, 'forgeRestAlertConfig'), 'active bar provides rest-alert configuration');
check($rest_alerts !== false && str_contains($rest_alerts, "notifyRestDone().finally(() => swPost({ type: 'CANCEL_TIMER' }))"), 'hidden-page notification fallback remains enabled');
check($api_client !== false && str_contains($api_client, 'window.forgeApi'), 'shared browser API client exists');
check($warmups !== false && str_contains($warmups, 'window.openWarmupCalc'), 'workout warmup feature is externalized');
check($hr_monitor !== false && str_contains($hr_monitor, 'window.forgeHR'), 'workout HR monitor feature is externalized');
check($session_clock !== false && str_contains($session_clock, 'forgeWorkoutClockConfig'), 'workout session clock is externalized');

$db_path = tempnam(sys_get_temp_dir(), 'forge-test-');
if ($db_path === false) {
    throw new RuntimeException('Could not create temporary database');
}

try {
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    if ($schema === false) throw new RuntimeException('Could not read schema');
    $bootstrap = new PDO('sqlite:' . $db_path);
    $bootstrap->exec($schema);
    // Simulate the pre-v6 save path, which could leave manual measurements
    // without an era. The migration must repair those existing readings.
    $bootstrap->exec("INSERT INTO schema_migrations (version) VALUES (5)");
    $bootstrap->exec("INSERT INTO body_measurements (measured_at, caliper_bf_pct, era_id) VALUES ('2026-01-01 08:00:00', 20.0, NULL)");
    putenv("FORGE_DB_PATH={$db_path}");
    require_once __DIR__ . '/../core/db_helper.php';
    require_once __DIR__ . '/../core/cycle_analysis.php';
    $db = get_db_connection();

    $indexes = $db->query("SELECT name FROM sqlite_master WHERE type = 'index'")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach (['idx_workout_sets_workout', 'idx_workout_sets_exercise_workout', 'idx_workouts_finished_started'] as $index) {
        check(in_array($index, $indexes, true), "{$index} exists");
    }
    check((int)$db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() === 6, 'schema migration version is recorded');
    check((bool)$db->query('SELECT id FROM training_eras WHERE is_active = 1')->fetchColumn(), 'active training era is created');
    check((int)$db->query('SELECT COUNT(*) FROM body_measurements WHERE era_id IS NULL')->fetchColumn() === 0, 'legacy body measurements are assigned to the active era');
    check(forge_navy_bodyfat_pct('M', 176.0, 100.0, 42.0) === 24.7, 'Navy body-fat calculation converts centimetres to inches');
    $bf_series = [['at' => '2026-11-01', 'navy' => 20.0], ['at' => '2026-11-05', 'navy' => null], ['at' => '2026-11-11', 'navy' => 15.0]];
    check(forge_series_at($bf_series, 'at', 'navy', '2026-11-06') === 17.5, 'body-fat series interpolates across entries missing that method');
    check(forge_series_at($bf_series, 'at', 'navy', '2026-11-20') === 15.0, 'body-fat series carries the latest reading forward');
    $bf_now = forge_bodyfat_at([
        ['measured_at' => '2026-11-01 08:00:00', 'navy' => 16.0, 'caliper' => null],
        ['measured_at' => '2026-11-10 08:00:00', 'navy' => null, 'caliper' => 13.0],
    ], '2026-11-20 08:00:00');
    check($bf_now['smoothed'] === 14.5, 'smoothed body fat averages each method carried forward');
    $db->exec("INSERT INTO training_cycles (era_id, cycle_type, starts_at, ends_at, metrics_json) SELECT id, 'cut', '2026-01-01', '2026-02-01', '{\"note\":1}' FROM training_eras WHERE is_active = 1");
    check(forge_refresh_stale_cycle_metrics($db) === 1 && forge_refresh_stale_cycle_metrics($db) === 0, 'stale cycle metrics are recomputed once');
    $db->exec("DELETE FROM training_cycles");

    $db->exec("INSERT INTO exercises (name, category) VALUES ('Test Press', 'Barbell')");
    $exercise_id = (int)$db->lastInsertId();
    $db->exec("INSERT INTO workouts (name) VALUES ('Active')");
    $active_id = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO workout_exercises (workout_id, exercise_id) VALUES (?, ?)')
        ->execute([$active_id, $exercise_id]);

    require_active_workout($db, $active_id);
    require_active_workout_exercise($db, $active_id, $exercise_id);
    expect_exception(fn() => require_active_workout_exercise($db, $active_id, $exercise_id + 1), 'unknown workout exercise is rejected');

    $db->prepare('UPDATE workouts SET finished_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$active_id]);
    expect_exception(fn() => require_active_workout($db, $active_id), 'finished workout is rejected');

    $db->exec("INSERT INTO workouts (name, finished_at) VALUES ('Previous', CURRENT_TIMESTAMP)");
    $previous_id = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO workout_sets (workout_id, exercise_id, set_type, weight_kg, reps, rpe)
                  VALUES (?, ?, 'working', 100, 5, 6)")
        ->execute([$previous_id, $exercise_id]);
    $suggestion = compute_progression_suggestion($db, [
        'exercise_id' => $exercise_id,
        'progression_scheme' => 'rpe',
        'progression_increment_kg' => 1.25,
        'target_rpe' => 8,
    ]);
    check($suggestion !== null && $suggestion['suggestion'] === 101.25, 'RPE progression uses the configured increment');

    $db->exec("INSERT INTO workouts (name, finished_at) VALUES ('Cardio record', '2026-01-01 10:00:00')");
    $cardio_workout_id = (int)$db->lastInsertId();
    $db->exec("INSERT INTO exercises (name, category) VALUES ('Test Bike', 'Cardio')");
    $cardio_exercise_id = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO workout_sets (workout_id, exercise_id, set_type, duration_sec, distance_km)
                  VALUES (?, ?, 'working', 1800, 10)")
        ->execute([$cardio_workout_id, $cardio_exercise_id]);

    rebuild_derived_records($db);
    check((int)$db->query('SELECT COUNT(*) FROM personal_records')->fetchColumn() === 1, 'strength records rebuild from completed sets');
    check((int)$db->query('SELECT COUNT(*) FROM cardio_records')->fetchColumn() === 3, 'cardio records rebuild from completed sets');
    $db->prepare('DELETE FROM workouts WHERE id = ?')->execute([$cardio_workout_id]);
    rebuild_derived_records($db);
    check((int)$db->query('SELECT COUNT(*) FROM cardio_records')->fetchColumn() === 0, 'deleted workout no longer contributes cardio records');

    // Cycle refresh fixtures: these exercise the live post-finish refresh without
    // ever loading or modifying the real Forge database.
    $db->prepare("INSERT INTO training_eras (name, starts_at, is_active, source) VALUES ('Cycle test bulk', '2026-01-01', 0, 'test')")->execute();
    $refresh_era = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO training_cycles (era_id, cycle_type, starts_at, ends_at, source) VALUES (?, 'bulk', '2026-01-01', '2026-01-14', 'auto')")
        ->execute([$refresh_era]);
    $weight = $db->prepare("INSERT INTO bodyweight_measurements (measured_at, weight_kg, era_id, source) VALUES (?, ?, ?, 'test')");
    $weight->execute(['2026-01-01 08:00:00', 90.0, $refresh_era]);
    $weight->execute(['2026-01-15 08:00:00', 89.4, $refresh_era]);
    $weight->execute(['2026-01-29 08:00:00', 88.7, $refresh_era]);
    $db->prepare("INSERT INTO workouts (name, era_id, started_at, finished_at) VALUES ('Cycle watch fixture', ?, '2026-01-29 09:00:00', '2026-01-29 10:00:00')")
        ->execute([$refresh_era]);
    $cycle_refresh = forge_refresh_active_cycle($db, (int)$db->lastInsertId());
    check($cycle_refresh !== null && $cycle_refresh['ends_at'] === '2026-01-29 10:00:00', 'cycle refresh extends the relevant cycle end');

    echo "PASS: FORGE regression checks\n";
} finally {
    putenv('FORGE_DB_PATH');
    if (isset($db_path) && is_file($db_path)) unlink($db_path);
}
