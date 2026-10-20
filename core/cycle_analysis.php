<?php
/** Shared, reproducible metrics for automatically detected training cycles. */
declare(strict_types=1);

/** Bump when forge_analyse_cycle() changes so stored cycle metrics are recomputed. */
const FORGE_CYCLE_METRICS_VERSION = 2;

const FORGE_CYCLE_BIG_FOUR = [
    'Barbell Back Squat', 'Barbell Bench Press', 'Deadlift', 'Overhead Press',
];

function forge_cycle_bodyweight(PDO $db, int $era_id, string $at): ?float {
    $before = $db->prepare('SELECT measured_at, weight_kg FROM bodyweight_measurements WHERE era_id = ? AND measured_at <= ? ORDER BY measured_at DESC LIMIT 1');
    $after = $db->prepare('SELECT measured_at, weight_kg FROM bodyweight_measurements WHERE era_id = ? AND measured_at >= ? ORDER BY measured_at ASC LIMIT 1');
    $before->execute([$era_id, $at]); $after->execute([$era_id, $at]);
    $a = $before->fetch(PDO::FETCH_ASSOC); $b = $after->fetch(PDO::FETCH_ASSOC);
    if (!$a && !$b) return null;
    if (!$a || !$b) return round((float)($a ?: $b)['weight_kg'], 1);
    $ta = strtotime($a['measured_at']); $tb = strtotime($b['measured_at']); $t = strtotime($at);
    if ($ta === $tb) return round((float)$a['weight_kg'], 1);
    return round((float)$a['weight_kg'] + (($t - $ta) / ($tb - $ta)) * ((float)$b['weight_kg'] - (float)$a['weight_kg']), 1);
}

/** Interpolate recorded caliper body-fat percentage at a point in time. */
/**
 * Value of $value_key at $at across chronological $points, linearly
 * interpolated between the nearest non-null readings and held flat beyond the
 * first or last one. Null when no point has a value.
 */
function forge_series_at(array $points, string $date_key, string $value_key, string $at): ?float {
    $t = strtotime($at); $before = null; $after = null;
    foreach ($points as $p) {
        if ($p[$value_key] === null) continue;
        $pt = strtotime($p[$date_key]);
        if ($pt <= $t) $before = $p;
        if ($pt >= $t) { $after = $p; break; }
    }
    if (!$before && !$after) return null;
    if (!$before || !$after) return (float)($before ?? $after)[$value_key];
    $tb = strtotime($before[$date_key]); $ta = strtotime($after[$date_key]);
    if ($ta === $tb) return (float)$before[$value_key];
    return (float)$before[$value_key] + (($t - $tb) / ($ta - $tb)) * ((float)$after[$value_key] - (float)$before[$value_key]);
}

/**
 * Body-fat readings in chronological order, each with its Navy and caliper
 * estimate (either may be null). Limited to one era unless $era_id is null.
 */
function forge_bodyfat_readings(PDO $db, ?int $era_id = null): array {
    $sex = get_user_setting($db, 'profile_sex', 'M');
    $height_cm = (float)(get_user_setting($db, 'profile_height_cm', '0') ?: 0);
    $stmt = $db->prepare('SELECT id, era_id, measured_at, DATE(measured_at) AS date, waist_cm, neck_cm, hip_cm, caliper_bf_pct
        FROM body_measurements' . ($era_id !== null ? ' WHERE era_id = ?' : '') . ' ORDER BY measured_at ASC');
    $stmt->execute($era_id !== null ? [$era_id] : []);
    $readings = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['navy'] = $height_cm > 0 && $row['waist_cm'] && $row['neck_cm']
            ? forge_navy_bodyfat_pct($sex, $height_cm, (float)$row['waist_cm'], (float)$row['neck_cm'], $row['hip_cm'] !== null ? (float)$row['hip_cm'] : null)
            : null;
        $row['caliper'] = $row['caliper_bf_pct'] ? (float)$row['caliper_bf_pct'] : null;
        $readings[] = $row;
    }
    return $readings;
}

