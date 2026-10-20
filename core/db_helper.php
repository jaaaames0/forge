<?php
/**
 * FORGE Database Helper
 */

date_default_timezone_set('Australia/Sydney');

const FORGE_SCHEMA_VERSION = 7;

function get_db_connection(): PDO {
    $dbPath = getenv('FORGE_DB_PATH') ?: __DIR__ . '/../database/forge.db';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 5000;');
    $db->exec('PRAGMA journal_mode=WAL;');
    $db->exec('PRAGMA foreign_keys = ON;');
    maybe_migrate_schema($db);
    maybe_seed_exercises($db);
    backfill_current_bodyweight_measurements($db);
    return $db;
}

/**
 * Estimate body fat with the circumference (US Navy) method.
 *
 * FORGE stores all body measurements in centimetres, while the published
 * constants expect inches. Convert at the boundary so every caller uses the
 * same, unit-correct calculation.
 */
function forge_navy_bodyfat_pct(string $sex, float $height_cm, ?float $waist_cm, ?float $neck_cm, ?float $hip_cm = null): ?float {
    if ($height_cm <= 0 || $waist_cm === null || $neck_cm === null || $waist_cm <= $neck_cm) return null;
    $height_in = $height_cm / 2.54;
    $waist_in  = $waist_cm / 2.54;
    $neck_in   = $neck_cm / 2.54;
    if ($sex === 'F') {
        if ($hip_cm === null || $hip_cm <= 0) return null;
        $circumference_in = $waist_in + ($hip_cm / 2.54) - $neck_in;
        if ($circumference_in <= 0) return null;
        return round(163.205 * log10($circumference_in) - 97.684 * log10($height_in) - 78.387, 1);
    }
    return round(86.01 * log10($waist_in - $neck_in) - 70.041 * log10($height_in) + 36.76, 1);
}

/**
 * Men's single-site skinfold chart from the caliper booklet, as the formula it
 * was printed from: a fit over every cell matches the chart to its rounding
 * (RMS 0.03 points), so this removes the 2 mm bands and 5-year age brackets.
 * Coefficients for 1, mm, mm², age (years, fractional).
 */
const FORGE_SKINFOLD_MEN = ['coef' => [-5.807, 1.3753, -0.017386, 0.2129], 'min_mm' => 2.0, 'max_mm' => 36.0];

/** Body-fat % from a skinfold reading, or null outside the chart's range or for women (no chart yet). */
function forge_skinfold_bodyfat_pct(string $sex, float $mm, float $age_years): ?float {
    if ($sex === 'F' || $mm < FORGE_SKINFOLD_MEN['min_mm'] || $mm > FORGE_SKINFOLD_MEN['max_mm'] || $age_years <= 0) return null;
    [$a, $b, $c, $d] = FORGE_SKINFOLD_MEN['coef'];
    return round($a + $b * $mm + $c * $mm * $mm + $d * $age_years, 1);
}

/** Age in fractional years on $at, or null without a valid date of birth. */
function forge_age_years(string $dob, string $at): ?float {
    $born = strtotime($dob); $t = strtotime($at);
    if ($born === false || $t === false || $t <= $born) return null;
    return ($t - $born) / (365.2425 * 86400);
}

