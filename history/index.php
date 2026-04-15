<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/db_helper.php';

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
        SELECT w.id, w.name, w.started_at, w.finished_at
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
    }
    unset($s);

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
    <?php foreach ($sessions as $s):
        $has_working = array_filter($s['sets'], fn($r) => $r['set_type'] === 'working' && $r['weight_kg'] > 0 && $r['reps']);
        $best_1rm = 0; $best_set = null;
        foreach ($s['sets'] as $r) {
            if ($r['set_type'] === 'working' && $r['estimated_1rm'] !== null && (float)$r['estimated_1rm'] > $best_1rm) {
                $best_1rm = (float)$r['estimated_1rm'];
                $best_set = $r;
            }
        }
    ?>
    <div class="card mb-2">
        <div class="card-body py-2 px-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <div class="fw-black" style="font-size:0.85rem; color:#e0e0e0;"><?= date('D j M Y', strtotime($s['started_at'])) ?></div>
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
} else {
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;

    $total_sessions = (int)$db->query("SELECT COUNT(*) FROM workouts WHERE finished_at IS NOT NULL")->fetchColumn();
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
        WHERE w.finished_at IS NOT NULL
        GROUP BY w.id
        ORDER BY w.started_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$limit, $offset]);
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

    <!-- Frequency calendar -->
    <div class="mb-3">
        <div class="stat-label mb-2">WORKOUT CALENDAR (90 DAYS)</div>
        <div class="chart-container" style="padding:12px;">
            <canvas id="heatmap-canvas"></canvas>
        </div>
    </div>

    <!-- Weekly volume chart -->
    <div class="mb-4">
        <div class="stat-label mb-2">WEEKLY VOLUME (12 WEEKS)</div>
        <div class="chart-container" style="padding:12px; height:150px;">
            <canvas id="chart-weekly"></canvas>
        </div>
    </div>

    <!-- Workout list -->
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
        <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>" class="btn btn-forge-outline btn-sm px-4">← NEWER</a><?php else: ?><span></span><?php endif; ?>
        <span class="text-muted x-small"><?= $page ?> / <?= $total_pages ?></span>
        <?php if ($page < $total_pages): ?><a href="?page=<?= $page+1 ?>" class="btn btn-forge-outline btn-sm px-4">OLDER →</a><?php endif; ?>
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

async function papi(action) {
    const fd = new FormData(); fd.append('action', action);
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

    // 90 days, aligned to Monday
    const today = new Date();
    const start = new Date(today);
    start.setDate(today.getDate() - 89);
    const dow = (start.getDay() + 6) % 7;
    start.setDate(start.getDate() - dow);

    const totalDays = Math.ceil((today - start) / 86400000) + 1;
    const COLS = Math.ceil(totalDays / 7);
    const ROWS = 7, ML = 14, MH = 16;

    // Fit cell size to container width
    const CELL = Math.max(10, Math.floor((availWidth - ML) / COLS) - 2);
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
            d.setDate(start.getDate() + col * 7 + row);
            if (d > today) continue;

            if (row === 0) {
                const mk = d.getFullYear() + '-' + d.getMonth();
                if (!monthCols[mk]) monthCols[mk] = { col, month: d.getMonth() };
            }

            const key   = localDateKey(d);
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
    ['M','','W','','F','',''].forEach((lbl, row) => {
        if (lbl) ctx.fillText(lbl, 0, MH + row * (CELL + GAP) + CELL - 2);
    });
}

initCharts();
</script>

<?php } ?>
</body>
</html>