/**
 * Navy, caliper and smoothed body fat at $at. Each method is a separate series,
 * interpolated between its own readings and held at its latest value, so an
 * entry (or a day) without one method does not drop it from the average.
 */
function forge_bodyfat_at(array $readings, string $at): array {
    $n = forge_series_at($readings, 'measured_at', 'navy', $at);
    $c = forge_series_at($readings, 'measured_at', 'caliper', $at);
    $vs = array_filter([$n, $c], fn($v) => $v !== null);
    return [
        'navy' => $n !== null ? round($n, 1) : null,
        'caliper' => $c !== null ? round($c, 1) : null,
        'smoothed' => count($vs) ? round(array_sum($vs) / count($vs), 1) : null,
    ];
}

/** Smoothed body fat within an era at $at; pass $readings when calling in a loop. */
function forge_cycle_bodyfat(PDO $db, int $era_id, string $at, ?array $readings = null): ?float {
    return forge_bodyfat_at($readings ?? forge_bodyfat_readings($db, $era_id), $at)['smoothed'];
}

/**
 * Return a compact analysis payload. Lift snapshots are deliberately based on
 * the first and final logged exposure inside the cycle—not arbitrary calendar
 * windows—so short cycles cannot read future workouts as their baseline.
 */
function forge_analyse_cycle(PDO $db, array $cycle): array {
    $era_id = (int)$cycle['era_id']; $start = $cycle['starts_at']; $end = $cycle['ends_at'];
    $cycle_type = $cycle['cycle_type'] ?? '';
    $bounds = [$era_id, $start, $end];
    $metrics = [
        'start_weight' => forge_cycle_bodyweight($db, $era_id, $start),
        'end_weight' => forge_cycle_bodyweight($db, $era_id, $end),
    ];
    if ($metrics['start_weight'] !== null && $metrics['end_weight'] !== null) {
        $metrics['change_kg'] = round($metrics['end_weight'] - $metrics['start_weight'], 1);
        $days = max(1, (strtotime($end) - strtotime($start)) / 86400);
        $metrics['weekly_rate_kg'] = round($metrics['change_kg'] / $days * 7, 2);
    }
    $bf_readings = forge_bodyfat_readings($db, $era_id);
    $metrics['body_fat_start_pct'] = forge_cycle_bodyfat($db, $era_id, $start, $bf_readings);
    $metrics['body_fat_end_pct'] = forge_cycle_bodyfat($db, $era_id, $end, $bf_readings);
    if ($metrics['body_fat_start_pct'] !== null && $metrics['body_fat_end_pct'] !== null) {
        $metrics['body_fat_change_pct'] = round($metrics['body_fat_end_pct'] - $metrics['body_fat_start_pct'], 1);
    }
    if ($metrics['start_weight'] !== null && $metrics['body_fat_start_pct'] !== null) {
        $metrics['start_lean_mass_kg'] = round($metrics['start_weight'] * (1 - $metrics['body_fat_start_pct'] / 100), 1);
    }
    if ($metrics['end_weight'] !== null && $metrics['body_fat_end_pct'] !== null) {
        $metrics['end_lean_mass_kg'] = round($metrics['end_weight'] * (1 - $metrics['body_fat_end_pct'] / 100), 1);
    }
    if (isset($metrics['start_lean_mass_kg'], $metrics['end_lean_mass_kg'])) {
        $metrics['lean_mass_change_kg'] = round($metrics['end_lean_mass_kg'] - $metrics['start_lean_mass_kg'], 1);
    }

    $dose = $db->prepare("SELECT COUNT(DISTINCT w.id) sessions, COUNT(ws.id) working_sets,
        COALESCE(SUM(ws.weight_kg * ws.reps), 0) volume_kg
        FROM workouts w LEFT JOIN workout_sets ws ON ws.workout_id = w.id AND ws.set_type = 'working'
        WHERE w.era_id = ? AND w.finished_at IS NOT NULL AND w.started_at >= ? AND w.started_at <= ?");
    $dose->execute($bounds); $metrics += $dose->fetch(PDO::FETCH_ASSOC) ?: [];
    $days = max(1, (strtotime($end) - strtotime($start)) / 86400);
    $metrics['sessions_per_week'] = round((float)($metrics['sessions'] ?? 0) / $days * 7, 1);

    $weekly = $db->prepare("SELECT strftime('%Y-%W', w.started_at) AS week, COUNT(DISTINCT w.id) AS sessions
        FROM workouts w WHERE w.era_id = ? AND w.finished_at IS NOT NULL AND w.started_at >= ? AND w.started_at <= ? GROUP BY week");
    $weekly->execute($bounds); $weeks = $weekly->fetchAll(PDO::FETCH_ASSOC);
    $metrics['active_weeks'] = count($weeks);
    $metrics['missed_weeks'] = max(0, (int)ceil($days / 7) - count($weeks));

    $ph = implode(',', array_fill(0, count(FORGE_CYCLE_BIG_FOUR), '?'));
    $lifts = $db->prepare("SELECT e.name, w.started_at, MAX(ws.weight_kg * (1 + ws.reps / 30.0)) AS e1rm
        FROM workout_sets ws JOIN workouts w ON w.id = ws.workout_id JOIN exercises e ON e.id = ws.exercise_id
        WHERE w.era_id = ? AND w.finished_at IS NOT NULL AND ws.set_type = 'working'
          AND e.name IN ($ph) AND w.started_at >= ? AND w.started_at <= ?
        GROUP BY e.name, DATE(w.started_at) ORDER BY e.name, w.started_at");
    $lifts->execute([$era_id, ...FORGE_CYCLE_BIG_FOUR, $start, $end]);
    $by_lift = [];
    foreach ($lifts->fetchAll(PDO::FETCH_ASSOC) as $row) $by_lift[$row['name']][] = $row;
    $changes = []; $lift_metrics = [];
    foreach ($by_lift as $name => $rows) {
        $first = $rows[0]; $last = $rows[count($rows) - 1];
        // A detraining block may contain only the final session before the
        // layoff. Its end snapshot is the first exposure after return, so the
        // handoff to the next cycle remains meaningful rather than 0 → 0.
        if ($cycle_type === 'detraining') {
            $baseline = $db->prepare("SELECT w.started_at, MAX(ws.weight_kg * (1 + ws.reps / 30.0)) AS e1rm
                FROM workout_sets ws JOIN workouts w ON w.id = ws.workout_id JOIN exercises e ON e.id = ws.exercise_id
                WHERE w.era_id=? AND w.finished_at IS NOT NULL AND ws.set_type='working' AND e.name=? AND w.started_at<=?
                GROUP BY DATE(w.started_at) ORDER BY w.started_at DESC LIMIT 1");
            $baseline->execute([$era_id, $name, $start]);
            $previous = $baseline->fetch(PDO::FETCH_ASSOC);
            if ($previous) $first = $previous;
            $handoff = $db->prepare("SELECT w.started_at, MAX(ws.weight_kg * (1 + ws.reps / 30.0)) AS e1rm
                FROM workout_sets ws JOIN workouts w ON w.id = ws.workout_id JOIN exercises e ON e.id = ws.exercise_id
                WHERE w.era_id=? AND w.finished_at IS NOT NULL AND ws.set_type='working' AND e.name=? AND w.started_at>?
                GROUP BY DATE(w.started_at) ORDER BY w.started_at ASC LIMIT 1");
            $handoff->execute([$era_id, $name, $end]);
            $next = $handoff->fetch(PDO::FETCH_ASSOC);
            if ($next) $last = $next;
        }
        $from = round((float)$first['e1rm'], 1); $to = round((float)$last['e1rm'], 1);
        if ($from <= 0) continue;
        $pct = round(($to / $from - 1) * 100, 1);
        $from_bw = forge_cycle_bodyweight($db, $era_id, $first['started_at']);
        $to_bw = forge_cycle_bodyweight($db, $era_id, $last['started_at']);
        $from_bf = forge_cycle_bodyfat($db, $era_id, $first['started_at'], $bf_readings);
        $to_bf = forge_cycle_bodyfat($db, $era_id, $last['started_at'], $bf_readings);
        $from_lbm = $from_bw !== null && $from_bf !== null ? $from_bw * (1 - $from_bf / 100) : null;
        $to_lbm = $to_bw !== null && $to_bf !== null ? $to_bw * (1 - $to_bf / 100) : null;
        $lift_metrics[$name] = [
            'start_e1rm' => $from, 'end_e1rm' => $to, 'change_kg' => round($to - $from, 1), 'change_pct' => $pct,
            'start_at' => $first['started_at'], 'end_at' => $last['started_at'],
            'start_bw_kg' => $from_bw, 'end_bw_kg' => $to_bw,
            'start_e1rm_per_bw' => $from_bw ? round($from / $from_bw, 2) : null,
            'end_e1rm_per_bw' => $to_bw ? round($to / $to_bw, 2) : null,
            'start_e1rm_per_lbm' => $from_lbm ? round($from / $from_lbm, 2) : null,
            'end_e1rm_per_lbm' => $to_lbm ? round($to / $to_lbm, 2) : null,
        ];
        $changes[] = $pct;
    }
    $metrics['lift_metrics'] = $lift_metrics;
    $metrics['strength_lifts'] = count($changes);
    if ($changes) $metrics['strength_change_pct'] = round(array_sum($changes) / count($changes), 1);
    if (!empty($metrics['change_kg']) && isset($metrics['strength_change_pct']) && (float)$metrics['change_kg'] !== 0.0) {
        $metrics['strength_pct_per_kg'] = round($metrics['strength_change_pct'] / $metrics['change_kg'], 2);
    }
    $metrics['metrics_version'] = FORGE_CYCLE_METRICS_VERSION;
    return $metrics;
}

/**
 * Recompute stored metrics written by an older forge_analyse_cycle(), keeping
 * each cycle's boundaries and any detector-only keys. Returns cycles updated.
 */
function forge_refresh_stale_cycle_metrics(PDO $db): int {
    $stale = $db->query("SELECT * FROM training_cycles WHERE COALESCE(json_extract(metrics_json, '$.metrics_version'), 0) < " . FORGE_CYCLE_METRICS_VERSION);
    $update = $db->prepare('UPDATE training_cycles SET metrics_json = ? WHERE id = ?');
    $n = 0;
    foreach ($stale->fetchAll(PDO::FETCH_ASSOC) as $cycle) {
        $old = json_decode($cycle['metrics_json'] ?? '{}', true) ?: [];
        $update->execute([json_encode(array_merge($old, forge_analyse_cycle($db, $cycle))), (int)$cycle['id']]);
        $n++;
    }
    return $n;
}

/**
 * Refresh the currently open auto cycle after a completed workout.
 * This deliberately does not create a new phase from a single session: it only
 * extends the current cycle. Full boundary reassessment remains an explicit
 * preview-and-apply action on the Cycles page.
 */
function forge_refresh_active_cycle(PDO $db, int $workout_id): ?array {
    $workout = $db->prepare('SELECT id, era_id, started_at, finished_at FROM workouts WHERE id = ? AND finished_at IS NOT NULL');
    $workout->execute([$workout_id]);
    $workout = $workout->fetch(PDO::FETCH_ASSOC);
    if (!$workout || !$workout['era_id']) return null;

    $cycle_stmt = $db->prepare('SELECT * FROM training_cycles WHERE era_id = ? AND starts_at <= ? ORDER BY starts_at DESC LIMIT 1');
    $cycle_stmt->execute([(int)$workout['era_id'], $workout['started_at']]);
    $cycle = $cycle_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cycle) return null;

    $end_at = max($cycle['ends_at'], $workout['finished_at']);
    $cycle['ends_at'] = $end_at;
    $metrics = forge_analyse_cycle($db, $cycle);
    $db->prepare('UPDATE training_cycles SET ends_at = ?, metrics_json = ? WHERE id = ?')
        ->execute([$end_at, json_encode($metrics), (int)$cycle['id']]);

    return ['cycle_id' => (int)$cycle['id'], 'cycle_type' => $cycle['cycle_type'], 'ends_at' => $end_at];
}