/** Keep current-era workout weigh-ins available for interpolation. */
function backfill_current_bodyweight_measurements(PDO $db): void {
    $era_id = $db->query('SELECT id FROM training_eras WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
    if (!$era_id) return;
    $stmt = $db->prepare("INSERT INTO bodyweight_measurements (measured_at, weight_kg, era_id, workout_id, source)
        SELECT w.started_at, w.bodyweight_kg, w.era_id, w.id, 'workout'
        FROM workouts w
        WHERE w.era_id = ? AND w.bodyweight_kg IS NOT NULL AND w.bodyweight_kg > 0
          AND NOT EXISTS (SELECT 1 FROM bodyweight_measurements bm WHERE bm.workout_id = w.id AND bm.source = 'workout')");
    $stmt->execute([$era_id]);
}

function maybe_migrate_schema(PDO $db): void {
    // Older installations predate schema tracking. Run the compatibility upgrade
    // once, then avoid repeated PRAGMA/DDL work on every page request.
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version INTEGER PRIMARY KEY,
        applied_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $current_version = (int)$db->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn();
    if ($current_version >= FORGE_SCHEMA_VERSION) return;

    // exercises.bw_factor
    $ex_cols = array_column($db->query("PRAGMA table_info(exercises)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('bw_factor', $ex_cols)) {
        $db->exec("ALTER TABLE exercises ADD COLUMN bw_factor REAL DEFAULT NULL");
        // Backfill known bodyweight exercises
        $bw_factors = [
            'Pull-Up'           => 0.95,
            'Chin-Up'           => 0.95,
            'Dip'               => 0.90,
            'Push-Up'           => 0.67,
            'Hanging Leg Raise' => 0.35,
            'Glute Bridge'      => 0.50,
            'Plank'             => 0.00,
        ];
        $stmt = $db->prepare("UPDATE exercises SET bw_factor = ? WHERE name = ?");
        foreach ($bw_factors as $name => $factor) {
            $stmt->execute([$factor, $name]);
        }
    }

    // workout_sets new columns
    $set_cols = array_column($db->query("PRAGMA table_info(workout_sets)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('added_weight_kg', $set_cols)) {
        $db->exec("ALTER TABLE workout_sets ADD COLUMN added_weight_kg REAL DEFAULT NULL");
    }
    if (!in_array('hr_avg', $set_cols)) {
        $db->exec("ALTER TABLE workout_sets ADD COLUMN hr_avg INTEGER DEFAULT NULL");
        $db->exec("ALTER TABLE workout_sets ADD COLUMN hr_max INTEGER DEFAULT NULL");
    }
    if (!in_array('intensity', $set_cols)) {
        $db->exec("ALTER TABLE workout_sets ADD COLUMN intensity INTEGER DEFAULT NULL");
    }

    // exercises.rest_category
    $ex_cols2 = array_column($db->query("PRAGMA table_info(exercises)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('rest_category', $ex_cols2)) {
        $db->exec("ALTER TABLE exercises ADD COLUMN rest_category TEXT DEFAULT NULL");

        // Backfill — order matters: most specific last so it wins
        // 1. Isolation: Dumbbell, Cable, and single-joint Machine
        $db->exec("UPDATE exercises SET rest_category = 'isolation'
                   WHERE category IN ('Dumbbell','Cable') OR category = 'Machine'");
        // 2. Bodyweight easy exercises
        $db->exec("UPDATE exercises SET rest_category = 'bodyweight'
                   WHERE category = 'Bodyweight'");
        // 3. All Barbell → compound
        $db->exec("UPDATE exercises SET rest_category = 'compound'
                   WHERE category = 'Barbell'");
        // 4. Compound machines and BW compound movements
        $compound_names = ['Leg Press','Chest Press Machine','Seated Row Machine',
                           'Shoulder Press Machine','Lat Pulldown Machine','Assisted Pull-Up Machine',
                           'Pull-Up','Chin-Up','Dip'];
        $ph = implode(',', array_fill(0, count($compound_names), '?'));
        $db->prepare("UPDATE exercises SET rest_category = 'compound' WHERE name IN ($ph)")
           ->execute($compound_names);
        // 5. Big 4 override
        $big4 = ['Barbell Back Squat','Barbell Front Squat','Deadlift',
                 'Barbell Bench Press','Incline Barbell Bench','Overhead Press'];
        $ph = implode(',', array_fill(0, count($big4), '?'));
        $db->prepare("UPDATE exercises SET rest_category = 'compound_big4' WHERE name IN ($ph)")
           ->execute($big4);
    }

    // Default category rest times
    foreach ([
        'rest_compound_big4_sec' => '300',
        'rest_compound_sec'      => '240',
        'rest_bodyweight_sec'    => '180',
        'rest_isolation_sec'     => '120',
    ] as $key => $default) {
        $db->prepare("INSERT OR IGNORE INTO user_settings (key, value) VALUES (?, ?)")
           ->execute([$key, $default]);
    }

    // workout_exercises table
    $db->exec("CREATE TABLE IF NOT EXISTS workout_exercises (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        workout_id  INTEGER NOT NULL REFERENCES workouts(id) ON DELETE CASCADE,
        exercise_id INTEGER NOT NULL REFERENCES exercises(id),
        order_num   INTEGER DEFAULT 0,
        target_sets INTEGER DEFAULT 3,
        UNIQUE(workout_id, exercise_id)
    )");
    $we_cols = array_column($db->query("PRAGMA table_info(workout_exercises)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('target_sets', $we_cols)) {
        $db->exec("ALTER TABLE workout_exercises ADD COLUMN target_sets INTEGER DEFAULT 3");
    }
    if (!in_array('progression_applied_weight', $we_cols)) {
        $db->exec("ALTER TABLE workout_exercises ADD COLUMN progression_applied_weight REAL DEFAULT NULL");
    }

    // cardio_records table
    $db->exec("CREATE TABLE IF NOT EXISTS cardio_records (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        exercise_id INTEGER NOT NULL REFERENCES exercises(id),
        record_type TEXT NOT NULL,
        value       REAL NOT NULL,
        duration_sec  INTEGER,
        distance_km   REAL,
        avg_hr        INTEGER,
        intensity     INTEGER,
        achieved_at   TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    // A/B variant columns
    $te_cols  = array_column($db->query("PRAGMA table_info(template_exercises)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $tpl_cols = array_column($db->query("PRAGMA table_info(templates)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('ab_group', $te_cols)) {
        $db->exec("ALTER TABLE template_exercises ADD COLUMN ab_group TEXT DEFAULT NULL");
    }
    if (!in_array('last_ab_variant', $tpl_cols)) {
        $db->exec("ALTER TABLE templates ADD COLUMN last_ab_variant TEXT DEFAULT NULL");
    }

    // workouts.ab_variant — records which A/B group was used for this session;
    // only written to templates.last_ab_variant on finish (not on start) so
    // cancelling a workout doesn't advance the A/B counter
    $wk_cols = array_column($db->query("PRAGMA table_info(workouts)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('ab_variant', $wk_cols)) {
        $db->exec("ALTER TABLE workouts ADD COLUMN ab_variant TEXT DEFAULT NULL");
    }

    // workout_sets remaining columns
    $ws_cols = array_column($db->query("PRAGMA table_info(workout_sets)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('is_pr', $ws_cols)) {
        $db->exec("ALTER TABLE workout_sets ADD COLUMN is_pr INTEGER NOT NULL DEFAULT 0");
    }
    if (!in_array('logged_at', $ws_cols)) {
        $db->exec("ALTER TABLE workout_sets ADD COLUMN logged_at TEXT DEFAULT NULL");
    }
    if (!in_array('elevation_m', $ws_cols)) {
        $db->exec("ALTER TABLE workout_sets ADD COLUMN elevation_m REAL DEFAULT NULL");
    }

    // body_measurements table
    $db->exec("CREATE TABLE IF NOT EXISTS body_measurements (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        measured_at     TEXT NOT NULL DEFAULT (datetime('now')),
        waist_cm        REAL,
        neck_cm         REAL,
        hip_cm          REAL,
        caliper_bf_pct  REAL,
        source          TEXT DEFAULT 'manual'
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS bodyweight_measurements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        measured_at TEXT NOT NULL,
        weight_kg REAL NOT NULL,
        era_id INTEGER DEFAULT NULL REFERENCES training_eras(id),
        workout_id INTEGER DEFAULT NULL REFERENCES workouts(id) ON DELETE SET NULL,
        source TEXT DEFAULT 'workout'
    )");

    // Training eras keep historical imports available without allowing them to
    // distort the current training cycle's progression and records.
    $db->exec("CREATE TABLE IF NOT EXISTS training_eras (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        starts_at TEXT NOT NULL,
        ends_at TEXT DEFAULT NULL,
        is_active INTEGER NOT NULL DEFAULT 0,
        source TEXT NOT NULL DEFAULT 'forge',
        notes TEXT DEFAULT NULL
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS training_cycles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        era_id INTEGER NOT NULL REFERENCES training_eras(id) ON DELETE CASCADE,
        cycle_type TEXT NOT NULL,
        starts_at TEXT NOT NULL,
        ends_at TEXT NOT NULL,
        label TEXT DEFAULT NULL,
        source TEXT NOT NULL DEFAULT 'auto',
        confidence REAL DEFAULT NULL,
        metrics_json TEXT DEFAULT NULL
    )");
    $wk_cols = array_column($db->query("PRAGMA table_info(workouts)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('era_id', $wk_cols)) {
        $db->exec("ALTER TABLE workouts ADD COLUMN era_id INTEGER DEFAULT NULL REFERENCES training_eras(id)");
    }
    $bm_cols = array_column($db->query("PRAGMA table_info(body_measurements)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('era_id', $bm_cols)) {
        $db->exec("ALTER TABLE body_measurements ADD COLUMN era_id INTEGER DEFAULT NULL REFERENCES training_eras(id)");
    }
    if (!in_array('caliper_mm', $bm_cols)) {
        $db->exec("ALTER TABLE body_measurements ADD COLUMN caliper_mm REAL DEFAULT NULL");
    }
    if (!in_array('source', $bm_cols)) {
        $db->exec("ALTER TABLE body_measurements ADD COLUMN source TEXT DEFAULT 'manual'");
    }

    $active_era = $db->query("SELECT id FROM training_eras WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
    if (!$active_era) {
        $first_workout = $db->query("SELECT MIN(started_at) FROM workouts")->fetchColumn();
        $starts_at = $first_workout ?: date('Y-m-d H:i:s');
        $db->prepare("INSERT INTO training_eras (name, starts_at, is_active, source) VALUES (?, ?, 1, 'forge')")
           ->execute(['Forge — current era', $starts_at]);
        $active_era = (int)$db->lastInsertId();
    }
    $db->prepare("UPDATE workouts SET era_id = ? WHERE era_id IS NULL")->execute([$active_era]);
    $db->prepare("UPDATE body_measurements SET era_id = ? WHERE era_id IS NULL")->execute([$active_era]);

    // Keep the common history and dashboard lookups fast as workout history grows.
    $db->exec('CREATE INDEX IF NOT EXISTS idx_workout_sets_workout ON workout_sets(workout_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_workout_sets_exercise_workout ON workout_sets(exercise_id, workout_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_workouts_finished_started ON workouts(finished_at, started_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_workouts_era_finished ON workouts(era_id, finished_at, started_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_training_cycles_era_dates ON training_cycles(era_id, starts_at, ends_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_personal_records_exercise_1rm ON personal_records(exercise_id, estimated_1rm DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_cardio_records_exercise_type_value ON cardio_records(exercise_id, record_type, value DESC)');
    $db->prepare('INSERT OR IGNORE INTO schema_migrations (version) VALUES (?)')
       ->execute([FORGE_SCHEMA_VERSION]);
}

/** Ensure that a workout can still accept live-session mutations. */
function require_active_workout(PDO $db, int $workout_id): void {
    $stmt = $db->prepare('SELECT 1 FROM workouts WHERE id = ? AND finished_at IS NULL');
    $stmt->execute([$workout_id]);
    if (!$stmt->fetchColumn()) {
        throw new Exception('Workout is not active');
    }
}

/** Return the era used for current-cycle views and new workouts. */
function active_training_era_id(PDO $db): int {
    $id = $db->query('SELECT id FROM training_eras WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
    if (!$id) throw new RuntimeException('No active training era configured');
    return (int)$id;
}

/** User-facing cycle terminology, kept separate from stored identifiers. */
function forge_cycle_label(string $type): string {
    return $type === 'maintenance' ? 'Recomposition' : ucfirst($type);
}

function workout_last_activity_at(PDO $db, int $workout_id, string $started_at): string {
    $stmt = $db->prepare("SELECT MAX(activity_at) FROM (
        SELECT logged_at AS activity_at FROM workout_sets WHERE workout_id = ? AND logged_at IS NOT NULL
        UNION ALL
        SELECT sampled_at AS activity_at FROM workout_hr_samples WHERE workout_id = ?
    )");
    $stmt->execute([$workout_id, $workout_id]);
    return $stmt->fetchColumn() ?: $started_at;
}

/**
 * Close abandoned sessions on the next Forge interaction. A session with no
 * logged set is discarded; otherwise it ends shortly after its last meaningful
 * activity so accidental overnight timers do not distort history.
 */
function cleanup_stale_active_workouts(PDO $db, int $idle_seconds = 3600): array {
    $active = $db->query("SELECT id, started_at FROM workouts WHERE finished_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    $closed = [];
    foreach ($active as $workout) {
        $last_activity = workout_last_activity_at($db, (int)$workout['id'], $workout['started_at']);
        if (time() - strtotime($last_activity) < $idle_seconds) continue;

        $set_count = $db->prepare('SELECT COUNT(*) FROM workout_sets WHERE workout_id = ?');
        $set_count->execute([(int)$workout['id']]);
        if (!(int)$set_count->fetchColumn()) {
            $db->prepare('DELETE FROM workouts WHERE id = ?')->execute([(int)$workout['id']]);
            $closed[] = ['id' => (int)$workout['id'], 'discarded' => true];
            continue;
        }

        $finished_at = date('Y-m-d H:i:s', strtotime($last_activity . ' +2 minutes'));
        $db->prepare('UPDATE workouts SET finished_at = ? WHERE id = ?')->execute([$finished_at, (int)$workout['id']]);
        $closed[] = ['id' => (int)$workout['id'], 'discarded' => false];
    }
    if ($closed) rebuild_derived_records($db);
    return $closed;
}

/** Ensure an exercise was intentionally added to this active workout. */
function require_active_workout_exercise(PDO $db, int $workout_id, int $exercise_id): void {
    require_active_workout($db, $workout_id);
    $stmt = $db->prepare('SELECT 1 FROM workout_exercises WHERE workout_id = ? AND exercise_id = ?');
    $stmt->execute([$workout_id, $exercise_id]);
    if (!$stmt->fetchColumn()) {
        throw new Exception('Exercise is not part of this workout');
    }
}

function get_user_setting(PDO $db, string $key, string $default = ''): string {
    $stmt = $db->prepare("SELECT value FROM user_settings WHERE key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

function set_user_setting(PDO $db, string $key, string $value): void {
    $db->prepare("INSERT OR REPLACE INTO user_settings (key, value) VALUES (?, ?)")
       ->execute([$key, $value]);
}

/**
 * Epley 1RM estimate. Valid for reps > 1 and reps <= 30.
 * Formula accuracy degrades above ~12 reps but remains consistent for relative PR comparison.
 */
function estimate_1rm(float $weight, int $reps, string $formula = 'epley'): ?float {
    if ($reps <= 1 || $reps > 30 || $weight <= 0) return null;
    if ($formula === 'brzycki') {
        return round($weight / (1.0278 - 0.0278 * $reps), 1);
    }
    return round($weight * (1 + $reps / 30), 1);
}

/**
 * Check if a new set beats the current PR for an exercise and insert/update if so.
 * Returns the new estimated 1RM if a PR was set, null otherwise.
 */
function maybe_update_pr(PDO $db, int $exercise_id, float $weight_kg, int $reps, string $formula = 'epley'): ?float {
    $new_1rm = estimate_1rm($weight_kg, $reps, $formula);
    if ($new_1rm === null) return null;

    $stmt = $db->prepare("SELECT id, estimated_1rm FROM personal_records WHERE exercise_id = ? ORDER BY estimated_1rm DESC LIMIT 1");
    $stmt->execute([$exercise_id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current || $new_1rm > (float)$current['estimated_1rm']) {
        $db->prepare("INSERT INTO personal_records (exercise_id, weight_kg, reps, estimated_1rm) VALUES (?, ?, ?, ?)")
           ->execute([$exercise_id, $weight_kg, $reps, $new_1rm]);
        return $new_1rm;
    }
    return null;
}

/**
 * Check cardio PRs (avg_speed, cardiac_efficiency, best_distance, best_duration) after a cardio set.
 * Returns array of record_type strings that were newly set, empty if no PRs.
 */
function maybe_update_cardio_pr(PDO $db, int $exercise_id, ?int $duration_sec, ?float $distance_km, ?int $hr_avg, ?int $intensity): array {
    $new_prs = [];

    $avg_speed = null;
    if ($duration_sec > 0 && $distance_km > 0) {
        $avg_speed = round($distance_km / ($duration_sec / 3600.0), 4); // km/h
    }

    $efficiency = null;
    if ($avg_speed !== null && $hr_avg > 0) {
        $efficiency = round($avg_speed / $hr_avg * 1000, 4); // higher = fitter
    }

    $metrics = [];
    if ($avg_speed  !== null) $metrics['avg_speed']           = $avg_speed;
    if ($efficiency !== null) $metrics['cardiac_efficiency']  = $efficiency;
    if ($distance_km > 0)     $metrics['best_distance']       = $distance_km;
    if ($duration_sec > 0)    $metrics['best_duration']      = $duration_sec; // longest continuous effort

    $check  = $db->prepare("SELECT value FROM cardio_records WHERE exercise_id=? AND record_type=? ORDER BY value DESC LIMIT 1");
    $insert = $db->prepare("INSERT INTO cardio_records (exercise_id, record_type, value, duration_sec, distance_km, avg_hr, intensity) VALUES (?,?,?,?,?,?,?)");

    foreach ($metrics as $type => $value) {
        $check->execute([$exercise_id, $type]);
        $current = $check->fetchColumn();
        $is_duration = ($type === 'best_duration');
        if ($current === false || ($is_duration ? $value > (float)$current : $value > (float)$current)) {
            $insert->execute([$exercise_id, $type, $value, $duration_sec, $distance_km, $hr_avg, $intensity]);
            $new_prs[] = $type;
        }
    }

    return $new_prs;
}

/**
 * Rebuild record summaries from completed workouts.
 *
 * personal_records and cardio_records are derived data, not the source of
 * truth. Rebuilding them after deleting history prevents stale PRs, cardio
 * records, and set badges from surviving after their source workout is gone.
 * Call this inside an existing transaction.
 */
function rebuild_derived_records(PDO $db): void {
    $era_id = active_training_era_id($db);
    $db->exec('DELETE FROM personal_records');
    $db->exec('DELETE FROM cardio_records');
    $db->exec("UPDATE workout_sets SET is_pr = 0
               WHERE workout_id IN (SELECT id FROM workouts WHERE finished_at IS NOT NULL)");

    $sets = $db->query("
        SELECT ws.id, ws.exercise_id, ws.weight_kg, ws.reps, ws.duration_sec,
               ws.distance_km, ws.hr_avg, ws.intensity, e.category, w.finished_at
        FROM workout_sets ws
        JOIN workouts w ON w.id = ws.workout_id
        JOIN exercises e ON e.id = ws.exercise_id
        WHERE w.finished_at IS NOT NULL AND ws.set_type = 'working' AND (w.era_id = ? OR w.era_id IS NULL)
        ORDER BY w.finished_at ASC, w.id ASC, ws.id ASC
    ");
    $sets->execute([$era_id]);
    $sets = $sets->fetchAll(PDO::FETCH_ASSOC);

    $strength_bests = [];
    $cardio_bests = [];
    $insert_strength = $db->prepare(
        'INSERT INTO personal_records (exercise_id, weight_kg, reps, estimated_1rm, achieved_at) VALUES (?, ?, ?, ?, ?)'
    );
    $insert_cardio = $db->prepare(
        'INSERT INTO cardio_records (exercise_id, record_type, value, duration_sec, distance_km, avg_hr, intensity, achieved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $mark_pr = $db->prepare('UPDATE workout_sets SET is_pr = 1 WHERE id = ?');

    foreach ($sets as $set) {
        $exercise_id = (int)$set['exercise_id'];
        $is_pr = false;

        if ($set['category'] === 'Cardio') {
            $duration = $set['duration_sec'] !== null ? (int)$set['duration_sec'] : null;
            $distance = $set['distance_km'] !== null ? (float)$set['distance_km'] : null;
            $hr_avg = $set['hr_avg'] !== null ? (int)$set['hr_avg'] : null;
            $metrics = [];
            if ($duration !== null && $duration > 0 && $distance !== null && $distance > 0) {
                $speed = round($distance / ($duration / 3600.0), 4);
                $metrics['avg_speed'] = $speed;
                if ($hr_avg !== null && $hr_avg > 0) {
                    $metrics['cardiac_efficiency'] = round($speed / $hr_avg * 1000, 4);
                }
            }
            if ($distance !== null && $distance > 0) $metrics['best_distance'] = $distance;
            if ($duration !== null && $duration > 0) $metrics['best_duration'] = $duration;

            foreach ($metrics as $type => $value) {
                $key = "{$exercise_id}:{$type}";
                if (!isset($cardio_bests[$key]) || $value > $cardio_bests[$key]) {
                    $insert_cardio->execute([
                        $exercise_id, $type, $value, $duration, $distance, $hr_avg,
                        $set['intensity'] !== null ? (int)$set['intensity'] : null,
                        $set['finished_at'],
                    ]);
                    $cardio_bests[$key] = $value;
                    $is_pr = true;
                }
            }
        } elseif ($set['weight_kg'] !== null && $set['reps'] !== null) {
            $estimated = estimate_1rm((float)$set['weight_kg'], (int)$set['reps']);
            if ($estimated !== null && (!isset($strength_bests[$exercise_id]) || $estimated > $strength_bests[$exercise_id])) {
                $insert_strength->execute([
                    $exercise_id, (float)$set['weight_kg'], (int)$set['reps'], $estimated, $set['finished_at'],
                ]);
                $strength_bests[$exercise_id] = $estimated;
                $is_pr = true;
            }
        }

        if ($is_pr) $mark_pr->execute([(int)$set['id']]);
    }
}

/**
 * Compute a progression suggestion for an exercise given its template_exercise config.
 * Returns array with keys: suggestion, increase, message, scheme, is_bw
 * Returns null if no progression scheme is configured.
 */
function compute_progression_suggestion(PDO $db, array $te): ?array {
    if (empty($te['progression_scheme'])) return null;

    $exercise_id = (int)$te['exercise_id'];
    $scheme      = $te['progression_scheme'];
    $increment   = (float)($te['progression_increment_kg'] ?? 2.5);
    $era_id      = active_training_era_id($db);

    // Last completed session for this exercise (aggregated per workout)
    $stmt = $db->prepare("
        SELECT COUNT(ws.id) as set_count,
               AVG(ws.rpe) as avg_rpe,
               MIN(ws.reps) as min_reps,
               MAX(ws.reps) as max_reps,
               MAX(ws.weight_kg) as max_weight,
               MAX(ws.added_weight_kg) as max_added_weight
        FROM workout_sets ws
        JOIN workouts w ON w.id = ws.workout_id
        WHERE ws.exercise_id = ?
          AND ws.set_type = 'working'
          AND w.finished_at IS NOT NULL
          AND (w.era_id = ? OR w.era_id IS NULL)
        GROUP BY w.id
        ORDER BY w.finished_at DESC
        LIMIT 1
    ");
    $stmt->execute([$exercise_id, $era_id]);
    $last = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$last) {
        return ['suggestion' => null, 'increase' => false,
                'message' => 'First time — log this session to start tracking', 'scheme' => $scheme, 'is_bw' => false];
    }

    $is_bw       = $last['max_added_weight'] !== null;
    $prog_weight = $is_bw ? (float)$last['max_added_weight'] : (float)$last['max_weight'];
    $suggestion  = $prog_weight;
    $increase    = false;
    $message     = '';

    if ($scheme === 'linear') {
        $target_sets = (int)($te['target_sets'] ?? 3);
        $target_reps = (int)($te['target_reps_min'] ?? 5);
        $hit_all = (int)$last['set_count'] >= $target_sets
                && (int)$last['min_reps'] >= $target_reps;
        if ($hit_all) {
            $suggestion = $prog_weight + $increment;
            $increase   = true;
            $message    = "All {$target_sets}×{$target_reps} hit — add {$increment}kg";
        } else {
            $message = "Push for {$target_sets}×{$target_reps} reps";
        }

    } elseif ($scheme === 'double') {
        $reps_min    = (int)($te['target_reps_min'] ?? 8);
        $reps_max    = (int)($te['target_reps_max'] ?? 12);
        $target_sets = (int)($te['target_sets'] ?? 3);
        $min_reps    = (int)$last['min_reps'];
        $set_count   = (int)$last['set_count'];
        $hit_top     = $min_reps >= $reps_max && $set_count >= $target_sets;
        if ($hit_top) {
            $suggestion = $prog_weight + $increment;
            $increase   = true;
            $message    = "All {$target_sets} sets hit {$reps_max} — add {$increment}kg, aim {$reps_min}+";
        } else {
            $message = "Push for {$reps_max} reps on all {$target_sets} sets";
        }

    } elseif ($scheme === 'rpe') {
        $target_rpe = (float)($te['target_rpe'] ?? 8.0);
        $avg_rpe    = $last['avg_rpe'] ? round((float)$last['avg_rpe'], 1) : null;
        if ($avg_rpe && $avg_rpe < $target_rpe - 1) {
            $suggestion = $prog_weight + $increment;
            $increase   = true;
            $message    = "RPE {$avg_rpe} (target {$target_rpe}) — add {$increment}kg";
        } elseif ($avg_rpe && $avg_rpe > $target_rpe + 0.5) {
            $suggestion = max(0.0, $prog_weight - $increment);
            $message    = "RPE {$avg_rpe} above target — consider −{$increment}kg";
        } else {
            $message = $avg_rpe ? "RPE {$avg_rpe} on target — maintain weight" : "Log RPE to enable auto-regulation";
        }
    }

    return compact('suggestion', 'increase', 'message', 'scheme', 'is_bw');
}

/**
 * Seed exercises table on first run.
 */
function maybe_seed_exercises(PDO $db): void {
    $count = $db->query("SELECT COUNT(*) FROM exercises")->fetchColumn();
    if ($count > 0) return;

    // [name, category, muscle_primary, muscle_secondary, bw_factor]
    $exercises = [
        // Barbell — Legs
        ['Barbell Back Squat',      'Barbell', 'Legs',      'Glutes',    null],
        ['Barbell Front Squat',     'Barbell', 'Legs',      'Glutes',    null],
        ['Romanian Deadlift',       'Barbell', 'Legs',      'Back',      null],
        ['Barbell Hip Thrust',      'Barbell', 'Glutes',    'Legs',      null],

        // Barbell — Back/Pull
        ['Deadlift',                'Barbell', 'Back',      'Legs',      null],
        ['Barbell Row',             'Barbell', 'Back',      'Arms',      null],
        ['Pendlay Row',             'Barbell', 'Back',      'Arms',      null],

        // Barbell — Push
        ['Barbell Bench Press',     'Barbell', 'Chest',     'Shoulders', null],
        ['Incline Barbell Bench',   'Barbell', 'Chest',     'Shoulders', null],
        ['Overhead Press',          'Barbell', 'Shoulders', 'Arms',      null],

        // Dumbbell — Push
        ['Dumbbell Bench Press',    'Dumbbell', 'Chest',    'Shoulders', null],
        ['Incline Dumbbell Press',  'Dumbbell', 'Chest',    'Shoulders', null],
        ['Dumbbell Shoulder Press', 'Dumbbell', 'Shoulders','Arms',      null],
        ['Dumbbell Lateral Raise',  'Dumbbell', 'Shoulders', null,       null],
        ['Dumbbell Front Raise',    'Dumbbell', 'Shoulders', null,       null],

        // Dumbbell — Pull
        ['Dumbbell Row',            'Dumbbell', 'Back',     'Arms',      null],
        ['Dumbbell Rear Delt Fly',  'Dumbbell', 'Shoulders','Back',      null],
        ['Dumbbell Bicep Curl',     'Dumbbell', 'Arms',     null,        null],
        ['Hammer Curl',             'Dumbbell', 'Arms',     null,        null],

        // Dumbbell — Legs
        ['Dumbbell Goblet Squat',   'Dumbbell', 'Legs',     'Glutes',    null],
        ['Dumbbell Lunge',          'Dumbbell', 'Legs',     'Glutes',    null],
        ['Dumbbell Romanian Deadlift','Dumbbell','Legs',    'Glutes',    null],

        // Cable
        ['Cable Row',               'Cable',    'Back',     'Arms',      null],
        ['Cable Lat Pulldown',      'Cable',    'Back',     'Arms',      null],
        ['Cable Fly',               'Cable',    'Chest',    null,        null],
        ['Cable Tricep Pushdown',   'Cable',    'Arms',     null,        null],
        ['Cable Bicep Curl',        'Cable',    'Arms',     null,        null],
        ['Cable Lateral Raise',     'Cable',    'Shoulders', null,       null],
        ['Cable Face Pull',         'Cable',    'Shoulders','Back',      null],
        ['Cable Crunch',            'Cable',    'Core',     null,        null],

        // Machine
        ['Leg Press',               'Machine',  'Legs',     'Glutes',    null],
        ['Leg Extension',           'Machine',  'Legs',     null,        null],
        ['Leg Curl',                'Machine',  'Legs',     'Glutes',    null],
        ['Hip Abductor Machine',    'Machine',  'Glutes',   'Legs',      null],
        ['Chest Press Machine',     'Machine',  'Chest',    'Shoulders', null],
        ['Seated Row Machine',      'Machine',  'Back',     'Arms',      null],
        ['Shoulder Press Machine',  'Machine',  'Shoulders','Arms',      null],
        ['Lat Pulldown Machine',    'Machine',  'Back',     'Arms',      null],
        ['Assisted Pull-Up Machine','Machine',  'Back',     'Arms',      null],
        ['Pec Deck',                'Machine',  'Chest',    null,        null],

        // Bodyweight — bw_factor = fraction of bodyweight lifted (research-backed estimates)
        ['Pull-Up',                 'Bodyweight','Back',    'Arms',      0.95],
        ['Chin-Up',                 'Bodyweight','Arms',    'Back',      0.95],
        ['Dip',                     'Bodyweight','Chest',   'Arms',      0.90],
        ['Push-Up',                 'Bodyweight','Chest',   'Shoulders', 0.67],
        ['Plank',                   'Bodyweight','Core',    null,        0.00],
        ['Hanging Leg Raise',       'Bodyweight','Core',    null,        0.35],
        ['Glute Bridge',            'Bodyweight','Glutes',  'Legs',      0.50],

        // Cardio — tracked by time/distance/HR, no weight
        ['Treadmill',               'Cardio',   'Cardio',   null,        null],
        ['Rowing Machine',          'Cardio',   'Cardio',   null,        null],
        ['Stationary Bike',         'Cardio',   'Cardio',   null,        null],
        ['Stair Climber',           'Cardio',   'Cardio',   null,        null],
    ];

    $stmt = $db->prepare("INSERT INTO exercises (name, category, muscle_primary, muscle_secondary, bw_factor) VALUES (?, ?, ?, ?, ?)");
    foreach ($exercises as $e) {
        $stmt->execute($e);
    }
}
