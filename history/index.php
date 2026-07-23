<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/db_helper.php';
require_once __DIR__ . '/../core/cycle_analysis.php';

$db       = get_db_connection();
$unit     = 'kg';
$time_fmt = 'H:i';

function fmtDur(string $start, string $end): string {
    $d = max(0, strtotime($end) - strtotime($start));
    $h = floor($d / 3600); $m = floor(($d % 3600) / 60);
    return $h ? "{$h}h {$m}m" : "{$m}m";
}
function fmtVol(float $v, string $unit): string {
    return $v >= 1000 ? number_format($v / 1000, 1) . 'k ' . $unit : number_format($v, 0) . ' ' . $unit;
}

$workout_id  = isset($_GET['id'])          ? (int)$_GET['id']          : null;
$exercise_id = isset($_GET['exercise_id']) ? (int)$_GET['exercise_id'] : null;
$tab = ($_GET['tab'] ?? 'workouts') === 'cycles' ? 'cycles' : 'workouts';

// ============================================================
// EXERCISE HISTORY VIEW
// ============================================================
if ($exercise_id) {
    $ex_stmt = $db->prepare("SELECT id, name, category, muscle_primary FROM exercises WHERE id = ?");
    $ex_stmt->execute([$exercise_id]);
    $ex = $ex_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ex) { header("Location: /forge/exercises/"); exit; }

    // All sessions containing this exercise, with sets
    $sess_stmt = $db->prepare("
        SELECT w.id, w.name, w.started_at, w.finished_at, w.bodyweight_kg, w.era_id
        FROM workouts w
        WHERE w.finished_at IS NOT NULL
          AND EXISTS (SELECT 1 FROM workout_sets ws WHERE ws.workout_id = w.id AND ws.exercise_id = ?)
        ORDER BY w.started_at DESC
    ");
    $sess_stmt->execute([$exercise_id]);
    $sessions = $sess_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Load sets for each session
    $set_stmt = $db->prepare("
        SELECT set_number, set_type, weight_kg, reps, rpe,
               CASE WHEN weight_kg > 0 AND reps = 1 THEN weight_kg
                    WHEN weight_kg > 0 AND reps > 1 AND reps <= 30
                    THEN ROUND(weight_kg * (1 + reps / 30.0), 1)
                    ELSE NULL END as estimated_1rm
        FROM workout_sets
        WHERE workout_id = ? AND exercise_id = ?
        ORDER BY set_number ASC
    ");
    foreach ($sessions as &$s) {
        $set_stmt->execute([$s['id'], $exercise_id]);
        $s['sets'] = $set_stmt->fetchAll(PDO::FETCH_ASSOC);
        $best_1rm = 0.0; $best_set = null;
        foreach ($s['sets'] as $set) if ($set['set_type'] === 'working' && $set['estimated_1rm'] !== null && (float)$set['estimated_1rm'] > $best_1rm) {
            $best_1rm = (float)$set['estimated_1rm']; $best_set = $set;
        }
        $bodyweight = $s['bodyweight_kg'] !== null ? (float)$s['bodyweight_kg'] : forge_cycle_bodyweight($db, (int)$s['era_id'], $s['started_at']);
        $bf = forge_cycle_bodyfat($db, (int)$s['era_id'], $s['started_at']);
        $lean_mass = ($bodyweight !== null && $bf !== null) ? round($bodyweight * (1 - $bf / 100), 1) : null;
        $s['best_1rm'] = round($best_1rm, 1); $s['best_set'] = $best_set;
        $s['bodyweight'] = $bodyweight; $s['bodyfat'] = $bf; $s['lean_mass'] = $lean_mass;
        $s['e1rm_per_bw'] = $bodyweight && $best_1rm > 0 ? round($best_1rm / $bodyweight, 2) : null;
        $s['e1rm_per_lbm'] = $lean_mass && $best_1rm > 0 ? round($best_1rm / $lean_mass, 2) : null;
    }
    unset($s);

    $exercise_cycles = $db->query('SELECT id, era_id, starts_at, ends_at, cycle_type FROM training_cycles ORDER BY starts_at')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sessions as &$s) {
        $s['cycle_id'] = null; $s['cycle_type'] = null;
        foreach ($exercise_cycles as $cycle) {
            if ((int)$cycle['era_id'] === (int)$s['era_id'] && $s['started_at'] >= $cycle['starts_at'] && $s['started_at'] <= $cycle['ends_at']) {
                $s['cycle_id'] = (int)$cycle['id']; $s['cycle_type'] = $cycle['cycle_type']; break;
            }
        }
    }
    unset($s);

    $session_by_id = [];
    foreach ($sessions as $session) $session_by_id[(int)$session['id']] = $session;
    $compare_a_id = (int)($_GET['compare_a'] ?? ($sessions[0]['id'] ?? 0));
    $compare_b_id = (int)($_GET['compare_b'] ?? 0);
    $compare_a = $session_by_id[$compare_a_id] ?? null;
    $compare_b = $session_by_id[$compare_b_id] ?? null;
    $reference_e1rm = $compare_a['best_1rm'] ?? 0;
    $similar_sessions = $reference_e1rm > 0 ? array_values(array_filter($sessions, fn($s) => (int)$s['id'] !== $compare_a_id && $s['cycle_id'] !== $compare_a['cycle_id'] && abs($s['best_1rm'] - $reference_e1rm) / $reference_e1rm <= 0.05)) : [];

    $page_title   = $ex['name'] . ' — History';
    $page_context = 'history';
    include __DIR__ . '/../core/page_head.php';
?>

<div class="container-fluid py-3 px-3" style="max-width:700px; margin:0 auto;">

    <a href="/forge/exercises/?id=<?= $exercise_id ?>" class="d-inline-flex align-items-center gap-1 mb-3 text-decoration-none"
       style="color:#888; font-size:0.8rem; font-weight:700; text-transform:uppercase; letter-spacing:1px;">
        <i class="bi bi-chevron-left"></i> <?= htmlspecialchars($ex['name']) ?>
    </a>

    <div class="mb-4">
        <h1 class="fw-black mb-1" style="font-size:1.4rem; letter-spacing:-1px;">FULL HISTORY</h1>
        <div class="text-muted" style="font-size:0.8rem;"><?= count($sessions) ?> sessions</div>
    </div>

    <?php if (empty($sessions)): ?>
    <div class="text-center py-4 text-muted">No sessions logged yet.</div>
    <?php else: ?>
    <details class="card mb-3"<?= $compare_b ? ' open' : '' ?>><summary class="card-body py-3 px-3 stat-label" style="cursor:pointer;">POINT-IN-TIME COMPARISON</summary><div class="card-body pt-0 px-3">
        <form method="get"><input type="hidden" name="exercise_id" value="<?= $exercise_id ?>">
            <div class="row g-2 align-items-end"><div class="col-5"><label class="stat-label d-block mb-1">POINT A</label><select name="compare_a" style="width:100%;min-height:44px;background:#1e1e1e;color:#e0e0e0;border:1px solid #444;border-radius:5px;padding:7px;font-size:.72rem;">
                <?php foreach ($sessions as $s): ?><option value="<?= $s['id'] ?>"<?= (int)$s['id'] === $compare_a_id ? ' selected' : '' ?>><?= date('j M y',strtotime($s['started_at'])) ?> · <?= $s['cycle_type'] ? forge_cycle_label($s['cycle_type']) . ' · ' : '' ?><?= round((float)$s['best_1rm'], 1) ?> kg</option><?php endforeach; ?>
            </select></div><div class="col-5"><label class="stat-label d-block mb-1">POINT B</label><select name="compare_b" style="width:100%;min-height:44px;background:#1e1e1e;color:#e0e0e0;border:1px solid #444;border-radius:5px;padding:7px;font-size:.72rem;"><option value="0">Choose a session</option>
                <?php foreach ($sessions as $s): ?><option value="<?= $s['id'] ?>"<?= (int)$s['id'] === $compare_b_id ? ' selected' : '' ?>><?= date('j M y',strtotime($s['started_at'])) ?> · <?= $s['cycle_type'] ? forge_cycle_label($s['cycle_type']) . ' · ' : '' ?><?= round((float)$s['best_1rm'], 1) ?> kg</option><?php endforeach; ?>
            </select></div><div class="col-2"><button type="submit" class="btn btn-forge btn-sm w-100 px-1">GO</button></div></div>
        </form>
        <?php if ($compare_a && $compare_b && (int)$compare_a['id'] !== (int)$compare_b['id']): ?>
        <div class="mt-3 pt-2" style="border-top:1px solid #222;">
            <?php foreach (['e1RM' => 'best_1rm', 'Bodyweight' => 'bodyweight', 'Lean mass*' => 'lean_mass', 'e1RM / BW' => 'e1rm_per_bw', 'e1RM / LBM*' => 'e1rm_per_lbm'] as $label => $key): $a=$compare_a[$key]; $b=$compare_b[$key]; $is_mass=in_array($key,['best_1rm','bodyweight','lean_mass'],true); $higher_is_better=$key !== 'bodyweight'; $delta=($a!==null&&$b!==null)?round((float)$b-(float)$a,2):null; $pct=($a!==null&&$b!==null&&(float)$a!==0)?round($delta/(float)$a*100,1):null; $a_color=$b_color='#e0e0e0'; if($delta!==null&&$delta!==0&&$higher_is_better){$a_color=$delta>0?'#ef5350':'#81c784';$b_color=$delta>0?'#81c784':'#ef5350';} ?>
                <div class="d-grid align-items-center py-2" style="grid-template-columns:1fr auto 1fr;border-top:1px solid #1a1a1a;font-size:.78rem;"><strong style="text-align:left;color:<?= $a_color ?>;"><?= $a === null ? '—' : round((float)$a, 2) ?><?= $is_mass ? ' kg' : '' ?><?php if($delta!==null&&$delta!==0&&$higher_is_better): ?><small style="display:block;font-size:.6rem;"><?= $delta>0 ? '-' : '+' ?><?= $is_mass ? abs(round($delta,1)).' kg' : abs($pct).'%' ?></small><?php endif; ?></strong><span class="stat-label px-2" style="font-size:.55rem;text-align:center;white-space:nowrap;"><?= $label ?></span><strong style="text-align:right;color:<?= $b_color ?>;"><?= $b === null ? '—' : round((float)$b, 2) ?><?= $is_mass ? ' kg' : '' ?><?php if($delta!==null&&$delta!==0&&$higher_is_better): ?><small style="display:block;font-size:.6rem;"><?= $delta>0 ? '+' : '-' ?><?= $is_mass ? abs(round($delta,1)).' kg' : abs($pct).'%' ?></small><?php endif; ?></strong></div>
            <?php endforeach; ?>
            <div class="text-muted mt-2" style="font-size:.62rem;">* Lean mass uses caliper body-fat measurements interpolated between logged points.</div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($compare_a && $similar_sessions): ?>
    <div class="px-3 pb-2">
    <div class="stat-label mt-3 mb-1">SIMILAR ABSOLUTE STRENGTH · <?= round((float)$compare_a['best_1rm'],1) ?> KG e1RM</div>
    <div class="py-1">
        <?php foreach (array_slice($similar_sessions, 0, 6) as $s): ?><div class="d-flex justify-content-between align-items-center py-2" style="border-top:1px solid #1a1a1a;font-size:.72rem;"><span><?= date('j M Y',strtotime($s['started_at'])) ?></span><span class="text-muted">BW <?= $s['bodyweight'] === null ? '—' : round((float)$s['bodyweight'], 1) ?> kg · <?= $s['e1rm_per_bw'] === null ? '—' : round((float)$s['e1rm_per_bw'], 2) ?>× BW</span><a href="?exercise_id=<?= $exercise_id ?>&compare_a=<?= $compare_a['id'] ?>&compare_b=<?= $s['id'] ?>" style="color:#FF5722;font-weight:700;text-decoration:none;">COMPARE</a></div><?php endforeach; ?>
    </div>
    </div>
    <?php endif; ?>
    </details>

    <?php foreach ($sessions as $s):
        $best_1rm = $s['best_1rm']; $best_set = $s['best_set'];
    ?>
    <div class="card mb-2">
        <div class="card-body py-2 px-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <div class="fw-black" style="font-size:0.85rem; color:#e0e0e0;"><?= date('D j M Y', strtotime($s['started_at'])) ?></div>
                    <?php if ($s['bodyweight'] !== null): ?><div style="font-size:0.67rem;color:#81c784;font-weight:700;">BW <?= round((float)$s['bodyweight'], 1) ?> kg · <?= $s['e1rm_per_bw'] ?? '—' ?>×</div><?php endif; ?>
                    <?php if ($best_set): ?>
                    <div class="text-muted" style="font-size:0.7rem;">best: <?= $best_set['weight_kg'] ?> kg × <?= $best_set['reps'] ?> — e1RM <?= $best_set['estimated_1rm'] ?> kg</div>
                    <?php endif; ?>
                </div>
                <a href="/forge/history/?id=<?= $s['id'] ?>" class="text-decoration-none" style="font-size:0.65rem; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:0.5px;">WORKOUT <i class="bi bi-arrow-right"></i></a>
            </div>
            <div style="display:grid; grid-template-columns:2rem 1fr 1fr 1fr 1fr; gap:2px 6px; align-items:center;">
                <div class="stat-label" style="font-size:0.55rem;"></div>
                <div class="stat-label" style="font-size:0.55rem;">TYPE</div>
                <div class="stat-label" style="font-size:0.55rem;">WEIGHT</div>
                <div class="stat-label" style="font-size:0.55rem;">REPS</div>
                <div class="stat-label" style="font-size:0.55rem;">e1RM</div>
                <?php foreach ($s['sets'] as $r):
                    $is_best = ($best_set && $r['set_number'] == $best_set['set_number']); ?>
                <div style="font-size:0.7rem; color:<?= $is_best ? '#FF5722' : '#666' ?>;">
                    <?= $is_best ? '<i class="bi bi-trophy-fill"></i>' : '#'.(int)$r['set_number'] ?>
                </div>
                <div style="font-size:0.72rem; color:#888; text-transform:uppercase;"><?= htmlspecialchars($r['set_type']) ?></div>
                <div style="font-size:0.82rem; font-weight:<?= $is_best ? '900' : '400' ?>; color:<?= $is_best ? '#e0e0e0' : '#aaa' ?>;"><?= $r['weight_kg'] ? $r['weight_kg'].' kg' : '—' ?></div>
                <div style="font-size:0.82rem; font-weight:<?= $is_best ? '900' : '400' ?>; color:<?= $is_best ? '#e0e0e0' : '#aaa' ?>;"><?= $r['reps'] ?? '—' ?></div>
                <div style="font-size:0.82rem; color:<?= $is_best ? '#FF5722' : '#666' ?>; font-weight:<?= $is_best ? '700' : '400' ?>;"><?= $r['estimated_1rm'] ? $r['estimated_1rm'].' kg' : '—' ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

</div>

<?php

// ============================================================
// DETAIL VIEW
// ============================================================
} elseif ($workout_id) {
    $stmt = $db->prepare("SELECT * FROM workouts WHERE id = ? AND finished_at IS NOT NULL");
    $stmt->execute([$workout_id]);
    $workout = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$workout) { header("Location: /forge/history/"); exit; }

    $workout_bw = $workout['bodyweight_kg'] !== null ? (float)$workout['bodyweight_kg'] : null;
    $workout_bw_source = $workout_bw !== null ? 'logged' : null;
    if ($workout_bw === null) {
        $before = $db->prepare("SELECT measured_at, weight_kg FROM bodyweight_measurements WHERE era_id = ? AND measured_at <= ? ORDER BY measured_at DESC LIMIT 1");
        $after = $db->prepare("SELECT measured_at, weight_kg FROM bodyweight_measurements WHERE era_id = ? AND measured_at >= ? ORDER BY measured_at ASC LIMIT 1");
        $before->execute([(int)$workout['era_id'], $workout['started_at']]);
        $after->execute([(int)$workout['era_id'], $workout['started_at']]);
        $a = $before->fetch(PDO::FETCH_ASSOC); $b = $after->fetch(PDO::FETCH_ASSOC);
        if ($a && $b) {
            $at = strtotime($workout['started_at']); $ta = strtotime($a['measured_at']); $tb = strtotime($b['measured_at']);
            $fraction = $ta === $tb ? 0 : ($at - $ta) / ($tb - $ta);
            $workout_bw = (float)$a['weight_kg'] + $fraction * ((float)$b['weight_kg'] - (float)$a['weight_kg']);
            $workout_bw_source = 'interpolated';
        } elseif ($a || $b) {
            $point = $a ?: $b;
            $workout_bw = (float)$point['weight_kg'];
            $workout_bw_source = 'nearest available';
        }
    }

    $stmt = $db->prepare("
        SELECT ws.*, e.name as exercise_name, e.muscle_primary, e.category
        FROM workout_sets ws
        JOIN exercises e ON e.id = ws.exercise_id
        WHERE ws.workout_id = ?
        ORDER BY ws.id ASC
    ");
    $stmt->execute([$workout_id]);
    $exercises = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $eid = (int)$s['exercise_id'];
        if (!isset($exercises[$eid])) {
            $exercises[$eid] = [
                'name' => $s['exercise_name'],
                'muscle' => $s['muscle_primary'],
                'category' => $s['category'],
                'sets' => []
            ];
        }
        $exercises[$eid]['sets'][] = $s;
    }

    // HR timeline data
    $hr_stmt = $db->prepare("SELECT sampled_at, bpm FROM workout_hr_samples WHERE workout_id = ? ORDER BY sampled_at ASC");
    $hr_stmt->execute([$workout_id]);
    $hr_samples_raw = $hr_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Set completion events (working sets with logged_at)
    $se_stmt = $db->prepare("
        SELECT ws.logged_at, ws.set_number, ws.set_type, ws.hr_max, e.name as exercise_name
        FROM workout_sets ws
        JOIN exercises e ON e.id = ws.exercise_id
        WHERE ws.workout_id = ? AND ws.logged_at IS NOT NULL AND ws.set_type = 'working'
        ORDER BY ws.logged_at ASC
    ");
    $se_stmt->execute([$workout_id]);
    $set_events_raw = $se_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert timestamps to seconds-since-workout-start.
    // workout.started_at and logged_at are PHP-generated (Australia/Sydney local time).
    // workout_hr_samples.sampled_at is JS-generated via toISOString() — stored as UTC without 'Z'.
    // Appending ' UTC' to sampled_at forces correct UTC parsing regardless of server timezone.
    $workout_start_ts = strtotime($workout['started_at']);
    $hr_samples_js = array_map(fn($s) => [
        'x' => strtotime($s['sampled_at'] . ' UTC') - $workout_start_ts,
        'y' => (int)$s['bpm'],
    ], $hr_samples_raw);
    $set_events_js = array_map(fn($s) => [
        'x'    => strtotime($s['logged_at']) - $workout_start_ts,
        'name' => $s['exercise_name'],
        'set'  => (int)$s['set_number'],
        'hrMax' => $s['hr_max'] ? (int)$s['hr_max'] : null,
    ], $set_events_raw);

    $has_hr = count($hr_samples_raw) >= 2;

    // HR summary stats
    if ($has_hr) {
        $bpms = array_column($hr_samples_raw, 'bpm');
        $hr_min = min($bpms); $hr_max_val = max($bpms);
        $hr_avg_val = (int)round(array_sum($bpms) / count($bpms));
    }

    $total_volume = 0; $working_sets = 0;
    foreach ($exercises as $ex) {
        foreach ($ex['sets'] as $s) {
            if ($s['set_type'] === 'working' && $s['weight_kg'] && $s['reps']) {
                $total_volume += $s['weight_kg'] * $s['reps']; $working_sets++;
            }
        }
    }

    // Profile HR for %HRR and HRRec computation in set tables
    $profile_resting_hr = (int)get_user_setting($db, 'profile_resting_hr', '0');
    $profile_max_hr     = (int)get_user_setting($db, 'profile_max_hr',     '0');
    $can_compute_hrr    = $profile_resting_hr > 0 && $profile_max_hr > $profile_resting_hr;

    // Build HR sample time-index for HRRec lookups: ts (unix, UTC) => bpm
    // sampled_at stored as UTC (JS toISOString), logged_at stored as AEST (PHP)
    $hr_ts_map = [];
    foreach ($hr_samples_raw as $sp) {
        $hr_ts_map[strtotime($sp['sampled_at'] . ' UTC')] = (int)$sp['bpm'];
    }
    ksort($hr_ts_map);
    $hr_ts_keys = array_keys($hr_ts_map);

    // Find bpm closest to target unix timestamp, within $tolerance seconds. Returns null if no match.
    function nearest_hr_bpm(int $target_ts, array $ts_map, array $ts_keys, int $tolerance = 20): ?int {
        if (empty($ts_keys)) return null;
        $lo = 0; $hi = count($ts_keys) - 1;
        while ($lo < $hi) { $mid = ($lo + $hi) >> 1; if ($ts_keys[$mid] < $target_ts) $lo = $mid + 1; else $hi = $mid; }
        // $lo is now the first key >= target; check lo and lo-1
        $best_ts = $ts_keys[$lo];
        if ($lo > 0 && abs($ts_keys[$lo - 1] - $target_ts) < abs($best_ts - $target_ts)) {
            $best_ts = $ts_keys[$lo - 1];
        }
        return abs($best_ts - $target_ts) <= $tolerance ? $ts_map[$best_ts] : null;
    }

    $page_title   = $workout['name'] ?: date('j M Y', strtotime($workout['started_at']));
    $page_context = 'history';
    $extra_head   = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';
    include __DIR__ . '/../core/page_head.php';
?>

<div class="container-fluid py-3 px-3" style="max-width:700px; margin:0 auto;">

    <a href="/forge/history/" class="d-inline-flex align-items-center gap-1 mb-3 text-decoration-none"
       style="color:#888; font-size:0.8rem; font-weight:700; text-transform:uppercase; letter-spacing:1px;">
        <i class="bi bi-chevron-left"></i> HISTORY
    </a>

    <div class="d-flex align-items-start justify-content-between mb-3">
        <div>
            <h2 class="fw-black mb-1" style="font-size:1.4rem; letter-spacing:-1px;">
                <?= htmlspecialchars($workout['name'] ?: date('l, j F', strtotime($workout['started_at']))) ?>
            </h2>
            <div class="text-muted" style="font-size:0.8rem;">
                <?= date('D j M Y · ' . $time_fmt, strtotime($workout['started_at'])) ?>
            </div>
            <?php if ($workout_bw !== null): ?>
            <div style="font-size:0.72rem;color:#81c784;font-weight:700;margin-top:3px;">BW <?= round($workout_bw, 1) ?> kg <span style="color:#888;font-weight:400;">· <?= $workout_bw_source ?></span></div>
            <?php endif; ?>
        </div>
        <button class="btn btn-sm" onclick="deleteWorkout(<?= $workout_id ?>)"
                style="background:transparent; border:1px solid #333; color:#555; min-height:36px; padding:0 12px;">
            <i class="bi bi-trash" style="font-size:0.85rem;"></i>
        </button>
    </div>

    <?php
    // For cardio sessions, pull distance + elevation from the single set row
    $cardio_distance = null; $cardio_elevation = null; $cardio_intensity = null;
    if (($workout['type'] ?? 'strength') === 'cardio') {
        foreach ($exercises as $ex) {
            foreach ($ex['sets'] as $s) {
                if ($s['distance_km'])  $cardio_distance  = (float)$s['distance_km'];
                if ($s['elevation_m'])  $cardio_elevation = (float)$s['elevation_m'];
                if ($s['intensity'])    $cardio_intensity = (int)$s['intensity'];
            }
        }
    }
    ?>
    <div class="row g-2 mb-3">
        <div class="col-3 text-center"><div class="stat-label">TIME</div><div class="stat-value" style="font-size:1.1rem;"><?= fmtDur($workout['started_at'], $workout['finished_at']) ?></div></div>
        <?php if (($workout['type'] ?? 'strength') === 'cardio'): ?>
        <div class="col-3 text-center"><div class="stat-label">DIST</div><div class="stat-value" style="font-size:1.1rem;"><?= $cardio_distance ? $cardio_distance . ' km' : '—' ?></div></div>
        <div class="col-3 text-center"><div class="stat-label">ELEV</div><div class="stat-value" style="font-size:1.1rem;"><?= $cardio_elevation ? round($cardio_elevation) . 'm' : '—' ?></div></div>
        <div class="col-3 text-center"><div class="stat-label">INT</div><div class="stat-value" style="font-size:1.1rem;"><?= $cardio_intensity ?? '—' ?></div></div>
        <?php else: ?>
        <div class="col-3 text-center"><div class="stat-label">EXERCISES</div><div class="stat-value" style="font-size:1.1rem;"><?= count($exercises) ?></div></div>
        <div class="col-3 text-center"><div class="stat-label">SETS</div><div class="stat-value" style="font-size:1.1rem;"><?= $working_sets ?></div></div>
        <div class="col-3 text-center"><div class="stat-label">VOLUME</div><div class="stat-value" style="font-size:0.95rem;"><?= $total_volume > 0 ? fmtVol($total_volume, $unit) : '—' ?></div></div>
        <?php endif; ?>
    </div>

    <?php if ($workout['notes']): ?>
    <div class="card mb-3" style="border-left:3px solid #FF5722;">
        <div class="card-body py-2 px-3">
            <div class="stat-label mb-1">NOTES</div>
            <div style="font-size:0.9rem;"><?= nl2br(htmlspecialchars($workout['notes'])) ?></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($has_hr): ?>
    <div class="card mb-3">
        <div class="card-body py-2 px-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="stat-label"><i class="bi bi-heart-pulse me-1"></i>HEART RATE</div>
                <div style="font-family:monospace; font-size:0.72rem; color:#888;">
                    <span style="color:#555;">min</span> <span style="color:#e0e0e0; font-weight:700;"><?= $hr_min ?></span>
                    &nbsp;<span style="color:#555;">avg</span> <span style="color:#e0e0e0; font-weight:700;"><?= $hr_avg_val ?></span>
                    &nbsp;<span style="color:#555;">max</span> <span style="color:#ef5350; font-weight:700;"><?= $hr_max_val ?></span>
                    &nbsp;<span style="color:#555;">bpm</span>
                </div>
            </div>
            <div style="position:relative; height:110px;">
                <canvas id="hr-timeline-chart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php foreach ($exercises as $eid => $ex):
        $is_cardio = ($ex['category'] === 'Cardio');
    ?>
    <div class="card mb-3">
        <div class="card-body pb-2">
            <div class="d-flex align-items-center gap-2 mb-2">
                <a href="/forge/exercises/?id=<?= (int)$eid ?>" class="fw-black uppercase text-decoration-none" style="font-size:0.9rem; letter-spacing:-0.3px; color:#e0e0e0;"><?= htmlspecialchars($ex['name']) ?></a>
                <?php if ($ex['muscle']): ?>
                <span class="muscle-badge muscle-<?= strtolower(preg_replace('/[^a-zA-Z]/', '', $ex['muscle'])) ?>"><?= htmlspecialchars($ex['muscle']) ?></span>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
            <table class="set-table" style="table-layout:fixed; width:100%;">
                <?php if ($is_cardio): ?>
                <colgroup><col style="width:28px;"><col><col><col><col style="width:52px;"><col style="width:44px;"><col style="width:44px;"></colgroup>
                <thead><tr><th>#</th><th>TIME</th><th>KM</th><th>ELEV</th><th>AVG SPEED</th><th>AVG HR</th><th>MAX HR</th></tr></thead>
                <tbody>
                <?php foreach ($ex['sets'] as $i => $s):
                    $set_speed = ($s['distance_km'] && $s['duration_sec'])
                        ? round($s['distance_km'] / ($s['duration_sec'] / 3600.0), 2) . 'k/h'
                        : '—';
                    $dur_fmt = $s['duration_sec']
                        ? (floor($s['duration_sec'] / 3600) > 0
                            ? floor($s['duration_sec'] / 3600) . 'h ' . gmdate('i:s', (int)$s['duration_sec'] % 3600)
                            : gmdate('i:s', (int)$s['duration_sec']))
                        : '—';
                ?>
                <tr>
                    <td style="font-weight:900; color:#888; font-size:0.9rem;"><?= $i + 1 ?></td>
                    <td style="font-weight:700;"><?= $dur_fmt ?></td>
                    <td style="font-weight:700;"><?= $s['distance_km'] ? $s['distance_km'] . ' km' : '—' ?></td>
                    <td style="color:#888;"><?= $s['elevation_m'] ? $s['elevation_m'] . 'm' : '—' ?></td>
                    <td style="font-weight:700;"><?= $set_speed ?></td>
                    <td style="color:#888;"><?= $s['hr_avg'] ?? '—' ?></td>
                    <td style="color:#888;"><?= $s['hr_max'] ?? '—' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php else:
                    // Determine if any set in this exercise has HR data — show %HRR column if so
                    $ex_has_hr = $can_compute_hrr && array_reduce($ex['sets'], fn($carry, $s) => $carry || !empty($s['hr_max']), false);
                ?>
                <?php if ($ex_has_hr): ?>
                <colgroup><col style="width:28px;"><col style="width:64px;"><col><col><col style="width:44px;"><col style="width:48px;"></colgroup>
                <thead><tr><th>#</th><th>TYPE</th><th><?= strtoupper($unit) ?></th><th>REPS</th><th>RPE</th><th><i class="bi bi-heart-pulse" title="%HRR / HRRec"></i></th></tr></thead>
                <?php else: ?>
                <colgroup><col style="width:28px;"><col style="width:70px;"><col><col><col style="width:52px;"></colgroup>
                <thead><tr><th>#</th><th>TYPE</th><th><?= strtoupper($unit) ?></th><th>REPS</th><th>RPE</th></tr></thead>
                <?php endif; ?>
                <tbody>
                <?php foreach ($ex['sets'] as $i => $s):
                    // Compute %HRR for this set
                    $hrr_pct = null;
                    if ($can_compute_hrr && !empty($s['hr_max'])) {
                        $pct = ($s['hr_max'] - $profile_resting_hr) / ($profile_max_hr - $profile_resting_hr) * 100;
                        if ($pct >= 0 && $pct <= 200) $hrr_pct = (int)round($pct);
                    }
                    // Compute HRRec for this set (bpm drop at 60s post-set)
                    $hrrec = null;
                    if (!empty($s['hr_max']) && !empty($s['logged_at']) && !empty($hr_ts_keys)) {
                        $target_ts   = strtotime($s['logged_at']) + 60;
                        $recovery_bpm = nearest_hr_bpm($target_ts, $hr_ts_map, $hr_ts_keys);
                        if ($recovery_bpm !== null) {
                            $drop = (int)$s['hr_max'] - $recovery_bpm;
                            if ($drop > 0) $hrrec = $drop;
                        }
                    }
                ?>
                <tr class="<?= $s['set_type'] === 'warmup' ? 'set-warmup' : '' ?>">
                    <td style="font-weight:900; color:#888; font-size:0.9rem;"><?= $i + 1 ?></td>
                    <td><span class="set-type-badge badge-<?= $s['set_type'] ?>"><?= $s['set_type'] ?></span></td>
                    <td style="font-weight:700;"><?= $s['weight_kg'] ?? '—' ?></td>
                    <td style="font-weight:700;"><?= $s['reps'] ?? '—' ?></td>
                    <td style="color:#888;"><?= $s['rpe'] ?? '—' ?></td>
                    <?php if ($ex_has_hr): ?>
                    <td style="color:#ef5350; font-size:0.75rem; line-height:1.3;">
                        <?php if ($hrr_pct !== null): ?>
                            <span style="font-weight:700;"><?= $hrr_pct ?>%</span>
                            <?php if ($hrrec !== null): ?>
                            <br><span style="color:#ab47bc; font-size:0.65rem;">↓<?= $hrrec ?></span>
                            <?php endif; ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($exercises)): ?>
    <p class="text-muted text-center py-4">No sets logged in this workout.</p>
    <?php endif; ?>

