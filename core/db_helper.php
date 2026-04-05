<?php
/**
 * FORGE Database Helper
 */

date_default_timezone_set('Australia/Sydney');

function get_db_connection(): PDO {
    $dbPath = __DIR__ . '/../database/forge.db';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 5000;');
    $db->exec('PRAGMA journal_mode=WAL;');
    $db->exec('PRAGMA foreign_keys = ON;');
    maybe_migrate_schema($db);
    maybe_seed_exercises($db);
    return $db;
}

function maybe_migrate_schema(PDO $db): void {
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

    // workout_sets.is_pr flag (persisted so PR highlights survive page return)
    $ws_cols = array_column($db->query("PRAGMA table_info(workout_sets)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('is_pr', $ws_cols)) {
        $db->exec("ALTER TABLE workout_sets ADD COLUMN is_pr INTEGER NOT NULL DEFAULT 0");
    }

    // body_measurements table
    $db->exec("CREATE TABLE IF NOT EXISTS body_measurements (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        measured_at     TEXT NOT NULL DEFAULT (datetime('now')),
        waist_cm        REAL,
        neck_cm         REAL,
        hip_cm          REAL,
        caliper_bf_pct  REAL
    )");
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
 * Epley 1RM estimate. Only valid for reps > 1 and reps <= 12.
 */
function estimate_1rm(float $weight, int $reps, string $formula = 'epley'): ?float {
    if ($reps <= 1 || $reps > 12 || $weight <= 0) return null;
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
 * Check cardio PRs (avg_speed, cardiac_efficiency, best_distance) after a cardio set.
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

    $check  = $db->prepare("SELECT value FROM cardio_records WHERE exercise_id=? AND record_type=? ORDER BY value DESC LIMIT 1");
    $insert = $db->prepare("INSERT INTO cardio_records (exercise_id, record_type, value, duration_sec, distance_km, avg_hr, intensity) VALUES (?,?,?,?,?,?,?)");

    foreach ($metrics as $type => $value) {
        $check->execute([$exercise_id, $type]);
        $current = $check->fetchColumn();
        if ($current === false || $value > (float)$current) {
            $insert->execute([$exercise_id, $type, $value, $duration_sec, $distance_km, $hr_avg, $intensity]);
            $new_prs[] = $type;
        }
    }

    return $new_prs;
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
        GROUP BY w.id
        ORDER BY w.finished_at DESC
        LIMIT 1
    ");
    $stmt->execute([$exercise_id]);
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
            $message = "Push for {$reps_max} reps on all {$target_sets} sets (last best: {$min_reps})";
        }

    } elseif ($scheme === 'rpe') {
        $target_rpe = (float)($te['target_rpe'] ?? 8.0);
        $avg_rpe    = $last['avg_rpe'] ? round((float)$last['avg_rpe'], 1) : null;
        if ($avg_rpe && $avg_rpe < $target_rpe - 1) {
            $suggestion = $prog_weight + 2.5;
            $increase   = true;
            $message    = "RPE {$avg_rpe} (target {$target_rpe}) — add 2.5kg";
        } elseif ($avg_rpe && $avg_rpe > $target_rpe + 0.5) {
            $suggestion = max(0.0, $prog_weight - 2.5);
            $message    = "RPE {$avg_rpe} above target — consider −2.5kg";
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
