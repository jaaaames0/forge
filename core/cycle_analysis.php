<?php
/** Shared, reproducible metrics for automatically detected training cycles. */
declare(strict_types=1);

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
function forge_cycle_bodyfat(PDO $db, int $era_id, string $at): ?float {
    $before = $db->prepare('SELECT measured_at, caliper_bf_pct FROM body_measurements WHERE era_id = ? AND caliper_bf_pct IS NOT NULL AND measured_at <= ? ORDER BY measured_at DESC LIMIT 1');
    $after = $db->prepare('SELECT measured_at, caliper_bf_pct FROM body_measurements WHERE era_id = ? AND caliper_bf_pct IS NOT NULL AND measured_at >= ? ORDER BY measured_at ASC LIMIT 1');
    $before->execute([$era_id, $at]); $after->execute([$era_id, $at]);
    $a = $before->fetch(PDO::FETCH_ASSOC); $b = $after->fetch(PDO::FETCH_ASSOC);
    if (!$a && !$b) return null;
    if (!$a || !$b) return round((float)($a ?: $b)['caliper_bf_pct'], 1);
    $ta = strtotime($a['measured_at']); $tb = strtotime($b['measured_at']); $t = strtotime($at);
    if ($ta === $tb) return round((float)$a['caliper_bf_pct'], 1);
    return round((float)$a['caliper_bf_pct'] + (($t - $ta) / ($tb - $ta)) * ((float)$b['caliper_bf_pct'] - (float)$a['caliper_bf_pct']), 1);
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
    $metrics['body_fat_start_pct'] = forge_cycle_bodyfat($db, $era_id, $start);
    $metrics['body_fat_end_pct'] = forge_cycle_bodyfat($db, $era_id, $end);
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
        $from_bf = forge_cycle_bodyfat($db, $era_id, $first['started_at']);
        $to_bf = forge_cycle_bodyfat($db, $era_id, $last['started_at']);
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
    return $metrics;
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