</div>

<script>
async function deleteWorkout(id) {
    if (!confirm('Delete this workout? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action', 'delete_workout');
    fd.append('id', id);
    const r = await fetch('/forge/core/api.php', { method: 'POST', body: fd }).then(r => r.json());
    if (r.status === 'success') window.location.href = '/forge/history/';
    else alert('Error: ' + r.message);
}

<?php if ($has_hr): ?>
(function () {
    const hrData      = <?= json_encode(array_values($hr_samples_js)) ?>;
    const setEvents   = <?= json_encode(array_values($set_events_js)) ?>;

    // Find nearest HR value to a given x (seconds), within maxGap seconds
    function nearestHR(sec, maxGap = 30) {
        let best = null, bestDiff = Infinity;
        for (const p of hrData) {
            const d = Math.abs(p.x - sec);
            if (d < bestDiff) { bestDiff = d; best = p.y; }
        }
        return bestDiff <= maxGap ? best : null;
    }

    // Build scatter markers for set events that have an HR reading nearby
    const setMarkers = setEvents.map(ev => {
        const y = ev.hrMax ?? nearestHR(ev.x);
        if (!y) return null;
        return { x: ev.x, y, name: ev.name, set: ev.set };
    }).filter(Boolean);

    // Custom plugin: draw orange vertical dashed lines at set completion times
    const setLinePlugin = {
        id: 'setLines',
        afterDraw(chart) {
            const { ctx, scales: { x: xScale, y: yScale } } = chart;
            ctx.save();
            ctx.strokeStyle = 'rgba(255,87,34,0.25)';
            ctx.lineWidth = 1;
            ctx.setLineDash([3, 4]);
            for (const m of setMarkers) {
                const px = xScale.getPixelForValue(m.x);
                ctx.beginPath();
                ctx.moveTo(px, yScale.top);
                ctx.lineTo(px, yScale.bottom);
                ctx.stroke();
            }
            ctx.restore();
        }
    };

    // x-axis tick formatter: show "Nm" at minute boundaries
    function fmtSec(v) {
        if (v % 60 !== 0) return '';
        const m = Math.round(v / 60);
        return m === 0 ? '0' : m + 'm';
    }

    Chart.defaults.color = '#888';
    Chart.defaults.borderColor = '#333';

    new Chart(document.getElementById('hr-timeline-chart'), {
        type: 'line',
        data: {
            datasets: [
                {
                    data: hrData,
                    borderColor: '#ef5350',
                    backgroundColor: 'rgba(239,83,80,0.08)',
                    fill: true,
                    pointRadius: 0,
                    borderWidth: 1.5,
                    tension: 0.3,
                    label: 'HR',
                    order: 2,
                },
                {
                    type: 'scatter',
                    data: setMarkers,
                    backgroundColor: '#FF5722',
                    pointRadius: 4,
                    pointStyle: 'triangle',
                    pointHoverRadius: 6,
                    label: 'Set',
                    order: 1,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            scales: {
                x: {
                    type: 'linear',
                    grid: { color: '#1e1e1e' },
                    ticks: {
                        color: '#555',
                        font: { size: 9 },
                        maxTicksLimit: 10,
                        callback: fmtSec,
                    }
                },
                y: {
                    grid: { color: '#1e1e1e' },
                    ticks: { color: '#555', font: { size: 9 }, maxTicksLimit: 5 },
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title(items) {
                            const v = items[0].parsed.x;
                            const m = Math.floor(v / 60);
                            const s = String(Math.round(v % 60)).padStart(2, '0');
                            return m + ':' + s;
                        },
                        label(item) {
                            if (item.datasetIndex === 1) {
                                return item.raw.name + ' set ' + item.raw.set + ' — ' + item.raw.y + ' bpm';
                            }
                            return item.parsed.y + ' bpm';
                        }
                    }
                }
            }
        },
        plugins: [setLinePlugin]
    });
})();
<?php endif; ?>
</script>

<?php

// ============================================================
// LIST VIEW
// ============================================================
} elseif ($tab === 'cycles') {
    $cycles = $db->query("SELECT c.*, e.name AS era_name FROM training_cycles c JOIN training_eras e ON e.id = c.era_id ORDER BY c.starts_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $compare_raw = $_GET['compare'] ?? [];
    if (!is_array($compare_raw)) $compare_raw = explode(',', (string)$compare_raw);
    $compare_ids = array_values(array_unique(array_filter(array_map('intval', $compare_raw))));
    $selected = array_values(array_filter($cycles, fn($cycle) => in_array((int)$cycle['id'], $compare_ids, true)));
    $page_title = 'Cycle Analysis'; $page_context = 'history';
    include __DIR__ . '/../core/page_head.php';
?>
<div class="container-fluid py-3 px-3" style="max-width:700px;margin:0 auto;">
    <div class="d-flex align-items-baseline justify-content-between mb-3">
        <h1 class="fw-black mb-0" style="font-size:1.6rem;letter-spacing:-1.5px;">HISTORY<span style="color:#FF5722;">_</span></h1>
        <div class="text-muted" style="font-size:.72rem;">CYCLE ANALYSIS</div>
    </div>
    <div class="d-flex mb-3" style="background:#111;border-radius:8px;padding:3px;gap:3px;">
        <a href="/forge/history/" class="seg-btn flex-fill text-decoration-none text-center">WORKOUTS</a>
        <a href="/forge/history/?tab=cycles" class="seg-btn active flex-fill text-decoration-none text-center">CYCLES</a>
    </div>

    <div id="cycle-detection-preview" class="d-none card mb-3"><div class="card-body"></div></div>

    <?php
    $range_value = static function (array $m, string $start, string $end, string $unit): string {
        return isset($m[$start], $m[$end]) ? round((float)$m[$start], 1) . ' → ' . round((float)$m[$end], 1) . $unit : '—';
    };
    ?>
    <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="stat-label mb-0">ALL CYCLES</div>
        <div class="d-flex align-items-center gap-1">
            <?php if ($compare_ids): ?><span class="text-muted" style="font-size:.7rem;"><?= count($compare_ids) ?>/3 selected</span><?php endif; ?>
            <button id="reanalyse-cycles-btn" class="btn-icon" type="button" onclick="previewCycleDetection()" aria-label="Reanalyse cycles" title="Reanalyse cycles"><i class="bi bi-arrow-repeat" style="font-size:.9rem;color:#888;"></i></button>
        </div>
    </div>
    <?php foreach ($cycles as $cycle):
        $m = json_decode($cycle['metrics_json'] ?? '{}', true) ?: [];
        $color = $cycle['cycle_type'] === 'bulk' ? '#81c784' : ($cycle['cycle_type'] === 'cut' ? '#64b5f6' : '#ce93d8');
        $is_compared = in_array((int)$cycle['id'], $compare_ids, true);
        $weight_change = isset($m['start_weight'], $m['end_weight']) ? (float)$m['end_weight'] - (float)$m['start_weight'] : null;
    ?>
    <details class="card cycle-card mb-2 <?= $is_compared ? 'cycle-card-selected' : '' ?>" style="border-left-color:<?= $color ?>;">
        <summary class="card-body py-2 px-3 cycle-card-summary">
            <div class="d-flex align-items-start justify-content-between gap-2">
                <div>
                    <strong style="font-size:.78rem;color:<?= $color ?>;"><?= strtoupper(forge_cycle_label($cycle['cycle_type'])) ?></strong>
                    <div class="cycle-summary-meta"><?= date('j M Y', strtotime($cycle['starts_at'])) ?> – <?= date('j M Y', strtotime($cycle['ends_at'])) ?></div>
                </div>
                <form method="get" onclick="event.stopPropagation();" class="m-0">
                    <input type="hidden" name="tab" value="cycles">
                    <?php foreach ($compare_ids as $id): if ($is_compared && $id === (int)$cycle['id']) continue; ?><input type="hidden" name="compare[]" value="<?= $id ?>"><?php endforeach; ?>
                    <?php if (!$is_compared && count($compare_ids) < 3): ?><input type="hidden" name="compare[]" value="<?= $cycle['id'] ?>"><?php endif; ?>
                    <?php if ($is_compared || count($compare_ids) < 3): ?><button class="btn <?= $is_compared ? 'btn-forge-outline' : 'btn-forge' ?> btn-sm cycle-compare-toggle" aria-label="<?= $is_compared ? 'Remove from comparator' : 'Add to comparator' ?>"><?= $is_compared ? '−' : '+' ?></button><?php endif; ?>
                </form>
            </div>
            <div class="cycle-summary-stats mt-2">
                <strong><?= (int)($m['sessions'] ?? 0) ?></strong> sessions · <strong><?= round((float)($m['sessions_per_week'] ?? 0), 1) ?></strong>/wk · <strong><?= number_format((float)($m['volume_kg'] ?? 0) / 1000, 1) ?>k</strong> kg · BW <strong><?= $weight_change === null ? '—' : (($weight_change >= 0 ? '+' : '') . round($weight_change, 1) . ' kg') ?></strong>
            </div>
        </summary>
        <div class="card-body pt-0 px-3">
            <div class="row g-0 cycle-detail-grid">
                <section class="col-md-6 cycle-detail-section pe-md-3 py-2">
                    <div class="stat-label mb-1">BODY COMPOSITION</div>
                    <div class="cycle-detail-row"><span>Bodyweight</span><span class="cycle-detail-value"><?= $range_value($m, 'start_weight', 'end_weight', ' kg') ?></span></div>
                    <div class="cycle-detail-row"><span>Weekly BW change</span><span class="cycle-detail-value"><?= isset($m['weekly_rate_kg']) ? (($m['weekly_rate_kg'] >= 0 ? '+' : '') . round((float)$m['weekly_rate_kg'], 2) . ' kg') : '—' ?></span></div>
                    <div class="cycle-detail-row"><span>Body fat</span><span class="cycle-detail-value"><?= $range_value($m, 'body_fat_start_pct', 'body_fat_end_pct', '%') ?></span></div>
                    <div class="cycle-detail-row"><span>Estimated lean mass</span><span class="cycle-detail-value"><?= $range_value($m, 'start_lean_mass_kg', 'end_lean_mass_kg', ' kg') ?></span></div>
                </section>
                <section class="col-md-6 cycle-detail-section ps-md-3 py-2">
                    <div class="stat-label mb-1">TRAINING &amp; STRENGTH</div>
                    <div class="cycle-detail-row"><span>Sessions / week</span><span class="cycle-detail-value"><?= (int)($m['sessions'] ?? 0) ?> · <?= round((float)($m['sessions_per_week'] ?? 0), 1) ?>/wk</span></div>
                    <div class="cycle-detail-row"><span>Working sets</span><span class="cycle-detail-value"><?= (int)($m['working_sets'] ?? 0) ?></span></div>
                    <div class="cycle-detail-row"><span>Volume</span><span class="cycle-detail-value"><?= number_format((float)($m['volume_kg'] ?? 0) / 1000, 1) ?>k kg</span></div>
                    <div class="cycle-detail-row"><span>Big Four average</span><span class="cycle-detail-value" style="color:<?= ($m['strength_change_pct'] ?? 0) >= 0 ? '#81c784' : '#ef5350' ?>;"><?= isset($m['strength_change_pct']) ? (($m['strength_change_pct'] >= 0 ? '+' : '') . round((float)$m['strength_change_pct'], 1) . '%') : '—' ?></span></div>
                    <?php foreach (FORGE_CYCLE_BIG_FOUR as $lift): if (!isset($m['lift_metrics'][$lift])) continue; $lm = $m['lift_metrics'][$lift]; ?>
                    <div class="cycle-detail-row"><span><?= htmlspecialchars(str_replace('Barbell ', '', $lift)) ?> e1RM</span><span class="cycle-detail-value"><?= round((float)$lm['start_e1rm'], 1) ?> → <?= round((float)$lm['end_e1rm'], 1) ?> kg · <span style="color:<?= $lm['change_pct'] >= 0 ? '#81c784' : '#ef5350' ?>;"><?= $lm['change_pct'] >= 0 ? '+' : '' ?><?= round((float)$lm['change_pct'], 1) ?>%</span></span></div>
                    <?php endforeach; ?>
                </section>
            </div>
        </div>
    </details>
    <?php endforeach; ?>

    <?php if (count($selected) >= 2): ?>
    <div class="stat-label mb-2 mt-3">COMPARISON</div>
    <div class="card mb-3 cycle-comparison"><div class="card-body p-0">
        <table style="width:100%;border-collapse:collapse;font-size:.72rem;">
            <thead><tr><th style="padding:10px;text-align:left;color:#888;">METRIC</th><?php foreach ($selected as $cycle): ?><th style="padding:10px;text-align:right;color:<?= $cycle['cycle_type']==='bulk'?'#81c784':($cycle['cycle_type']==='cut'?'#64b5f6':'#ce93d8') ?>;"><?= strtoupper(forge_cycle_label($cycle['cycle_type'])) ?><br><span style="color:#888;font-weight:400;"><?= date('j M Y',strtotime($cycle['starts_at'])) ?> – <?= date('j M Y',strtotime($cycle['ends_at'])) ?></span><form method="get" class="mt-1"><input type="hidden" name="tab" value="cycles"><?php foreach ($compare_ids as $id): if ($id !== (int)$cycle['id']): ?><input type="hidden" name="compare[]" value="<?= $id ?>"><?php endif; endforeach; ?><button class="btn btn-forge-outline btn-sm cycle-compare-toggle" aria-label="Remove <?= htmlspecialchars(forge_cycle_label($cycle['cycle_type'])) ?> from comparator">−</button></form></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php
            $rows = [
                'Bodyweight' => fn($m) => $range_value($m, 'start_weight', 'end_weight', ' kg'),
                'Weekly BW change' => fn($m) => isset($m['weekly_rate_kg']) ? ($m['weekly_rate_kg'] >= 0 ? '+' : '').round((float)$m['weekly_rate_kg'], 2).' kg' : '—',
                'Body fat' => fn($m) => $range_value($m, 'body_fat_start_pct', 'body_fat_end_pct', '%'),
                'Estimated lean mass' => fn($m) => $range_value($m, 'start_lean_mass_kg', 'end_lean_mass_kg', ' kg'),
                'Sessions / week' => fn($m) => (int)($m['sessions'] ?? 0).' · '.round((float)($m['sessions_per_week'] ?? 0), 1).'/wk',
                'Working sets' => fn($m) => (string)($m['working_sets'] ?? '—'),
                'Volume' => fn($m) => isset($m['volume_kg']) ? number_format((float)$m['volume_kg']/1000,1).'k kg' : '—',
                'Big Four average' => fn($m) => isset($m['strength_change_pct']) ? (($m['strength_change_pct'] >= 0 ? '+' : '').round((float)$m['strength_change_pct'], 1).'%') : '—',
            ];
            foreach (FORGE_CYCLE_BIG_FOUR as $lift) $rows[str_replace('Barbell ', '', $lift)] = fn($m) => isset($m['lift_metrics'][$lift]) ? (round((float)$m['lift_metrics'][$lift]['start_e1rm'], 1).' → '.round((float)$m['lift_metrics'][$lift]['end_e1rm'], 1).' kg · '.(($m['lift_metrics'][$lift]['change_pct'] >= 0 ? '+' : '').round((float)$m['lift_metrics'][$lift]['change_pct'], 1).'%')) : '—';
            foreach ($rows as $label => $format): ?>
                <tr style="border-top:1px solid #1a1a1a;"><td style="padding:9px 10px;color:#888;"><?= htmlspecialchars($label) ?></td><?php foreach ($selected as $cycle): $m=json_decode($cycle['metrics_json']??'{}',true)?:[]; $value=$format($m); ?><td style="padding:9px 10px;text-align:right;color:<?= str_starts_with($value,'-') ? '#ef5350' : '#e0e0e0' ?>;"><?= htmlspecialchars($value) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
    <?php endif; ?>

<script>
async function previewCycleDetection() {
    const button = document.getElementById('reanalyse-cycles-btn');
    const preview = document.getElementById('cycle-detection-preview');
    const body = preview.querySelector('.card-body');
    button.disabled = true; button.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    const result = await window.forgeApi('preview_cycle_detection');
    button.disabled = false; button.innerHTML = '<i class="bi bi-arrow-repeat" style="font-size:.9rem;color:#888;"></i>';
    if (result.status !== 'success') { alert('Could not analyse cycles: ' + (result.message || 'Unknown error')); return; }
    const data = result.data;
    window.cycleProposalSignature = data.proposal_signature || '';
    preview.classList.remove('d-none');
    body.innerHTML = '';
    const heading = document.createElement('div'); heading.className = 'stat-label mb-2'; heading.textContent = data.changed ? 'PROPOSED CYCLE UPDATE' : 'NO CYCLE CHANGES DETECTED'; body.appendChild(heading);
    const copy = document.createElement('div'); copy.className = 'text-muted mb-2'; copy.style.fontSize = '.76rem'; copy.textContent = data.changed ? `${data.removed.length} saved cycle${data.removed.length === 1 ? '' : 's'} will be replaced by ${data.added.length} proposed cycle${data.added.length === 1 ? '' : 's'}.` : 'The detector agrees with the currently saved cycle boundaries.'; body.appendChild(copy);
    if (data.changed) {
        const makeList = (label, cycles, color) => { const section = document.createElement('div'); section.style.cssText = 'border-top:1px solid #333;padding-top:8px;margin-top:8px;'; const title = document.createElement('div'); title.className = 'stat-label mb-1'; title.style.color = color; title.textContent = label; section.appendChild(title); cycles.sort((a,b) => a.starts_at.localeCompare(b.starts_at)).forEach(cycle => { const row = document.createElement('div'); row.style.cssText = 'padding:6px 0;border-bottom:1px solid #292929;font-size:.74rem;'; row.textContent = `${cycle.cycle_type.toUpperCase()} · ${cycle.starts_at.slice(0,10)} – ${cycle.ends_at.slice(0,10)}`; section.appendChild(row); }); return section; };
        if (data.removed.length) body.appendChild(makeList('SAVED CYCLES TO REPLACE', data.removed, '#ef5350'));
        if (data.added.length) body.appendChild(makeList('PROPOSED AUTO CYCLES', data.added, '#81c784'));
        const apply = document.createElement('button'); apply.className = 'btn btn-forge w-100 mt-3'; apply.textContent = 'APPLY PROPOSAL'; apply.onclick = applyCycleDetection; body.appendChild(apply);
    }
}
async function applyCycleDetection() {
    if (!confirm('Replace the saved automatic cycles with this proposal?')) return;
    const result = await window.forgeApi('apply_cycle_detection', { proposal_signature: window.cycleProposalSignature || '' });
    if (result.status !== 'success') { alert('Could not apply cycle proposal: ' + (result.message || 'Unknown error')); return; }
    window.location.href = '/forge/history/?tab=cycles';
}
</script>

</div>
</body></html>
<?php
} else {
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $cycle_id = isset($_GET['cycle']) ? max(0, (int)$_GET['cycle']) : -1;
    $limit  = 20;
    $offset = ($page - 1) * $limit;

    $cycles = $db->query("SELECT c.*, e.name AS era_name FROM training_cycles c JOIN training_eras e ON e.id=c.era_id ORDER BY c.starts_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    if ($cycle_id === -1) {
        $current_era = active_training_era_id($db);
        foreach ($cycles as $cycle) if ((int)$cycle['era_id'] === $current_era) { $cycle_id = (int)$cycle['id']; break; }
    }
    $selected_cycle = null;
    foreach ($cycles as $cycle) if ((int)$cycle['id'] === $cycle_id) $selected_cycle = $cycle;
    $filter_sql = $selected_cycle ? ' AND w.era_id = ? AND w.started_at >= ? AND w.started_at <= ?' : '';
    $filter_params = $selected_cycle ? [(int)$selected_cycle['era_id'], $selected_cycle['starts_at'], $selected_cycle['ends_at']] : [];
    $cycle_query = $selected_cycle ? '&cycle=' . (int)$selected_cycle['id'] : '';

    $count_stmt = $db->prepare("SELECT COUNT(*) FROM workouts w WHERE w.finished_at IS NOT NULL{$filter_sql}");
    $count_stmt->execute($filter_params);
    $total_sessions = (int)$count_stmt->fetchColumn();
    $total_pages    = ceil($total_sessions / $limit);

    $stmt = $db->prepare("
        SELECT w.*,
               COUNT(DISTINCT ws.exercise_id) as exercise_count,
               COUNT(CASE WHEN ws.set_type='working' THEN 1 END) as working_set_count,
               SUM(CASE WHEN ws.set_type='working' AND ws.weight_kg IS NOT NULL AND ws.reps IS NOT NULL
                        THEN ws.weight_kg * ws.reps ELSE 0 END) as total_volume_kg,
               SUM(ws.distance_km)  as total_distance_km,
               SUM(ws.elevation_m)  as total_elevation_m
        FROM workouts w
        LEFT JOIN workout_sets ws ON ws.workout_id = w.id
        WHERE w.finished_at IS NOT NULL{$filter_sql}
        GROUP BY w.id
        ORDER BY w.started_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([...$filter_params, $limit, $offset]);
    $workouts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $page_title   = 'History';
    $page_context = 'history';
    $extra_head   = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';
    include __DIR__ . '/../core/page_head.php';
?>

<div class="container-fluid py-3 px-3" style="max-width:700px; margin:0 auto;">

    <div class="d-flex align-items-baseline justify-content-between mb-3">
        <h1 class="fw-black mb-0" style="font-size:1.6rem; letter-spacing:-1.5px;">
            HISTORY<span style="color:#FF5722;">_</span>LOG
        </h1>
        <div class="text-muted" style="font-size:0.8rem;">
            <span class="fw-black text-accent" style="font-size:1.1rem;"><?= $total_sessions ?></span> sessions
        </div>
    </div>
    <div class="d-flex mb-3" style="background:#111;border-radius:8px;padding:3px;gap:3px;">
        <a href="/forge/history/" class="seg-btn active flex-fill text-decoration-none text-center">WORKOUTS</a>
        <a href="/forge/history/?tab=cycles" class="seg-btn flex-fill text-decoration-none text-center">CYCLES</a>
    </div>

    <?php if ($cycles): ?>
    <form method="get" action="/forge/history/" class="mb-3">
        <input type="hidden" name="page" value="1">
        <label class="stat-label d-block mb-1">CYCLE FILTER</label>
        <select name="cycle" onchange="this.form.submit()" style="width:100%;background:#1e1e1e;color:#e0e0e0;border:1px solid #444;border-radius:5px;padding:8px;font-size:.78rem;">
            <option value="0">All eras and cycles</option>
            <?php foreach ($cycles as $cycle): ?><option value="<?= $cycle['id'] ?>"<?= (int)$cycle_id === (int)$cycle['id'] ? ' selected' : '' ?>><?= forge_cycle_label($cycle['cycle_type']) ?> · <?= date('M Y',strtotime($cycle['starts_at'])) ?>–<?= date('M Y',strtotime($cycle['ends_at'])) ?></option><?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>

    <!-- Frequency calendar -->
    <div class="mb-3">
        <div class="stat-label mb-2">WORKOUT CALENDAR<?= $selected_cycle ? ' · SELECTED CYCLE' : ' · ALL TIME' ?></div>
        <div class="chart-container" style="padding:12px;">
            <canvas id="heatmap-canvas"></canvas>
        </div>
    </div>

    <!-- Weekly volume chart -->
    <div class="mb-4">
        <div class="stat-label mb-2">WEEKLY VOLUME<?= $selected_cycle ? ' · SELECTED CYCLE' : ' · ALL TIME' ?></div>
        <div class="chart-container" style="padding:12px; height:150px;">
            <canvas id="chart-weekly"></canvas>
        </div>
    </div>

    <!-- Workout list -->
    <?php if ($total_pages > 1): ?>
    <div id="workout-list" class="d-flex justify-content-between align-items-center mb-3">
        <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?><?= $cycle_query ?>#workout-list" class="btn btn-forge-outline btn-sm px-3">← NEWER</a><?php else: ?><span></span><?php endif; ?>
        <label class="text-muted x-small">PAGE
            <select onchange="window.location.href='?page='+this.value+'<?= $cycle_query ?>#workout-list'" style="background:#1e1e1e;color:#888;border:1px solid #444;border-radius:3px;padding:2px 4px;">
                <?php for ($p = 1; $p <= $total_pages; $p++): ?><option value="<?= $p ?>"<?= $p === $page ? ' selected' : '' ?>><?= $p ?> / <?= $total_pages ?></option><?php endfor; ?>
            </select>
        </label>
        <?php if ($page < $total_pages): ?><a href="?page=<?= $page+1 ?><?= $cycle_query ?>#workout-list" class="btn btn-forge-outline btn-sm px-3">OLDER →</a><?php endif; ?>
    </div>
    <?php else: ?><div id="workout-list"></div><?php endif; ?>
    <?php if (empty($workouts) && $page === 1): ?>
    <div class="text-center py-5">
        <i class="bi bi-clock-history" style="font-size:3rem; color:#333;"></i>
        <p class="text-muted mt-3">No workouts yet.<br>
            <a href="/forge/workout/" style="color:#FF5722;">Start your first session →</a>
        </p>
    </div>
    <?php else: ?>

    <?php
    $current_month = '';
    foreach ($workouts as $w):
        $month = date('F Y', strtotime($w['started_at']));
        if ($month !== $current_month):
            if ($current_month !== '') echo '</div>';
            $current_month = $month;
    ?>
        <div class="stat-label mb-2 mt-3"><?= $month ?></div>
        <div class="d-grid gap-2">
    <?php endif; ?>
        <a href="/forge/history/?id=<?= $w['id'] ?>" class="text-decoration-none">
            <div class="card">
                <div class="card-body py-3 px-3">
                    <div class="d-flex align-items-start justify-content-between">
                        <div class="min-w-0 me-2">
                            <div class="fw-black" style="font-size:0.95rem; letter-spacing:-0.3px; color:#e0e0e0;">
                                <?= htmlspecialchars($w['name'] ?: date('l', strtotime($w['started_at']))) ?>
                            </div>
                            <div class="text-muted" style="font-size:0.75rem; margin-top:2px;">
                                <?= date('D j M · ' . $time_fmt, strtotime($w['started_at'])) ?>
                                · <?= fmtDur($w['started_at'], $w['finished_at']) ?>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right text-muted" style="flex-shrink:0; margin-top:2px;"></i>
                    </div>
                    <?php if (($w['type'] ?? 'strength') === 'cardio'): ?>
                    <div class="d-flex gap-3 mt-2">
                        <div><span class="stat-label"><i class="bi bi-activity" style="color:#FF5722;"></i></span>
                            <span style="font-weight:900; font-size:0.8rem; color:#FF5722; letter-spacing:0.3px;">CARDIO</span></div>
                        <?php if ($w['total_distance_km']): ?>
                        <div><span class="stat-label">DIST</span> <span style="font-weight:900; font-size:0.9rem;"><?= round((float)$w['total_distance_km'], 2) ?> km</span></div>
                        <?php endif; ?>
                        <?php if ($w['total_elevation_m']): ?>
                        <div><span class="stat-label">ELEV</span> <span style="font-weight:900; font-size:0.9rem;"><?= round((float)$w['total_elevation_m']) ?>m</span></div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="d-flex gap-3 mt-2">
                        <div><span class="stat-label">EX</span> <span style="font-weight:900; font-size:0.9rem;"><?= $w['exercise_count'] ?></span></div>
                        <div><span class="stat-label">SETS</span> <span style="font-weight:900; font-size:0.9rem;"><?= $w['working_set_count'] ?></span></div>
                        <?php if ($w['total_volume_kg'] > 0): ?>
                        <div><span class="stat-label">VOL</span> <span style="font-weight:900; font-size:0.9rem; color:#FF5722;"><?= fmtVol((float)$w['total_volume_kg'], $unit) ?></span></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </a>
    <?php endforeach; ?>
    <?php if ($current_month !== '') echo '</div>'; ?>

    <?php if ($total_pages > 1): ?>
    <div class="d-flex justify-content-between align-items-center mt-4">
        <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?><?= $cycle_query ?>#workout-list" class="btn btn-forge-outline btn-sm px-4">← NEWER</a><?php else: ?><span></span><?php endif; ?>
        <span class="text-muted x-small"><?= $page ?> / <?= $total_pages ?></span>
        <?php if ($page < $total_pages): ?><a href="?page=<?= $page+1 ?><?= $cycle_query ?>#workout-list" class="btn btn-forge-outline btn-sm px-4">OLDER →</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<script>
const UNIT = <?= json_encode($unit) ?>;

Chart.defaults.color = '#888';
Chart.defaults.borderColor = '#333';
Chart.defaults.plugins.legend.display = false;
Chart.defaults.plugins.tooltip.backgroundColor = '#1e1e1e';
Chart.defaults.plugins.tooltip.borderColor = '#444';
Chart.defaults.plugins.tooltip.borderWidth = 1;
Chart.defaults.plugins.tooltip.titleColor = '#e0e0e0';
Chart.defaults.plugins.tooltip.bodyColor = '#e0e0e0';

const HISTORY_CYCLE_CONTEXT = <?= json_encode($selected_cycle ? (string)$selected_cycle['id'] : 'all') ?>;
async function papi(action) {
    const fd = new FormData(); fd.append('action', action);
    fd.append('cycle_context', HISTORY_CYCLE_CONTEXT);
    return fetch('/forge/core/progress_api.php', { method: 'POST', body: fd }).then(r => r.json());
}

async function initCharts() {
    const [weekly, freq] = await Promise.all([papi('get_weekly_volume'), papi('get_workout_frequency')]);
    renderWeekly(weekly.data || []);
    renderCalendar(freq.data || []);
}

function renderWeekly(data) {
    if (!data.length) { document.getElementById('chart-weekly').closest('.chart-container').style.display='none'; return; }
    new Chart(document.getElementById('chart-weekly'), {
        type: 'bar',
        data: {
            labels: data.map(d => d.week.replace(/^\d{4}-/, '')),
            datasets: [{
                data: data.map(d => parseFloat(d.volume_kg) || 0),
                backgroundColor: 'rgba(255,87,34,0.15)',
                borderColor: '#FF5722', borderWidth: 1, borderRadius: 3,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            scales: {
                x: { grid: { color: '#222' }, ticks: { color: '#666', font: { size: 10 } } },
                y: { grid: { color: '#222' }, ticks: { color: '#666', font: { size: 10 },
                    callback: v => v >= 1000 ? (v/1000).toFixed(0)+'k' : v } },
            },
        }
    });
}

function renderCalendar(data) {
    const counts = {};
    data.forEach(d => { counts[d.date] = parseInt(d.count) || 0; });

    const canvas = document.getElementById('heatmap-canvas');
    const container = canvas.parentElement;
    const availWidth = container.clientWidth - 24; // subtract padding

    if (!data.length) return;
    // Show the selected period in full. Long ranges collapse to one cell per
    // week, retaining the useful frequency signal without a five-year strip.
    const dates = data.map(d => new Date(d.date + 'T00:00:00')).sort((a, b) => a - b);
    const spanDays = (dates[dates.length - 1] - dates[0]) / 86400000;
    const monthlyMode = spanDays > 730;
    const weeklyMode = !monthlyMode && spanDays > 210;
    const start = new Date(dates[0]);
    const end = new Date(dates[dates.length - 1]);
    const dow = (start.getDay() + 6) % 7;
    start.setDate(start.getDate() - dow);
    if (monthlyMode) {
        const monthlyCounts = {};
        data.forEach(({date, count}) => {
            const key = date.slice(0, 7);
            monthlyCounts[key] = (monthlyCounts[key] || 0) + parseInt(count || 0);
        });
        Object.keys(counts).forEach(k => delete counts[k]); Object.assign(counts, monthlyCounts);
        start.setDate(1); end.setDate(1);
    } else if (weeklyMode) {
        const weeklyCounts = {};
        data.forEach(({date, count}) => {
            const d = new Date(date + 'T00:00:00');
            d.setDate(d.getDate() - ((d.getDay() + 6) % 7));
            const key = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
            weeklyCounts[key] = (weeklyCounts[key] || 0) + parseInt(count || 0);
        });
        Object.keys(counts).forEach(k => delete counts[k]); Object.assign(counts, weeklyCounts);
    }
    const totalDays = Math.ceil((end - start) / 86400000) + 1;
    const monthCount = (end.getFullYear() - start.getFullYear()) * 12 + end.getMonth() - start.getMonth() + 1;
    const COLS = monthlyMode ? 12 : Math.ceil(totalDays / 7);
    const ROWS = monthlyMode ? Math.ceil(monthCount / 12) : (weeklyMode ? 1 : 7), ML = (weeklyMode || monthlyMode) ? 0 : 14, MH = 16;

    // Fit cell size to container width
    const CELL = Math.max(weeklyMode ? 3 : 10, Math.floor((availWidth - ML) / COLS) - 2);
    const GAP  = Math.min(3, Math.floor(CELL * 0.15));

    canvas.width  = ML + COLS * (CELL + GAP);
    canvas.height = MH + ROWS * (CELL + GAP);
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    const maxCount = Math.max(1, ...Object.values(counts));
    const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const monthCols = {};

    function localDateKey(d) {
        return d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
    }

    for (let col = 0; col < COLS; col++) {
        for (let row = 0; row < ROWS; row++) {
            const d = new Date(start);
            if (monthlyMode) d.setMonth(start.getMonth() + row * 12 + col);
            else d.setDate(start.getDate() + col * 7 + (weeklyMode ? 0 : row));
            if (d > end) continue;

            if (row === 0) {
                const mk = d.getFullYear() + '-' + d.getMonth();
                if (!monthCols[mk]) monthCols[mk] = { col, month: d.getMonth() };
            }

            const key   = monthlyMode ? d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') : localDateKey(d);
            const count = counts[key] || 0;
            const alpha = count === 0 ? 0 : 0.2 + 0.8 * (count / maxCount);
            ctx.fillStyle = count === 0 ? '#1e1e1e' : `rgba(255,87,34,${alpha.toFixed(2)})`;
            ctx.beginPath();
            ctx.roundRect(ML + col * (CELL + GAP), MH + row * (CELL + GAP), CELL, CELL, 2);
            ctx.fill();
        }
    }

    ctx.fillStyle = '#555';
    ctx.font = '9px Inter, sans-serif';
    let lastX = -30;
    Object.values(monthCols).forEach(({ col, month }) => {
        const x = ML + col * (CELL + GAP);
        if (x - lastX >= 22) { ctx.fillText(MONTHS[month], x, 12); lastX = x; }
    });

    ctx.fillStyle = '#555';
    ctx.font = '9px Inter, sans-serif';
    ((weeklyMode || monthlyMode) ? [] : ['M','','W','','F','','']).forEach((lbl, row) => {
        if (lbl) ctx.fillText(lbl, 0, MH + row * (CELL + GAP) + CELL - 2);
    });
}

initCharts();
</script>

<?php } ?>
</body>
</html>
