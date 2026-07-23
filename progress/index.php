<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/db_helper.php';
require_once __DIR__ . '/../core/cycle_analysis.php';

$db   = get_db_connection();
$unit = 'kg';
$active_era_id = active_training_era_id($db);
$cycles = $db->query("SELECT c.*, e.name AS era_name FROM training_cycles c JOIN training_eras e ON e.id = c.era_id ORDER BY c.starts_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$cycle_context = (string)($_GET['cycle'] ?? 'current');
$cycle_context_request = $cycle_context;
$requested_section = (string)($_GET['section'] ?? 'strength');
$progress_section = in_array($requested_section, ['strength', 'cardio', 'body'], true) ? $requested_section : 'strength';
$active_cycle = null;
foreach ($cycles as $cycle) if ((int)$cycle['era_id'] === $active_era_id) { $active_cycle = $cycle; break; }
$selected_cycle = null;
if ($cycle_context === 'current') {
    $selected_cycle = $active_cycle;
} elseif (ctype_digit($cycle_context)) {
    foreach ($cycles as $cycle) if ((int)$cycle['id'] === (int)$cycle_context) { $selected_cycle = $cycle; break; }
}
$scope_label = 'All time'; $scope_clause = ''; $scope_params = [];
if ($selected_cycle) {
    $scope_label = forge_cycle_label($selected_cycle['cycle_type']) . ' · ' . date('M Y', strtotime($selected_cycle['starts_at'])) . '–' . date('M Y', strtotime($selected_cycle['ends_at']));
    $scope_clause = ' AND w.era_id = ? AND w.started_at >= ? AND w.started_at <= ?';
    $scope_params = [(int)$selected_cycle['era_id'], $selected_cycle['starts_at'], $selected_cycle['ends_at']];
    $cycle_context = (string)$selected_cycle['id'];
} elseif ($cycle_context === 'era') {
    $scope_label = 'Current era'; $scope_clause = ' AND w.era_id = ?'; $scope_params = [$active_era_id];
} elseif ($cycle_context !== 'all') {
    $cycle_context = 'all';
}

$scope_start_at = $selected_cycle['starts_at'] ?? null;
$scope_end_at = $selected_cycle['ends_at'] ?? ($cycle_context === 'era' ? date('Y-m-d H:i:s') : null);
$scrub_dates = [];
if ($selected_cycle) {
    $scrub_stmt = $db->prepare('SELECT DISTINCT DATE(started_at) AS date FROM workouts WHERE era_id=? AND finished_at IS NOT NULL AND started_at>=? AND started_at<=? ORDER BY started_at');
    $scrub_stmt->execute([(int)$selected_cycle['era_id'], $selected_cycle['starts_at'], $selected_cycle['ends_at']]);
    $scrub_dates = $scrub_stmt->fetchAll(PDO::FETCH_COLUMN);
}
$requested_as_of = (string)($_GET['as_of'] ?? '');
$as_of_at = null;
if ($scope_end_at && $requested_as_of !== '') {
    $candidate = strtotime($requested_as_of . ' 23:59:59');
    if ($candidate !== false) $as_of_at = date('Y-m-d H:i:s', min(strtotime($scope_end_at), max(strtotime((string)$scope_start_at), $candidate)));
}
$scrub_index = max(0, count($scrub_dates) - 1);
if ($scrub_dates && $requested_as_of !== '') {
    foreach ($scrub_dates as $i => $date) if (strtotime($date) <= strtotime($requested_as_of)) $scrub_index = $i;
}
if ($as_of_at) {
    $scope_clause = ' AND w.era_id = ?' . ($scope_start_at ? ' AND w.started_at >= ?' : '') . ' AND w.started_at <= ?';
    $scope_params = $scope_start_at ? [(int)$selected_cycle['era_id'], $scope_start_at, $as_of_at] : [$active_era_id, $as_of_at];
}

// ── BODYWEIGHT (most recent logged, default 80 kg) ─────────────────────────
if ($selected_cycle) {
    $bw = (float)(forge_cycle_bodyweight($db, (int)$selected_cycle['era_id'], $as_of_at ?: $selected_cycle['ends_at']) ?: 80.0);
} else {
    $bw_stmt = $db->prepare("SELECT bodyweight_kg FROM workouts w WHERE bodyweight_kg IS NOT NULL AND bodyweight_kg > 0{$scope_clause} ORDER BY started_at DESC LIMIT 1");
    $bw_stmt->execute($scope_params); $bw = (float)($bw_stmt->fetchColumn() ?: 80.0);
}

// ── PROFILE DATA ──────────────────────────────────────────────────────────────
$profile_sex   = get_user_setting($db, 'profile_sex', 'M');
$height_cm     = (float)(get_user_setting($db, 'profile_height_cm', 0) ?: 0);
$profile_dob   = get_user_setting($db, 'profile_dob', '');
$resting_hr    = (int)(get_user_setting($db, 'profile_resting_hr', 0) ?: 0);
$max_hr_stored = (int)(get_user_setting($db, 'profile_max_hr', 0) ?: 0);
$is_female     = ($profile_sex === 'F');

$age = 0;
if ($profile_dob) {
    $dob_dt = new DateTime($profile_dob, new DateTimeZone('Australia/Sydney'));
    $now_dt = new DateTime('now', new DateTimeZone('Australia/Sydney'));
    $age    = (int)$dob_dt->diff($now_dt)->y;
}
$max_hr = $max_hr_stored ?: ($age > 0 ? 220 - $age : 0);
$vo2max = ($max_hr > 0 && $resting_hr > 0) ? round(15 * $max_hr / $resting_hr, 1) : null;

// ── STRENGTH STANDARDS ─────────────────────────────────────────────────────
// Source: Symmetric Strength. Male reference at 80 kg, female at 60 kg.
// Scaled to user bodyweight via allometric formula: standard × (bw / base)^0.67
$level_names = ['Untrained', 'Beginner', 'Novice', 'Intermediate', 'Advanced', 'Elite'];
$bw_base     = $is_female ? 60.0 : 80.0;
$bw_scale    = pow($bw / $bw_base, 0.67);

$big4_def = $is_female ? [
    'squat'    => ['name' => 'Barbell Back Squat',  'ref' => [28,  50,  65,  84, 107, 133]],
    'bench'    => ['name' => 'Barbell Bench Press', 'ref' => [17,  30,  39,  51,  65,  81]],
    'deadlift' => ['name' => 'Deadlift',            'ref' => [33,  59,  77,  99, 126, 157]],
    'ohp'      => ['name' => 'Overhead Press',      'ref' => [10,  18,  24,  31,  40,  50]],
] : [
    'squat'    => ['name' => 'Barbell Back Squat',  'ref' => [47,  84,  109, 139, 178, 222]],
    'bench'    => ['name' => 'Barbell Bench Press', 'ref' => [32,  57,   74,  96, 123, 154]],
    'deadlift' => ['name' => 'Deadlift',            'ref' => [56, 100,  131, 167, 213, 265]],
    'ohp'      => ['name' => 'Overhead Press',      'ref' => [20,  35,   46,  59,  76,  95]],
];

// Resolve IDs + PRs for all 4 lifts
$all_names = array_column($big4_def, 'name');
$ph        = implode(',', array_fill(0, count($all_names), '?'));
$id_stmt   = $db->prepare("SELECT id, name FROM exercises WHERE name IN ($ph)");
$id_stmt->execute($all_names);
$ex_id_map = [];
foreach ($id_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $ex_id_map[$row['name']] = (int)$row['id'];

$big4 = [];
foreach ($big4_def as $key => $def) {
    $scaled = array_map(fn($v) => (int)round($v * $bw_scale), $def['ref']);
    $eid    = $ex_id_map[$def['name']] ?? null;
    $pr     = 0.0;
    if ($eid) {
        $pr_stmt = $db->prepare("SELECT ws.weight_kg * (1 + ws.reps / 30.0) FROM workout_sets ws JOIN workouts w ON w.id = ws.workout_id WHERE ws.exercise_id = ? AND ws.set_type = 'working' AND ws.reps BETWEEN 1 AND 30 AND ws.weight_kg > 0 AND w.finished_at IS NOT NULL{$scope_clause} ORDER BY w.started_at DESC, ws.id DESC LIMIT 1");
        $pr_stmt->execute([$eid, ...$scope_params]); $pr = (float)($pr_stmt->fetchColumn() ?: 0);
    }
    $big4[$key] = ['name' => $def['name'], 'exercise_id' => $eid, 'scaled' => $scaled, 'current_1rm' => round($pr, 1)];
}

// 5RM variants (Epley: 5RM = 1RM × 6/7 ≈ 0.857)
$rm5_mult = 6.0 / 7.0;
$big4_5rm = [];
foreach ($big4 as $key => $lift) {
    $big4_5rm[$key] = $lift;
    $big4_5rm[$key]['scaled']      = array_map(fn($v) => (int)round($v * $rm5_mult), $lift['scaled']);
    $big4_5rm[$key]['current_1rm'] = round($lift['current_1rm'] * $rm5_mult, 1);
}

// ── PR BOARD ───────────────────────────────────────────────────────────────
$pr_board_stmt = $db->prepare("SELECT ws.exercise_id, ROUND(MAX(ws.weight_kg * (1 + ws.reps / 30.0)), 1) AS estimated_1rm,
    e.name AS exercise_name, e.muscle_primary, e.category, COUNT(DISTINCT w.id) AS pr_count, MAX(w.started_at) AS last_pr_at
    FROM workout_sets ws JOIN workouts w ON w.id = ws.workout_id JOIN exercises e ON e.id = ws.exercise_id
    WHERE ws.set_type = 'working' AND ws.reps BETWEEN 1 AND 30 AND ws.weight_kg > 0 AND w.finished_at IS NOT NULL{$scope_clause}
    GROUP BY ws.exercise_id ORDER BY pr_count DESC, estimated_1rm DESC");
$pr_board_stmt->execute($scope_params); $pr_board = $pr_board_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── CARDIO PRs ─────────────────────────────────────────────────────────────
$cardio_raw = $db->query("
    SELECT cr.*, e.name as exercise_name
    FROM cardio_records cr
    JOIN exercises e ON e.id = cr.exercise_id
    WHERE cr.id = (
        SELECT id FROM cardio_records c2
        WHERE c2.exercise_id = cr.exercise_id AND c2.record_type = cr.record_type
        ORDER BY c2.value DESC LIMIT 1
    )
    ORDER BY e.name, cr.record_type
")->fetchAll(PDO::FETCH_ASSOC);

$cardio_prs = [];
foreach ($cardio_raw as $r) {
    if (!isset($cardio_prs[$r['exercise_name']])) {
        $cardio_prs[$r['exercise_name']] = ['_exercise_id' => (int)$r['exercise_id']];
    }
    $cardio_prs[$r['exercise_name']][$r['record_type']] = $r;
}

// ── CARDIO TOTALS ──────────────────────────────────────────────────────────
$ctotals_sql = "
    SELECT ROUND(SUM(COALESCE(ws.distance_km, 0)), 1) as total_km,
           SUM(COALESCE(ws.duration_sec, 0)) as total_sec,
           COUNT(DISTINCT ws.workout_id) as sessions
    FROM workout_sets ws
    JOIN workouts w ON w.id = ws.workout_id
    JOIN exercises e ON e.id = ws.exercise_id
    WHERE w.finished_at IS NOT NULL AND e.category = 'Cardio'{$scope_clause}";
$ctotals_stmt = $db->prepare($ctotals_sql); $ctotals_stmt->execute($scope_params);
$ctotals = $ctotals_stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_km' => 0, 'total_sec' => 0, 'sessions' => 0];
$total_hrs = $ctotals['total_sec'] > 0 ? round($ctotals['total_sec'] / 3600, 1) : 0;

// ── BODY DATA ─────────────────────────────────────────────────────────────────
$body_scope = $selected_cycle ? ' AND measured_at >= ? AND measured_at <= ?' : '';
$body_scope_params = $selected_cycle ? [$selected_cycle['starts_at'], $as_of_at ?: $selected_cycle['ends_at']] : [];
$body_era_clause = $cycle_context === 'all' ? '' : ' AND era_id = ?';
$body_era_params = $cycle_context === 'all' ? [] : [$selected_cycle ? (int)$selected_cycle['era_id'] : $active_era_id];
$body_stmt = $db->prepare("
    SELECT id, measured_at, waist_cm, neck_cm, hip_cm, caliper_bf_pct
    FROM body_measurements WHERE 1 = 1{$body_era_clause}{$body_scope} ORDER BY measured_at DESC LIMIT 10
");
$body_stmt->execute([...$body_era_params, ...$body_scope_params]);
$body_measurements = $body_stmt->fetchAll(PDO::FETCH_ASSOC);

$latest_meas = $body_measurements[0] ?? null;

// Navy BF% from latest measurement
$navy_bf    = null;
$caliper_bf = $selected_cycle
    ? forge_cycle_bodyfat($db, (int)$selected_cycle['era_id'], $as_of_at ?: $selected_cycle['ends_at'])
    : ($latest_meas ? (float)($latest_meas['caliper_bf_pct'] ?: 0) ?: null : null);
if ($latest_meas && $height_cm > 0 && $latest_meas['waist_cm'] && $latest_meas['neck_cm']) {
    if ($is_female && $latest_meas['hip_cm']) {
        $navy_bf = round(163.205 * log10((float)$latest_meas['waist_cm'] + (float)$latest_meas['hip_cm'] - (float)$latest_meas['neck_cm']) - 97.684 * log10($height_cm) - 78.387, 1);
    } else {
        $navy_bf = round(86.01 * log10((float)$latest_meas['waist_cm'] - (float)$latest_meas['neck_cm']) - 70.041 * log10($height_cm) + 36.76, 1);
    }
}
$bf_vals     = array_filter([$navy_bf, $caliper_bf], fn($v) => $v !== null);
$smoothed_bf = count($bf_vals) ? round(array_sum($bf_vals) / count($bf_vals), 1) : null;
$lean_mass   = ($smoothed_bf !== null && $bw > 0) ? round($bw * (1 - $smoothed_bf / 100), 1) : null;
$fat_mass    = ($smoothed_bf !== null && $bw > 0) ? round($bw * ($smoothed_bf / 100), 1) : null;
$bmi         = ($height_cm > 0 && $bw > 0) ? round($bw / pow($height_cm / 100, 2), 1) : null;
$whr         = ($height_cm > 0 && $latest_meas && $latest_meas['waist_cm']) ? round((float)$latest_meas['waist_cm'] / $height_cm, 3) : null;

// Bodyweight trend (last 180 days — one point per workout)
$bw_date_clause = ($selected_cycle || $cycle_context === 'all') ? '' : " AND started_at >= date('now', '-180 days')";
$workout_body_scope = $selected_cycle ? ' AND started_at >= ? AND started_at <= ?' : '';
$bw_stmt = $db->prepare("
    SELECT DATE(started_at) as date, ROUND(bodyweight_kg, 1) as bw
    FROM workouts
    WHERE bodyweight_kg IS NOT NULL AND bodyweight_kg > 0
      {$body_era_clause}{$workout_body_scope}{$bw_date_clause}
    ORDER BY started_at
");
$bw_stmt->execute([...$body_era_params, ...$body_scope_params]);
$bw_trend = $bw_stmt->fetchAll(PDO::FETCH_ASSOC);

// BF% trend (chronological for chart)
$bf_stmt = $db->prepare("
    SELECT DATE(measured_at) as date, waist_cm, neck_cm, hip_cm, caliper_bf_pct
    FROM body_measurements WHERE 1 = 1{$body_era_clause}{$body_scope} ORDER BY measured_at ASC
");
$bf_stmt->execute([...$body_era_params, ...$body_scope_params]);
$bf_trend_raw = $bf_stmt->fetchAll(PDO::FETCH_ASSOC);

// Pre-compute BF% for each measurement for chart
$bf_trend = [];
foreach ($bf_trend_raw as $row) {
    $n = null;
    if ($height_cm > 0 && $row['waist_cm'] && $row['neck_cm']) {
        if ($is_female && $row['hip_cm']) {
            $n = round(163.205 * log10((float)$row['waist_cm'] + (float)$row['hip_cm'] - (float)$row['neck_cm']) - 97.684 * log10($height_cm) - 78.387, 1);
        } else {
            $n = round(86.01 * log10((float)$row['waist_cm'] - (float)$row['neck_cm']) - 70.041 * log10($height_cm) + 36.76, 1);
        }
    }
    $c = $row['caliper_bf_pct'] ? (float)$row['caliper_bf_pct'] : null;
    $vs = array_filter([$n, $c], fn($v) => $v !== null);
    $bf_trend[] = ['date' => $row['date'], 'navy' => $n, 'caliper' => $c, 'smoothed' => count($vs) ? round(array_sum($vs)/count($vs),1) : null];
}
// Preserve a meaningful BF line even when the selected cycle sits between two
// measurements: interpolation is based on the complete era, not clipped data.
if ($selected_cycle) {
    foreach ([$selected_cycle['starts_at'], $as_of_at ?: $selected_cycle['ends_at']] as $boundary) {
        $bf = forge_cycle_bodyfat($db, (int)$selected_cycle['era_id'], $boundary);
        if ($bf !== null) $bf_trend[] = ['date' => date('Y-m-d', strtotime($boundary)), 'navy' => null, 'caliper' => $bf, 'smoothed' => $bf];
    }
    usort($bf_trend, fn($a, $b) => strcmp($a['date'], $b['date']));
}

// Estimate BF% at every bodyweight entry by linearly interpolating between
// measurements. Before the first and after the last measurement, retain the
// nearest value so recording a new BF% cannot create an artificial step change.
$bf_anchors = array_values(array_filter($bf_trend, fn($row) => $row['smoothed'] !== null));
$lbm_trend = [];
if ($bf_anchors) {
    foreach ($bw_trend as $weight_row) {
        $date_ts = strtotime($weight_row['date']);
        $before = null;
        $after = null;
        foreach ($bf_anchors as $anchor) {
            $anchor_ts = strtotime($anchor['date']);
            if ($anchor_ts <= $date_ts) $before = $anchor;
            if ($anchor_ts >= $date_ts) {
                $after = $anchor;
                break;
            }
        }

        if ($before === null) {
            $estimated_bf = (float)$bf_anchors[0]['smoothed'];
        } elseif ($after === null) {
            $estimated_bf = (float)$bf_anchors[count($bf_anchors) - 1]['smoothed'];
        } else {
            $before_ts = strtotime($before['date']);
            $after_ts = strtotime($after['date']);
            $fraction = $after_ts === $before_ts ? 0 : ($date_ts - $before_ts) / ($after_ts - $before_ts);
            $estimated_bf = (float)$before['smoothed'] + $fraction * ((float)$after['smoothed'] - (float)$before['smoothed']);
        }

        $lbm_trend[] = [
            'date' => $weight_row['date'],
            'lbm' => round((float)$weight_row['bw'] * (1 - $estimated_bf / 100), 1),
            'bf_pct' => round($estimated_bf, 1),
        ];
    }
}

// ── STRENGTH RATIOS ────────────────────────────────────────────────────────
$s1 = $big4['squat']['current_1rm'];
$b1 = $big4['bench']['current_1rm'];
$d1 = $big4['deadlift']['current_1rm'];
$o1 = $big4['ohp']['current_1rm'];

function ratio_status(float $r, float $lo, float $hi): string {
    if ($r >= $lo && $r <= $hi) return 'ok';
    if ($r < $lo * 0.88 || $r > $hi * 1.12) return 'off';
    return 'close';
}
$ratios = [];
if ($s1 > 0 && $b1 > 0) $ratios[] = ['label' => 'Squat / Bench',    'ratio' => round($s1/$b1,2), 'target' => '1.25–1.40', 'lo' => 1.25, 'hi' => 1.40, 'desc' => 'Squat should be 1.25–1.40× bench'];
if ($d1 > 0 && $s1 > 0) $ratios[] = ['label' => 'Deadlift / Squat', 'ratio' => round($d1/$s1,2), 'target' => '1.20–1.35', 'lo' => 1.20, 'hi' => 1.35, 'desc' => 'Deadlift should be 1.20–1.35× squat'];
if ($b1 > 0 && $o1 > 0) $ratios[] = ['label' => 'Bench / OHP',      'ratio' => round($b1/$o1,2), 'target' => '1.50–1.75', 'lo' => 1.50, 'hi' => 1.75, 'desc' => 'Bench should be 1.50–1.75× OHP'];

$page_title   = 'Progress';
$page_context = 'progress';
$extra_head   = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';
include __DIR__ . '/../core/page_head.php';

// ── STANDARDS CARD HELPER ──────────────────────────────────────────────────
function standards_card(array $lift, array $level_names, string $unit): string {
    $levels  = $lift['scaled'];
    $current = (float)$lift['current_1rm'];
    $max     = $levels[5] * 1.10;

    $lv = -1;
    for ($i = 0; $i < 6; $i++) { if ($current >= $levels[$i]) $lv = $i; }

    $fill_pct = $current > 0 ? min(100, round($current / $max * 100, 2)) : 0;

    if ($lv < 0 && $current > 0) {
        $badge = "<span class='std-badge' style='background:#37474f;color:#90a4ae;'>Below Untrained</span>";
    } elseif ($lv < 0) {
        $badge = "<span class='std-badge' style='background:#1a1a1a;color:#888;'>No Data</span>";
    } elseif ($lv < 2) {
        $badge = "<span class='std-badge' style='background:#1a2a37;color:#64b5f6;'>{$level_names[$lv]}</span>";
    } elseif ($lv < 4) {
        $badge = "<span class='std-badge' style='background:#1a2e1a;color:#81c784;'>{$level_names[$lv]}</span>";
    } else {
        $badge = "<span class='std-badge' style='background:#3e1a00;color:#FF5722;'>{$level_names[$lv]}</span>";
    }

    if ($current > 0 && $lv < 5) {
        $next = $levels[$lv + 1];
        $gap  = (int)ceil($next - $current);
        $sub  = "Next: <strong style='color:#e0e0e0;'>{$level_names[$lv+1]}</strong> at {$next} {$unit} &nbsp;<span style='color:#FF5722;font-weight:700;'>+{$gap} {$unit}</span>";
    } elseif ($lv === 5) {
        $sub = "<span style='color:#FF5722;font-weight:700;'>Elite level achieved ✓</span>";
    } else {
        $sub = "Log a working set to see your level";
    }

    // Tick marks and labels
    $ticks = '';
    $lbls  = '';
    for ($i = 0; $i < 6; $i++) {
        $pct   = round($levels[$i] / $max * 100, 2);
        $abbr  = strtoupper(substr($level_names[$i], 0, 3));
        $col   = ($i === $lv && $current > 0) ? '#FF5722' : '#888';
        $wcol  = ($i === $lv && $current > 0) ? '#FF5722' : '#888';
        $ticks .= "<div style='position:absolute;left:{$pct}%;top:0;height:100%;width:1px;background:#0a0a0a;'></div>";
        $lbls  .= "<div style='position:absolute;left:{$pct}%;transform:translateX(-50%);text-align:center;font-size:0.52rem;font-weight:700;color:{$col};line-height:1.3;white-space:nowrap;'>
            <div>{$abbr}</div><div style='color:{$wcol};'>{$levels[$i]}</div></div>";
    }

    // User marker + label
    // Layout: wrapper has padding-top:26px so bar sits 26px from wrapper top.
    // Label: top:4px (inside the 26px padding space, above the bar).
    // Marker: top:23px height:16px (starts 3px above bar, spans bar, 3px below).
    $marker = $mlabel = '';
    if ($fill_pct > 0) {
        $marker = "<div style='position:absolute;left:{$fill_pct}%;top:23px;height:16px;width:2px;background:#fff;border-radius:1px;transform:translateX(-50%);z-index:2;'></div>";
        $mlabel = "<div style='position:absolute;left:{$fill_pct}%;top:4px;transform:translateX(-50%);font-size:0.65rem;font-weight:900;color:#FF5722;white-space:nowrap;'>" . round($current) . " {$unit}</div>";
    }

    $link = $lift['exercise_id'] ? "href='/forge/exercises/?id={$lift['exercise_id']}'" : '';

    return "
    <div class='card standards-card mb-2'>
        <div class='card-body py-2 px-3'>
            <div class='d-flex justify-content-between align-items-center mb-1'>
                <a {$link} class='fw-black text-decoration-none' style='font-size:0.88rem;color:#e0e0e0;letter-spacing:-0.3px;'>{$lift['name']}</a>
                {$badge}
            </div>
            <div class='text-muted mb-3' style='font-size:0.72rem;'>{$sub}</div>
            <div style='position:relative;padding-top:26px;padding-bottom:34px;'>
                {$mlabel}
                <div style='height:10px;border-radius:5px;background:#1e1e1e;overflow:hidden;position:relative;'>
                    <div style='height:100%;width:{$fill_pct}%;background:linear-gradient(90deg,#bf360c,#FF5722);'></div>
                    {$ticks}
                </div>
                {$marker}
                <div style='position:relative;margin-top:6px;height:32px;'>{$lbls}</div>
            </div>
        </div>
    </div>";
}
?>

<div class="container-fluid py-3 px-3" style="max-width:700px;margin:0 auto;">

    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="fw-black mb-0" style="font-size:1.6rem;letter-spacing:-1.5px;">
            PROGRESS<span style="color:#FF5722;">_</span>
        </h1>
        <div class="text-muted" style="font-size:0.7rem;font-weight:700;">BW <?= round($bw, 1) ?> kg</div>
    </div>

    <!-- Segmented toggle -->
    <div class="d-flex mb-3" style="background:#111;border-radius:8px;padding:3px;gap:3px;">
        <button type="button" id="btn-strength" class="seg-btn<?= $progress_section === 'strength' ? ' active' : '' ?> flex-fill" onclick="showSection('strength')">
            <i class="bi bi-bar-chart-fill me-1"></i>STRENGTH
        </button>
        <button type="button" id="btn-cardio" class="seg-btn<?= $progress_section === 'cardio' ? ' active' : '' ?> flex-fill" onclick="showSection('cardio')">
            <i class="bi bi-heart-pulse me-1"></i>CARDIO
        </button>
        <button type="button" id="btn-body" class="seg-btn<?= $progress_section === 'body' ? ' active' : '' ?> flex-fill" onclick="showSection('body')">
            <i class="bi bi-person-arms-up me-1"></i>BODY
        </button>
    </div>

    <form method="get" class="mb-3">
        <input id="progress-section-field" type="hidden" name="section" value="<?= $progress_section ?>">
        <select aria-label="Viewing period" name="cycle" onchange="this.form.submit()" style="width:100%;background:#1e1e1e;color:#e0e0e0;border:1px solid #444;border-radius:5px;padding:9px;font-size:.78rem;min-height:44px;">
            <option value="current"<?= $cycle_context_request === 'current' ? ' selected' : '' ?>>CURRENT CYCLE<?= $active_cycle ? ' · ' . forge_cycle_label($active_cycle['cycle_type']) . ' · ' . date('M Y',strtotime($active_cycle['starts_at'])) . '–' . date('M Y',strtotime($active_cycle['ends_at'])) : '' ?></option>
            <option value="era"<?= $cycle_context === 'era' ? ' selected' : '' ?>>CURRENT ERA</option>
            <option value="all"<?= $cycle_context === 'all' ? ' selected' : '' ?>>ALL TIME</option>
            <?php foreach ($cycles as $cycle): ?><option value="<?= $cycle['id'] ?>"<?= $cycle_context_request !== 'current' && $cycle_context === (string)$cycle['id'] ? ' selected' : '' ?>><?= forge_cycle_label($cycle['cycle_type']) ?> · <?= date('M Y',strtotime($cycle['starts_at'])) ?>–<?= date('M Y',strtotime($cycle['ends_at'])) ?></option><?php endforeach; ?>
        </select>
        <?php if ($scrub_dates): ?><input id="progress-as-of" type="hidden" name="as_of" value="<?= htmlspecialchars($scrub_dates[$scrub_index]) ?>"><div class="mt-2"><input id="progress-date-scrubber" type="range" min="0" max="<?= count($scrub_dates) - 1 ?>" value="<?= $scrub_index ?>" oninput="updateProgressScrubber(this.value)" onchange="this.form.submit()" style="width:100%;accent-color:#FF5722;"><output id="progress-scrubber-label" class="text-muted" style="font-size:.7rem;"><?= date('j M Y', strtotime($scrub_dates[$scrub_index])) ?></output></div><?php endif; ?>
    </form>

    <!-- ═══════════════════════════════════════════════════════════ STRENGTH -->
    <div id="sec-strength">

        <div class="d-flex align-items-center justify-content-between mb-3">
            <div class="stat-label">STRENGTH STANDARDS <span class="text-muted" style="font-weight:400;font-size:0.65rem;">· Symmetric Strength · scaled to <?= round($bw,1) ?> kg BW</span></div>
            <div style="background:#111;border-radius:6px;padding:2px;display:flex;gap:2px;flex-shrink:0;">
                <button id="btn-1rm" class="seg-btn active" onclick="setRmMode('1rm')" style="padding:4px 10px;font-size:0.65rem;min-height:30px;border-radius:4px;">e1RM</button>
                <button id="btn-5rm" class="seg-btn" onclick="setRmMode('5rm')" style="padding:4px 10px;font-size:0.65rem;min-height:30px;border-radius:4px;">5RM</button>
            </div>
        </div>

        <div id="standards-1rm">
        <?php foreach ($big4 as $key => $lift): ?>
        <?= standards_card($lift, $level_names, $unit) ?>
        <?php endforeach; ?>
        </div>
        <div id="standards-5rm" class="d-none">
        <?php foreach ($big4_5rm as $key => $lift): ?>
        <?= standards_card($lift, $level_names, $unit) ?>
        <?php endforeach; ?>
        </div>

        <!-- Big 4 trend -->
        <div class="d-flex align-items-center justify-content-between mb-2 mt-1">
            <div class="stat-label">BIG 4 — e1RM TREND</div>
            <div style="background:#111;border-radius:5px;padding:2px;display:flex;gap:2px;">
                <button id="big4-session-btn" class="seg-btn active" onclick="setBig4TrendMode('session')" style="padding:3px 7px;font-size:0.58rem;min-height:26px;border-radius:3px;">SESSION</button>
                <button id="big4-pr-btn" class="seg-btn" onclick="setBig4TrendMode('pr')" style="padding:3px 7px;font-size:0.58rem;min-height:26px;border-radius:3px;">RUNNING PR</button>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-body py-3 px-3">
                <div style="height:200px;">
                    <canvas id="big4-chart"></canvas>
                </div>
                <div id="big4-empty" class="text-muted text-center py-3 d-none" style="font-size:0.8rem;">Log sets across multiple sessions to see trend</div>
            </div>
        </div>

        <!-- PR Board -->
        <div class="d-flex align-items-center justify-content-between mb-2 mt-1">
            <div class="stat-label"><?= $cycle_context === 'all' ? 'ALL-TIME PRs' : 'BEST LIFTS IN VIEW' ?></div>
        </div>
        <?php if (empty($pr_board)): ?>
        <div class="card mb-3">
            <div class="card-body text-muted text-center py-4" style="font-size:0.8rem;">No strength PRs recorded yet</div>
        </div>
        <?php else: ?>
        <div class="card mb-3">
            <div class="card-body p-0">
            <?php foreach ($pr_board as $i => $pr): ?>
            <a href="/forge/exercises/?id=<?= $pr['exercise_id'] ?>" class="text-decoration-none d-flex align-items-center px-3 py-2"
               style="border-bottom:1px solid #1a1a1a;min-height:48px;<?= $i === count($pr_board)-1 ? 'border-bottom:none;' : '' ?>">
                <span style="font-size:0.7rem;font-weight:700;color:#444;min-width:24px;"><?= $i+1 ?></span>
                <div class="flex-grow-1 min-w-0">
                    <div style="font-weight:700;font-size:0.85rem;color:#e0e0e0;"><?= htmlspecialchars($pr['exercise_name']) ?></div>
                    <div style="font-size:0.68rem;color:#555;">
                        Best logged result in this view &nbsp;·&nbsp; <?= $pr['pr_count'] ?> session<?= $pr['pr_count'] != 1 ? 's' : '' ?>
                    </div>
                </div>
                <?php if ($pr['muscle_primary']): ?>
                <span class="muscle-badge muscle-<?= strtolower(preg_replace('/[^a-zA-Z]/','',$pr['muscle_primary'])) ?> me-2" style="font-size:0.6rem;">
                    <?= htmlspecialchars($pr['muscle_primary']) ?>
                </span>
                <?php endif; ?>
                <div style="text-align:right;flex-shrink:0;">
                    <div class="pr-rm-val" data-1rm="<?= $pr['estimated_1rm'] ?>" style="font-weight:900;font-size:0.9rem;color:#FF5722;"><?= $pr['estimated_1rm'] ?></div>
                    <div class="pr-rm-label" style="font-size:0.6rem;color:#555;">e1RM <?= $unit ?></div>
                </div>
            </a>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Strength Ratios -->
        <?php if (!empty($ratios)): ?>
        <div class="stat-label mb-2 mt-1">STRENGTH RATIOS</div>
        <div class="row g-2 mb-3">
        <?php foreach ($ratios as $rt):
            $st = ratio_status((float)$rt['ratio'], $rt['lo'], $rt['hi']);
            $status_color = $st === 'ok' ? '#2e7d32' : ($st === 'close' ? '#f57f17' : '#c62828');
            $status_bg    = $st === 'ok' ? '#1a2e1a' : ($st === 'close' ? '#2e1f00' : '#2e0a0a');
            $status_icon  = $st === 'ok' ? 'bi-check-circle-fill' : ($st === 'close' ? 'bi-dash-circle' : 'bi-exclamation-circle-fill');
        ?>
        <div class="col-12">
            <div class="card" style="border-color:<?= $status_color ?>33;">
                <div class="card-body py-2 px-3 d-flex align-items-center justify-content-between">
                    <div>
                        <div style="font-weight:700;font-size:0.8rem;color:#e0e0e0;"><?= $rt['label'] ?></div>
                        <div style="font-size:0.68rem;color:#666;"><?= htmlspecialchars($rt['desc']) ?></div>
                        <div style="font-size:0.65rem;color:#555;margin-top:2px;">Target: <?= $rt['target'] ?></div>
                    </div>
                    <div class="text-end ms-3" style="flex-shrink:0;">
                        <div style="font-size:1.4rem;font-weight:900;color:<?= $status_color ?>;"><?= number_format($rt['ratio'],2) ?></div>
                        <div><i class="bi <?= $status_icon ?>" style="color:<?= $status_color ?>;font-size:0.75rem;"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php elseif ($s1 == 0 || $b1 == 0 || $d1 == 0 || $o1 == 0): ?>
        <div class="stat-label mb-2 mt-1">STRENGTH RATIOS</div>
        <div class="card mb-3">
            <div class="card-body text-muted text-center py-3" style="font-size:0.8rem;">Log all 4 Big lifts to see balance ratios</div>
        </div>
        <?php endif; ?>

    </div><!-- /sec-strength -->

    <!-- ════════════════════════════════════════════════════════════ CARDIO -->
    <div id="sec-cardio" class="d-none">

        <!-- Cardio totals -->
        <div class="row g-2 mb-3">
            <div class="col-4">
                <div class="card text-center py-3">
                    <div style="font-size:1.4rem;font-weight:900;color:#FF5722;"><?= number_format((float)$ctotals['total_km'],1) ?></div>
                    <div class="stat-label" style="font-size:0.6rem;">TOTAL KM</div>
                </div>
            </div>
            <div class="col-4">
                <div class="card text-center py-3">
                    <div style="font-size:1.4rem;font-weight:900;color:#FF5722;"><?= $total_hrs ?></div>
                    <div class="stat-label" style="font-size:0.6rem;">TOTAL HRS</div>
                </div>
            </div>
            <div class="col-4">
                <div class="card text-center py-3">
                    <div style="font-size:1.4rem;font-weight:900;color:#FF5722;"><?= (int)$ctotals['sessions'] ?></div>
                    <div class="stat-label" style="font-size:0.6rem;">SESSIONS</div>
                </div>
            </div>
        </div>

        <!-- Cardio PRs -->
        <div class="stat-label mb-2">CARDIO RECORDS</div>
        <?php if (empty($cardio_prs)): ?>
        <div class="card mb-3">
            <div class="card-body text-muted text-center py-4" style="font-size:0.8rem;">No cardio sessions logged yet</div>
        </div>
        <?php else: ?>
        <?php foreach ($cardio_prs as $ex_name => $prs): ?>
        <div class="card mb-2">
            <div class="card-body py-2 px-3">
                <a href="/forge/exercises/?id=<?= (int)$prs['_exercise_id'] ?>" class="fw-black mb-2 text-decoration-none d-block" style="font-size:0.85rem;color:#e0e0e0;"><?= htmlspecialchars($ex_name) ?></a>
                <div class="row g-2">
                    <?php
                    $pr_defs = [
                        'avg_speed'          => ['label' => 'BEST SPEED',  'fmt' => fn($v) => round($v,2) . ' km/h'],
                        'cardiac_efficiency' => ['label' => 'EFFICIENCY',  'fmt' => fn($v) => round($v,2)],
                        'best_distance'      => ['label' => 'BEST DIST',   'fmt' => fn($v) => round($v,2) . ' km'],
                    ];
                    foreach ($pr_defs as $type => $def):
                        if (!isset($prs[$type])) continue;
                        $r = $prs[$type];
                    ?>
                    <div class="col-4 text-center">
                        <div style="font-size:0.65rem;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:0.5px;"><?= $def['label'] ?></div>
                        <div style="font-size:0.9rem;font-weight:900;color:#FF5722;"><?= $def['fmt']($r['value']) ?></div>
                        <div style="font-size:0.6rem;color:#444;"><?= date('M j', strtotime($r['achieved_at'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- Cardiac efficiency trend -->
        <div class="stat-label mb-2 mt-2">CARDIAC EFFICIENCY TREND</div>
        <div class="card mb-3">
            <div class="card-body py-3 px-3">
                <div style="height:180px;">
                    <canvas id="efficiency-chart"></canvas>
                </div>
                <div id="efficiency-empty" class="text-muted text-center py-3 d-none" style="font-size:0.8rem;">Log cardio sessions with HR data to see trend</div>
            </div>
        </div>

        <!-- Weekly cardio volume -->
        <div class="stat-label mb-2">WEEKLY CARDIO VOLUME</div>
        <div class="card mb-3">
            <div class="card-body py-3 px-3">
                <div style="height:160px;">
                    <canvas id="volume-chart"></canvas>
                </div>
                <div id="volume-empty" class="text-muted text-center py-3 d-none" style="font-size:0.8rem;">No cardio sessions in the last 12 weeks</div>
            </div>
        </div>

        <!-- VO2 max -->
        <div class="stat-label mb-2">VO2 MAX ESTIMATE</div>
        <div class="card mb-3" <?= $vo2max ? '' : 'style="border-color:#333;opacity:0.7;"' ?>>
            <div class="card-body py-3 px-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div style="font-weight:700;font-size:0.85rem;color:<?= $vo2max ? '#e0e0e0' : '#888' ?>;">Estimated VO2 Max</div>
                        <div style="font-size:0.7rem;color:#555;margin-top:4px;">Formula: 15 × (HR<sub>max</sub> / HR<sub>rest</sub>)</div>
                        <?php if ($vo2max): ?>
                        <div style="font-size:0.68rem;color:#666;margin-top:4px;">
                            HR<sub>rest</sub> <?= $resting_hr ?> bpm &nbsp;·&nbsp;
                            HR<sub>max</sub> <?= $max_hr ?> bpm<?= $max_hr_stored ? '' : ' (est.)' ?>
                        </div>
                        <?php else: ?>
                        <div style="font-size:0.68rem;color:#444;margin-top:6px;">Set resting HR in <a href="/forge/profile/" style="color:#FF5722;">Profile</a> to unlock</div>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:2rem;font-weight:900;color:<?= $vo2max ? '#FF5722' : '#333' ?>;">
                        <?= $vo2max ?? '—' ?>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /sec-cardio -->

    <!-- ═══════════════════════════════════════════════════════════════ BODY -->
    <div id="sec-body" class="d-none">

        <?php if ($height_cm <= 0 || empty($profile_sex)): ?>
        <div class="card mb-3" style="border-color:#2a1a0a;">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label mb-1">PROFILE INCOMPLETE</div>
                    <div style="font-size:0.8rem; color:#888;">Height and sex are required for body composition calculations.</div>
                </div>
                <a href="/forge/profile/" class="btn btn-sm btn-forge ms-3" style="white-space:nowrap;">SET UP →</a>
            </div>
        </div>
        <?php elseif (empty($body_measurements)): ?>
        <div class="card mb-3" style="border-color:#2a1a0a;">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label mb-1">NO MEASUREMENTS YET</div>
                    <div style="font-size:0.8rem; color:#888;">Log waist, neck (+ hip for women) to track body fat % over time.</div>
                </div>
                <a href="/forge/profile/" class="btn btn-sm btn-forge ms-3" style="white-space:nowrap;">LOG →</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Summary stat cards -->
        <div class="row g-2 mb-3">
            <?php
            // BMI card
            $bmi_color = '#888'; $bmi_bg = '#1a1a1a'; $bmi_label = 'No data';
            if ($bmi !== null) {
                if ($bmi < 18.5)      { $bmi_color='#42a5f5'; $bmi_bg='#1a2a3a'; $bmi_label='Underweight'; }
                elseif ($bmi < 25.0)  { $bmi_color='#66bb6a'; $bmi_bg='#1a2e1a'; $bmi_label='Healthy'; }
                elseif ($bmi < 30.0)  { $bmi_color='#ffa726'; $bmi_bg='#2e1f00'; $bmi_label='Overweight'; }
                else                  { $bmi_color='#ef5350'; $bmi_bg='#2e0a0a'; $bmi_label='Obese'; }
            }
            ?>
            <div class="col-4">
                <div class="card text-center py-3" style="background:<?= $bmi_bg ?>;">
                    <div style="font-size:1.4rem;font-weight:900;color:<?= $bmi_color ?>;"><?= $bmi ?? '—' ?></div>
                    <div class="stat-label" style="font-size:0.55rem;">BMI</div>
                    <div style="font-size:0.6rem;color:<?= $bmi_color ?>;"><?= $bmi_label ?></div>
                </div>
            </div>
            <div class="col-4">
                <div class="card text-center py-3">
                    <div style="font-size:1.4rem;font-weight:900;color:#FF5722;"><?= $smoothed_bf !== null ? $smoothed_bf . '%' : '—' ?></div>
                    <div class="stat-label" style="font-size:0.55rem;">BODY FAT</div>
                    <div style="font-size:0.6rem;color:#888;">smoothed</div>
                </div>
            </div>
            <div class="col-4">
                <div class="card text-center py-3">
                    <div style="font-size:1.4rem;font-weight:900;color:#FF5722;"><?= $lean_mass !== null ? $lean_mass : '—' ?></div>
                    <div class="stat-label" style="font-size:0.55rem;">LEAN MASS</div>
                    <div style="font-size:0.6rem;color:#888;"><?= $unit ?></div>
                </div>
            </div>
        </div>

        <!-- Bodyweight trend chart -->
        <div class="stat-label mb-2">BODYWEIGHT TREND</div>
        <div class="card mb-3">
            <div class="card-body py-3 px-3">
                <div style="height:180px;">
                    <canvas id="bw-chart"></canvas>
                </div>
                <div id="bw-empty" class="text-muted text-center py-3 d-none" style="font-size:0.8rem;">Log bodyweight at your next workout to start tracking</div>
            </div>
        </div>

        <!-- Estimated lean body mass trend -->
        <?php if (count($lbm_trend) > 0): ?>
        <div class="stat-label mb-2">ESTIMATED LEAN MASS</div>
        <div class="card mb-3">
            <div class="card-body py-3 px-3">
                <div style="height:180px;">
                    <canvas id="lbm-chart"></canvas>
                </div>
                <div class="text-muted mt-2" style="font-size:0.68rem;">Body-fat percentage is interpolated between measurements.</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Body composition breakdown -->
        <?php if ($smoothed_bf !== null && $lean_mass !== null): ?>
        <div class="stat-label mb-2">BODY COMPOSITION</div>
        <div class="card mb-3">
            <div class="card-body py-3 px-3">
                <div class="d-flex justify-content-between mb-1" style="font-size:0.72rem;">
                    <span style="color:#66bb6a;font-weight:700;">LEAN <?= $lean_mass ?> <?= $unit ?></span>
                    <span style="color:#ef5350;font-weight:700;">FAT <?= $fat_mass ?> <?= $unit ?></span>
                </div>
                <?php $lean_pct = round(100 - $smoothed_bf); ?>
                <div style="height:12px;border-radius:6px;overflow:hidden;display:flex;">
                    <div style="width:<?= $lean_pct ?>%;background:#2e7d32;"></div>
                    <div style="width:<?= $smoothed_bf ?>%;background:#c62828;"></div>
                </div>
                <div class="row g-2 mt-2">
                    <?php if ($navy_bf !== null): ?>
                    <div class="col-4 text-center">
                        <div style="font-size:0.9rem;font-weight:900;color:#FF5722;"><?= $navy_bf ?>%</div>
                        <div style="font-size:0.6rem;color:#888;">NAVY</div>
                    </div>
                    <?php endif; ?>
                    <?php if ($caliper_bf !== null): ?>
                    <div class="col-4 text-center">
                        <div style="font-size:0.9rem;font-weight:900;color:#FF5722;"><?= $caliper_bf ?>%</div>
                        <div style="font-size:0.6rem;color:#888;">CALIPER</div>
                    </div>
                    <?php endif; ?>
                    <div class="col-4 text-center">
                        <div style="font-size:0.9rem;font-weight:900;color:#FF5722;"><?= $smoothed_bf ?>%</div>
                        <div style="font-size:0.6rem;color:#888;">SMOOTHED</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- BF% trend chart -->
        <?php if (count($bf_trend) > 0): ?>
        <div class="stat-label mb-2">BODY FAT TREND</div>
        <div class="card mb-3">
            <div class="card-body py-3 px-3">
                <div style="height:160px;">
                    <canvas id="bf-chart"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Metrics row: W:H ratio + VO2max -->
        <div class="row g-2 mb-3">
            <?php if ($whr !== null): ?>
            <?php
            $whr_color = '#888'; $whr_bg = '#1a1a1a'; $whr_label = '';
            if ($whr < 0.40)      { $whr_color='#42a5f5'; $whr_bg='#1a2a3a'; $whr_label='Slim'; }
            elseif ($whr <= 0.50) { $whr_color='#66bb6a'; $whr_bg='#1a2e1a'; $whr_label='Healthy'; }
            elseif ($whr <= 0.60) { $whr_color='#ffa726'; $whr_bg='#2e1f00'; $whr_label='At risk'; }
            else                  { $whr_color='#ef5350'; $whr_bg='#2e0a0a'; $whr_label='High risk'; }
            ?>
            <div class="col-6">
                <div class="card text-center py-3" style="background:<?= $whr_bg ?>;">
                    <div style="font-size:1.4rem;font-weight:900;color:<?= $whr_color ?>;"><?= $whr ?></div>
                    <div class="stat-label" style="font-size:0.55rem;">WAIST:HEIGHT</div>
                    <div style="font-size:0.6rem;color:<?= $whr_color ?>;"><?= $whr_label ?></div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($vo2max !== null): ?>
            <div class="col-6">
                <div class="card text-center py-3">
                    <div style="font-size:1.4rem;font-weight:900;color:#FF5722;"><?= $vo2max ?></div>
                    <div class="stat-label" style="font-size:0.55rem;">VO2 MAX</div>
                    <div style="font-size:0.6rem;color:#555;">mL/kg/min</div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Measurements history -->
        <?php if (!empty($body_measurements)): ?>
        <div class="stat-label mb-2">MEASUREMENTS LOG</div>
        <div class="card mb-3">
            <div class="card-body p-0">
                <div class="d-flex px-3 py-2" style="font-size:0.62rem;font-weight:700;color:#444;border-bottom:1px solid #1a1a1a;">
                    <div style="width:70px;">DATE</div>
                    <div class="flex-grow-1 text-center">WAIST</div>
                    <div class="flex-grow-1 text-center">NECK</div>
                    <?php if ($is_female): ?><div class="flex-grow-1 text-center">HIP</div><?php endif; ?>
                    <div class="flex-grow-1 text-center">CAL%</div>
                    <div class="flex-grow-1 text-center">BF%</div>
                </div>
                <?php
                $last = count($body_measurements) - 1;
                foreach ($body_measurements as $mi => $m):
                    // Compute BF% for this row
                    $mn = null;
                    if ($height_cm > 0 && $m['waist_cm'] && $m['neck_cm']) {
                        if ($is_female && $m['hip_cm']) {
                            $mn = round(163.205 * log10((float)$m['waist_cm'] + (float)$m['hip_cm'] - (float)$m['neck_cm']) - 97.684 * log10($height_cm) - 78.387, 1);
                        } else {
                            $mn = round(86.01 * log10((float)$m['waist_cm'] - (float)$m['neck_cm']) - 70.041 * log10($height_cm) + 36.76, 1);
                        }
                    }
                    $mc = $m['caliper_bf_pct'] ? (float)$m['caliper_bf_pct'] : null;
                    $mv = array_filter([$mn, $mc], fn($v) => $v !== null);
                    $ms = count($mv) ? round(array_sum($mv)/count($mv),1) : null;
                ?>
                <div class="d-flex align-items-center px-3 py-2" style="font-size:0.72rem;<?= $mi === $last ? '' : 'border-bottom:1px solid #1a1a1a;' ?>min-height:40px;">
                    <div style="width:70px;color:#555;font-size:0.65rem;"><?= date('d M', strtotime($m['measured_at'])) ?></div>
                    <div class="flex-grow-1 text-center" style="color:#e0e0e0;"><?= $m['waist_cm'] ? round((float)$m['waist_cm'],1) : '—' ?></div>
                    <div class="flex-grow-1 text-center" style="color:#e0e0e0;"><?= $m['neck_cm'] ? round((float)$m['neck_cm'],1) : '—' ?></div>
                    <?php if ($is_female): ?>
                    <div class="flex-grow-1 text-center" style="color:#e0e0e0;"><?= $m['hip_cm'] ? round((float)$m['hip_cm'],1) : '—' ?></div>
                    <?php endif; ?>
                    <div class="flex-grow-1 text-center" style="color:#e0e0e0;"><?= $mc !== null ? $mc . '%' : '—' ?></div>
                    <div class="flex-grow-1 text-center" style="color:#FF5722;font-weight:700;"><?= $ms !== null ? $ms . '%' : '—' ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="stat-label mb-2">MEASUREMENTS LOG</div>
        <div class="card mb-3">
            <div class="card-body text-muted text-center py-4" style="font-size:0.8rem;">
                Log body measurements in <a href="/forge/profile/" style="color:#FF5722;">Profile</a> to track body composition
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /sec-body -->

</div>

<style>
.std-badge {
    font-size: 0.6rem;
    font-weight: 900;
    padding: 3px 8px;
    border-radius: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    flex-shrink: 0;
}
</style>

<script>
const PROGRESS_API = '/forge/core/progress_api.php';
const PROGRESS_CYCLE_CONTEXT = <?= json_encode($cycle_context_request) ?>;
const PROGRESS_AS_OF = <?= json_encode($as_of_at) ?>;
const PROGRESS_SCRUB_DATES = <?= json_encode(array_values($scrub_dates)) ?>;

function updateProgressScrubber(index) {
    const date = PROGRESS_SCRUB_DATES[Number(index)];
    if (!date) return;
    document.getElementById('progress-as-of').value = date;
    document.getElementById('progress-scrubber-label').value = new Date(date + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

async function papi(action, data = {}) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('cycle_context', PROGRESS_CYCLE_CONTEXT);
    if (PROGRESS_AS_OF) fd.append('as_of', PROGRESS_AS_OF);
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    const r = await fetch(PROGRESS_API, { method: 'POST', body: fd });
    return r.json();
}

// ── SECTION TOGGLE ──────────────────────────────────────────────────────────
let strengthLoaded = false;
let cardioLoaded   = false;
let bodyLoaded     = false;

function showSection(sec) {
    ['strength', 'cardio', 'body'].forEach(s => {
        document.getElementById('sec-' + s).classList.toggle('d-none', s !== sec);
        document.getElementById('btn-' + s).classList.toggle('active', s === sec);
    });
    if (sec === 'strength' && !strengthLoaded) { loadStrengthCharts(getRmMult()); strengthLoaded = true; }
    if (sec === 'cardio'   && !cardioLoaded)   { loadCardioCharts();              cardioLoaded   = true; }
    if (sec === 'body'     && !bodyLoaded)     { loadBodyCharts();                bodyLoaded     = true; }
    document.getElementById('progress-section-field').value = sec;
    const url = new URL(window.location.href); url.searchParams.set('section', sec); window.history.replaceState({}, '', url);
}

// ── 1RM / 5RM TOGGLE ────────────────────────────────────────────────────────
const RM5_MULT = 6 / 7;
let rmMode = '1rm';
function getRmMult() { return rmMode === '5rm' ? RM5_MULT : 1.0; }

function setRmMode(mode) {
    rmMode = mode;
    const mult = getRmMult();

    // Swap standards cards
    document.getElementById('standards-1rm').classList.toggle('d-none', mode === '5rm');
    document.getElementById('standards-5rm').classList.toggle('d-none', mode === '1rm');

    // Update PR board values
    document.querySelectorAll('.pr-rm-val').forEach(el => {
        el.textContent = Math.round(parseFloat(el.dataset['1rm']) * mult * 10) / 10;
    });
    document.querySelectorAll('.pr-rm-label').forEach(el => {
        el.textContent = (mode === '5rm' ? '5RM' : 'e1RM') + ' <?= $unit ?>';
    });

    // Update toggle buttons
    document.getElementById('btn-1rm').classList.toggle('active', mode === '1rm');
    document.getElementById('btn-5rm').classList.toggle('active', mode === '5rm');

    // Redraw Big 4 chart with new multiplier
    if (big4ChartInstance) { big4ChartInstance.destroy(); big4ChartInstance = null; }
    if (big4RawData) renderBig4Chart(big4RawData, mult);
}

// ── CHART HELPERS ───────────────────────────────────────────────────────────
function mkLine(id, datasets, allDates, yLabel) {
    return new Chart(document.getElementById(id), {
        type: 'line',
        data: { labels: allDates, datasets },
        options: {
            maintainAspectRatio: false, animation: false,
            spanGaps: true,
            plugins: { legend: { labels: { color: '#666', font: { size: 10 }, boxWidth: 12 } } },
            scales: {
                x: { ticks: { color: '#444', maxTicksLimit: 6, font: { size: 10 } }, grid: { color: '#1a1a1a' } },
                y: { ticks: { color: '#888', font: { size: 10 }, callback: v => v + ' kg' }, grid: { color: '#1a1a1a' } },
            }
        }
    });
}

function mkBar(id, labels, data, yCallback) {
    return new Chart(document.getElementById(id), {
        type: 'bar',
        data: { labels, datasets: [{ data, backgroundColor: '#FF572266', borderColor: '#FF5722', borderWidth: 1, borderRadius: 3 }] },
        options: {
            maintainAspectRatio: false, animation: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: '#444', font: { size: 10 } }, grid: { color: '#1a1a1a' } },
                y: { ticks: { color: '#888', font: { size: 10 }, callback: yCallback }, grid: { color: '#1a1a1a' }, beginAtZero: true },
            }
        }
    });
}

// ── STRENGTH CHARTS ─────────────────────────────────────────────────────────
const BIG4_COLORS = {
    'Barbell Back Squat':  '#FF5722',
    'Barbell Bench Press': '#42a5f5',
    'Deadlift':            '#7e57c2',
    'Overhead Press':      '#66bb6a',
};

let big4RawData      = null;
let big4ChartInstance = null;
let big4TrendMode     = 'session';

function setBig4TrendMode(mode) {
    big4TrendMode = mode;
    document.getElementById('big4-session-btn').classList.toggle('active', mode === 'session');
    document.getElementById('big4-pr-btn').classList.toggle('active', mode === 'pr');
    if (big4ChartInstance) { big4ChartInstance.destroy(); big4ChartInstance = null; }
    if (big4RawData) renderBig4Chart(big4RawData, getRmMult());
}

function renderBig4Chart(byLift, mult) {
    const dateSet = new Set();
    Object.values(byLift).forEach(rows => rows.forEach(row => dateSet.add(row.date)));
    const allDates = [...dateSet].sort();

    if (!allDates.length) {
        document.getElementById('big4-chart').closest('.card-body').innerHTML =
            '<div class="text-muted text-center py-4" style="font-size:0.8rem;">Log sets across multiple sessions to see trend</div>';
        return;
    }

    const datasets = Object.entries(byLift).map(([name, rows]) => {
        let best = 0;
        const displayRows = big4TrendMode === 'pr'
            ? rows.map(row => ({ ...row, estimated_1rm: best = Math.max(best, parseFloat(row.estimated_1rm)) }))
            : rows;
        const map = Object.fromEntries(displayRows.map(r => [r.date, parseFloat(r.estimated_1rm)]));
        return {
            label: name.replace('Barbell ', ''),
            data: allDates.map(d => map[d] != null ? Math.round(map[d] * mult * 10) / 10 : null),
            borderColor: BIG4_COLORS[name] || '#888',
            backgroundColor: 'transparent',
            borderWidth: 2,
            pointRadius: 3,
            tension: 0.3,
            spanGaps: true,
        };
    });

    const canvas = document.getElementById('big4-chart');
    big4ChartInstance = new Chart(canvas, {
        type: 'line',
        data: { labels: allDates, datasets },
        options: {
            maintainAspectRatio: false, animation: false,
            plugins: { legend: { labels: { color: '#666', font: { size: 10 }, boxWidth: 12 } } },
            scales: {
                x: { ticks: { color: '#444', maxTicksLimit: 6, font: { size: 10 } }, grid: { color: '#1a1a1a' } },
                y: { ticks: { color: '#888', font: { size: 10 }, callback: v => v + ' kg' }, grid: { color: '#1a1a1a' } },
            }
        }
    });
}

async function loadStrengthCharts(mult = 1.0) {
    const r = await papi('get_big4_trend');
    if (r.status !== 'success') return;
    big4RawData = r.data;
    renderBig4Chart(big4RawData, mult);
}

// ── CARDIO CHARTS ────────────────────────────────────────────────────────────
const CARDIO_COLORS = ['#FF5722', '#42a5f5', '#66bb6a', '#7e57c2', '#ef5350', '#26c6da'];

async function loadCardioCharts() {
    // Efficiency trend
    const effR = await papi('get_cardio_efficiency_trend');
    if (effR.status === 'success' && Object.keys(effR.data).length) {
        const byEx   = effR.data;
        const dateSet = new Set();
        Object.values(byEx).forEach(rows => rows.forEach(r => dateSet.add(r.date)));
        const allDates = [...dateSet].sort();
        const datasets = Object.entries(byEx).map(([name, rows], i) => {
            const map = Object.fromEntries(rows.map(r => [r.date, r.value]));
            return {
                label: name,
                data: allDates.map(d => map[d] ?? null),
                borderColor: CARDIO_COLORS[i % CARDIO_COLORS.length],
                backgroundColor: 'transparent',
                borderWidth: 2,
                pointRadius: 3,
                tension: 0.3,
                spanGaps: true,
            };
        });
        new Chart(document.getElementById('efficiency-chart'), {
            type: 'line',
            data: { labels: allDates, datasets },
            options: {
                maintainAspectRatio: false, animation: false, spanGaps: true,
                plugins: { legend: { labels: { color: '#666', font: { size: 10 }, boxWidth: 12 } } },
                scales: {
                    x: { ticks: { color: '#444', maxTicksLimit: 6, font: { size: 10 } }, grid: { color: '#1a1a1a' } },
                    y: { ticks: { color: '#888', font: { size: 10 } }, grid: { color: '#1a1a1a' } },
                }
            }
        });
    } else {
        document.getElementById('efficiency-empty').classList.remove('d-none');
        document.getElementById('efficiency-chart').classList.add('d-none');
    }

    // Weekly volume
    const volR = await papi('get_cardio_volume_trend');
    if (volR.status === 'success' && volR.data.length) {
        const labels = volR.data.map(r => r.week.replace(/^\d{4}-/, ''));
        const vals   = volR.data.map(r => parseFloat(r.total_km));
        mkBar('volume-chart', labels, vals, v => v + ' km');
    } else {
        document.getElementById('volume-empty').classList.remove('d-none');
        document.getElementById('volume-chart').classList.add('d-none');
    }
}

// ── BODY CHARTS ───────────────────────────────────────────────────────────────
const BW_TREND  = <?= json_encode(array_map(fn($r) => ['date' => $r['date'], 'bw' => (float)$r['bw']], $bw_trend)) ?>;
const BF_TREND  = <?= json_encode($bf_trend) ?>;
const LBM_TREND = <?= json_encode($lbm_trend) ?>;

function loadBodyCharts() {
    // Bodyweight trend
    if (BW_TREND.length) {
        new Chart(document.getElementById('bw-chart'), {
            type: 'line',
            data: {
                labels: BW_TREND.map(r => r.date),
                datasets: [{
                    label: 'Bodyweight (kg)',
                    data: BW_TREND.map(r => r.bw),
                    borderColor: '#FF5722',
                    backgroundColor: '#FF572220',
                    fill: true,
                    borderWidth: 2,
                    pointRadius: 3,
                    tension: 0.3,
                    spanGaps: true,
                }]
            },
            options: {
                maintainAspectRatio: false, animation: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#888', maxTicksLimit: 6, font: { size: 10 } }, grid: { color: '#1a1a1a' } },
                    y: { ticks: { color: '#888', font: { size: 10 }, callback: v => v + ' kg' }, grid: { color: '#1a1a1a' } },
                }
            }
        });
    } else {
        document.getElementById('bw-empty').classList.remove('d-none');
        document.getElementById('bw-chart').classList.add('d-none');
    }

    // Estimated lean body mass trend
    const lbmCanvas = document.getElementById('lbm-chart');
    if (lbmCanvas && LBM_TREND.length) {
        new Chart(lbmCanvas, {
            type: 'line',
            data: {
                labels: LBM_TREND.map(r => r.date),
                datasets: [{
                    label: 'Lean mass (kg)',
                    data: LBM_TREND.map(r => r.lbm),
                    borderColor: '#66bb6a',
                    backgroundColor: '#66bb6a20',
                    fill: true,
                    borderWidth: 2,
                    pointRadius: 3,
                    tension: 0.3,
                    spanGaps: true,
                }]
            },
            options: {
                maintainAspectRatio: false, animation: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { afterLabel: context => `Estimated BF: ${LBM_TREND[context.dataIndex].bf_pct}%` } },
                },
                scales: {
                    x: { ticks: { color: '#888', maxTicksLimit: 6, font: { size: 10 } }, grid: { color: '#1a1a1a' } },
                    y: { ticks: { color: '#888', font: { size: 10 }, callback: v => v + ' kg' }, grid: { color: '#1a1a1a' } },
                }
            }
        });
    }

    // BF% trend
    const bfCanvas = document.getElementById('bf-chart');
    if (bfCanvas && BF_TREND.length) {
        const dates = BF_TREND.map(r => r.date);
        const datasets = [];
        if (BF_TREND.some(r => r.navy !== null)) datasets.push({
            label: 'Navy', data: BF_TREND.map(r => r.navy), borderColor: '#42a5f5',
            backgroundColor: 'transparent', borderWidth: 1.5, pointRadius: 2, tension: 0.3, spanGaps: true,
        });
        if (BF_TREND.some(r => r.caliper !== null)) datasets.push({
            label: 'Caliper', data: BF_TREND.map(r => r.caliper), borderColor: '#7e57c2',
            backgroundColor: 'transparent', borderWidth: 1.5, pointRadius: 2, tension: 0.3, spanGaps: true,
        });
        if (BF_TREND.some(r => r.smoothed !== null)) datasets.push({
            label: 'Smoothed', data: BF_TREND.map(r => r.smoothed), borderColor: '#FF5722',
            backgroundColor: 'transparent', borderWidth: 2, pointRadius: 3, tension: 0.3, spanGaps: true,
        });
        new Chart(bfCanvas, {
            type: 'line',
            data: { labels: dates, datasets },
            options: {
                maintainAspectRatio: false, animation: false,
                plugins: { legend: { labels: { color: '#888', font: { size: 10 }, boxWidth: 12 } } },
                scales: {
                    x: { ticks: { color: '#888', maxTicksLimit: 6, font: { size: 10 } }, grid: { color: '#1a1a1a' } },
                    y: { ticks: { color: '#888', font: { size: 10 }, callback: v => v + '%' }, grid: { color: '#1a1a1a' } },
                }
            }
        });
    }
}

// Load the section retained in the URL when a period is changed.
showSection(<?= json_encode($progress_section) ?>);
</script>

</body>
</html>
