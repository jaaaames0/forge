<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/db_helper.php';

$db   = get_db_connection();
$unit = 'kg';

$exercise_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

// ============================================================
// EXERCISE STATS VIEW
// ============================================================
if ($exercise_id) {
    $stmt = $db->prepare("SELECT * FROM exercises WHERE id = ?");
    $stmt->execute([$exercise_id]);
    $ex = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ex) { header("Location: /forge/exercises/"); exit; }

    $is_cardio = $ex['category'] === 'Cardio';

    if ($is_cardio) {
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT ws.workout_id) as session_count,
                   COUNT(ws.id) as set_count,
                   ROUND(SUM(COALESCE(ws.distance_km, 0)), 2) as total_distance_km,
                   SUM(COALESCE(ws.duration_sec, 0)) as total_duration_sec
            FROM workout_sets ws
            JOIN workouts w ON w.id = ws.workout_id
            WHERE ws.exercise_id = ? AND w.finished_at IS NOT NULL
        ");
        $stmt->execute([$exercise_id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        $pr_stmt = $db->prepare("
            SELECT record_type, value, duration_sec, distance_km, avg_hr, intensity, achieved_at
            FROM cardio_records
            WHERE exercise_id = ?
              AND id = (
                  SELECT id FROM cardio_records c2
                  WHERE c2.exercise_id = cardio_records.exercise_id
                    AND c2.record_type = cardio_records.record_type
                  ORDER BY c2.value DESC LIMIT 1
              )
            ORDER BY record_type
        ");
        $pr_stmt->execute([$exercise_id]);
        $cardio_prs = [];
        foreach ($pr_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $cardio_prs[$r['record_type']] = $r; }
        $pr = null;
    } else {
        $cardio_prs = [];
        $stmt = $db->prepare("SELECT * FROM personal_records WHERE exercise_id = ? ORDER BY estimated_1rm DESC LIMIT 1");
        $stmt->execute([$exercise_id]);
        $pr = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT ws.workout_id) as session_count,
                   COUNT(ws.id) as set_count,
                   MAX(ws.weight_kg * ws.reps) as best_set_volume
            FROM workout_sets ws
            JOIN workouts w ON w.id = ws.workout_id
            WHERE ws.exercise_id = ? AND ws.set_type = 'working' AND w.finished_at IS NOT NULL
              AND ws.weight_kg IS NOT NULL AND ws.reps IS NOT NULL
        ");
        $stmt->execute([$exercise_id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Last session sets for this exercise
        $last_wkt_stmt = $db->prepare("
            SELECT w.id, w.name, w.started_at
            FROM workouts w
            JOIN workout_sets ws ON ws.workout_id = w.id
            WHERE ws.exercise_id = ? AND w.finished_at IS NOT NULL
            ORDER BY w.finished_at DESC LIMIT 1
        ");
        $last_wkt_stmt->execute([$exercise_id]);
        $last_workout = $last_wkt_stmt->fetch(PDO::FETCH_ASSOC);

        $last_session_sets = [];
        $best_set_idx = null;
        if ($last_workout) {
            $ss = $db->prepare("
                SELECT ws.set_number, ws.set_type, ws.weight_kg, ws.reps, ws.rpe,
                       CASE WHEN ws.weight_kg > 0 AND ws.reps = 1 THEN ws.weight_kg
                            WHEN ws.weight_kg > 0 AND ws.reps > 1 AND ws.reps <= 30
                            THEN ROUND(ws.weight_kg * (1 + ws.reps / 30.0), 1)
                            ELSE NULL END as estimated_1rm
                FROM workout_sets ws
                WHERE ws.workout_id = ? AND ws.exercise_id = ?
                ORDER BY ws.set_number ASC
            ");
            $ss->execute([$last_workout['id'], $exercise_id]);
            $last_session_sets = $ss->fetchAll(PDO::FETCH_ASSOC);
            $best_e1rm = 0;
            foreach ($last_session_sets as $i => $s) {
                if ($s['set_type'] === 'working' && $s['estimated_1rm'] !== null && (float)$s['estimated_1rm'] > $best_e1rm) {
                    $best_e1rm = (float)$s['estimated_1rm'];
                    $best_set_idx = $i;
                }
            }
        }
    }

    $page_title   = $ex['name'];
    $page_context = 'exercises';
    $extra_head   = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';
    include __DIR__ . '/../core/page_head.php';
?>

<div class="container-fluid py-3 px-3" style="max-width:700px; margin:0 auto;">

    <a href="/forge/exercises/" class="d-inline-flex align-items-center gap-1 mb-3 text-decoration-none"
       style="color:#888; font-size:0.8rem; font-weight:700; text-transform:uppercase; letter-spacing:1px;">
        <i class="bi bi-chevron-left"></i> EXERCISES
    </a>

    <!-- Exercise header -->
    <div class="d-flex align-items-start justify-content-between mb-3">
        <div>
            <h2 class="fw-black mb-1" style="font-size:1.4rem; letter-spacing:-1px;">
                <?= htmlspecialchars($ex['name']) ?>
            </h2>
            <div class="d-flex flex-wrap gap-1 align-items-center">
                <?php if ($ex['muscle_primary']): ?>
                <span class="muscle-badge muscle-<?= strtolower(preg_replace('/[^a-zA-Z]/', '', $ex['muscle_primary'])) ?>">
                    <?= htmlspecialchars($ex['muscle_primary']) ?>
                </span>
                <?php endif; ?>
                <?php if ($ex['category']): ?>
                <span style="background:#222; color:#888; font-size:0.65rem; font-weight:700; padding:2px 6px; border-radius:3px; text-transform:uppercase; letter-spacing:0.5px;">
                    <?= htmlspecialchars($ex['category']) ?>
                </span>
                <?php endif; ?>
                <?php if ($ex['is_custom']): ?>
                <span style="background:#2a2000; color:#ff9800; font-size:0.6rem; font-weight:700; padding:2px 5px; border-radius:3px; text-transform:uppercase;">CUSTOM</span>
                <?php endif; ?>
            </div>
        </div>
        <button class="btn-icon" onclick="openEditModal()" type="button"><i class="bi bi-pencil" style="font-size:1rem;"></i></button>
    </div>

    <!-- Quick stats — 5 inline cards -->
    <div class="row g-1 mb-4">
        <div class="col text-center"><div class="card py-2 px-1" style="height:100%;"><div class="stat-label" style="font-size:0.55rem;">SESSIONS</div><div class="fw-black" style="font-size:1rem;"><?= (int)$stats['session_count'] ?></div></div></div>
        <?php if ($is_cardio): ?>
        <div class="col text-center"><div class="card py-2 px-1" style="height:100%;"><div class="stat-label" style="font-size:0.55rem;">TOTAL KM</div><div class="fw-black" style="font-size:1rem;"><?= $stats['total_distance_km'] ?: '—' ?></div></div></div>
        <div class="col text-center"><div class="card py-2 px-1" style="height:100%;"><div class="stat-label" style="font-size:0.55rem;">TOTAL TIME</div><div class="fw-black" style="font-size:1rem;"><?= $stats['total_duration_sec'] ? gmdate('H:i', (int)$stats['total_duration_sec']) : '—' ?></div></div></div>
        <?php else: ?>
        <div class="col text-center"><div class="card py-2 px-1" style="height:100%;"><div class="stat-label" style="font-size:0.55rem;">SETS</div><div class="fw-black" style="font-size:1rem;"><?= (int)$stats['set_count'] ?></div></div></div>
        <div class="col text-center"><div class="card py-2 px-1" style="height:100%;"><div class="stat-label" style="font-size:0.55rem;">BEST VOL</div><div class="fw-black" style="font-size:0.85rem;"><?= $stats['best_set_volume'] ? round($stats['best_set_volume']).' '.$unit : '—' ?></div></div></div>
        <div class="col text-center"><div class="card py-2 px-1" style="height:100%;"><div class="stat-label" style="font-size:0.55rem;">e1RM</div><div class="fw-black" style="font-size:0.85rem; color:#FF5722;"><?= $pr ? $pr['estimated_1rm'].' '.$unit : '—' ?></div></div></div>
        <div class="col text-center"><div class="card py-2 px-1" style="height:100%;"><div class="stat-label" style="font-size:0.55rem;">5RM</div><div class="fw-black" style="font-size:0.85rem;"><?= $pr ? round($pr['estimated_1rm'] * 6/7, 1).' '.$unit : '—' ?></div></div></div>
        <?php endif; ?>
    </div>

    <!-- Cardio records -->
    <?php if ($is_cardio && !empty($cardio_prs)): ?>
    <div class="row g-1 mb-4">
        <?php if (isset($cardio_prs['avg_speed'])): ?>
        <div class="col text-center"><div class="card py-2 px-1" style="height:100%; border-color:#FF5722;"><div class="stat-label" style="font-size:0.55rem;">BEST SPEED</div><div class="fw-black" style="font-size:1rem; color:#FF5722;"><?= round($cardio_prs['avg_speed']['value'], 2) ?> km/h</div></div></div>
        <?php endif; ?>
        <?php if (isset($cardio_prs['best_distance'])): ?>
        <div class="col text-center"><div class="card py-2 px-1" style="height:100%; border-color:#FF5722;"><div class="stat-label" style="font-size:0.55rem;">BEST DISTANCE</div><div class="fw-black" style="font-size:1rem; color:#FF5722;"><?= round($cardio_prs['best_distance']['value'], 2) ?> km</div></div></div>
        <?php endif; ?>
        <?php if (isset($cardio_prs['best_duration'])): ?>
        <div class="col text-center"><div class="card py-2 px-1" style="height:100%; border-color:#FF5722;"><div class="stat-label" style="font-size:0.55rem;">BEST DURATION</div><div class="fw-black" style="font-size:1rem; color:#FF5722;"><?= gmdate('H:i', (int)$cardio_prs['best_duration']['value']) ?></div></div></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Last session sets -->
    <?php if (!$is_cardio && !empty($last_session_sets)): ?>
    <?php
        $session_date = date('D j M', strtotime($last_workout['started_at']));
    ?>
    <div class="card mb-4" style="border-color:#333;">
        <div class="card-body py-2 px-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="stat-label">LAST SESSION <span class="text-muted fw-normal" style="font-size:0.65rem; text-transform:none; letter-spacing:0;"><?= $session_date ?></span></div>
                <a href="/forge/history/?exercise_id=<?= $exercise_id ?>" class="text-decoration-none" style="font-size:0.65rem; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:0.5px;">FULL HISTORY <i class="bi bi-arrow-right"></i></a>
            </div>
            <div style="display:grid; grid-template-columns:2rem 1fr 1fr 1fr 1fr; gap:2px 6px; align-items:center;">
                <div></div>
                <div class="stat-label" style="font-size:0.6rem;">TYPE</div>
                <div class="stat-label" style="font-size:0.6rem;">WEIGHT</div>
                <div class="stat-label" style="font-size:0.6rem;">REPS</div>
                <div class="stat-label" style="font-size:0.6rem;">e1RM</div>
                <?php foreach ($last_session_sets as $i => $s): ?>
                <?php $is_best = ($i === $best_set_idx); ?>
                <div style="font-size:0.7rem; color:<?= $is_best ? '#FF5722' : '#666' ?>;">
                    <?= $is_best ? '<i class="bi bi-trophy-fill"></i>' : '#'.(int)$s['set_number'] ?>
                </div>
                <div style="font-size:0.75rem; color:#888; text-transform:uppercase;"><?= htmlspecialchars($s['set_type']) ?></div>
                <div style="font-size:0.85rem; font-weight:<?= $is_best ? '900' : '400' ?>; color:<?= $is_best ? '#e0e0e0' : '#aaa' ?>;"><?= $s['weight_kg'] ? $s['weight_kg'].' '.$unit : '—' ?></div>
                <div style="font-size:0.85rem; font-weight:<?= $is_best ? '900' : '400' ?>; color:<?= $is_best ? '#e0e0e0' : '#aaa' ?>;"><?= $s['reps'] ?? '—' ?></div>
                <div style="font-size:0.85rem; color:<?= $is_best ? '#FF5722' : '#666' ?>; font-weight:<?= $is_best ? '700' : '400' ?>;"><?= $s['estimated_1rm'] ? $s['estimated_1rm'].' '.$unit : '—' ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- No data state -->
    <?php if (!$stats['session_count']): ?>
    <div class="text-center py-4 text-muted">
        <i class="bi bi-graph-up" style="font-size:2.5rem; color:#333; display:block; margin-bottom:12px;"></i>
        No sessions logged yet for this exercise.
    </div>
    <?php elseif ($is_cardio): ?>

    <!-- Cardio charts -->
    <div class="mb-4">
        <div class="stat-label mb-2">AVG SPEED OVER TIME (km/h)</div>
        <div class="chart-container" style="height:150px; padding:12px;"><canvas id="chart-speed"></canvas></div>
    </div>
    <div class="mb-4">
        <div class="stat-label mb-2">CARDIAC EFFICIENCY <span class="text-muted" style="font-size:0.62rem; font-weight:400;">(speed ÷ HR × 1000, higher = fitter)</span></div>
        <div class="chart-container" style="height:150px; padding:12px;"><canvas id="chart-efficiency"></canvas></div>
    </div>
    <div class="mb-4">
        <div class="stat-label mb-2">DISTANCE PER SESSION (km)</div>
        <div class="chart-container" style="height:150px; padding:12px;"><canvas id="chart-distance"></canvas></div>
    </div>

    <?php else: ?>

    <!-- Strength charts -->
    <div class="mb-4">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <div id="rm-chart-label" class="stat-label">e1RM OVER TIME</div>
            <div class="d-flex align-items-center gap-1" style="font-size:0.7rem; font-weight:700; color:#666; text-transform:uppercase;">
                <span>VIEW AT</span>
                <select id="rep-picker" class="form-select form-select-sm" style="width:auto; min-height:28px; padding:1px 6px; font-size:0.75rem; background:#1a1a1a; border-color:#333; color:#e0e0e0;" onchange="updateRmChart()">
                    <option value="0" selected>1RM</option>
                    <option value="1">1</option><option value="3">3</option><option value="5">5</option>
                    <option value="8">8</option><option value="10">10</option><option value="12">12</option>
                </select>
                <span>REPS</span>
            </div>
        </div>
        <div class="chart-container" style="height:150px; padding:12px;"><canvas id="chart-1rm"></canvas></div>
    </div>
    <div class="mb-4">
        <div class="stat-label mb-2">VOLUME PER SESSION</div>
        <div class="chart-container" style="height:140px; padding:12px;"><canvas id="chart-vol"></canvas></div>
    </div>

    <div id="hrr-section" style="display:none;">
        <div class="mb-4">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="stat-label"><i class="bi bi-heart-pulse me-1"></i>%HRR OVER TIME</div>
                <div id="hrr-range" style="font-family:monospace; font-size:0.7rem; color:#888;"></div>
            </div>
            <div class="chart-container" style="height:140px; padding:12px;"><canvas id="chart-hrr"></canvas></div>
        </div>
        <div id="hrrec-section" style="display:none;" class="mb-4">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="stat-label"><i class="bi bi-activity me-1"></i>HR RECOVERY <span class="text-muted" style="font-size:0.62rem; font-weight:400;">(bpm drop at 60s, higher = fitter)</span></div>
                <div id="hrrec-range" style="font-family:monospace; font-size:0.7rem; color:#888;"></div>
            </div>
            <div class="chart-container" style="height:140px; padding:12px;"><canvas id="chart-hrrec"></canvas></div>
        </div>
    </div>

    <?php endif; ?>

</div>

<!-- Edit Modal -->
<div class="modal fade" id="editExModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom:1px solid #333;">
                <h5 class="modal-title fw-black uppercase">EDIT EXERCISE</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3"><label class="stat-label d-block mb-1">NAME</label>
                    <input type="text" id="ex-name" class="form-control" value="<?= htmlspecialchars($ex['name']) ?>"></div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="stat-label d-block mb-1">CATEGORY</label>
                        <select id="ex-cat" class="form-select">
                            <?php foreach (['Barbell','Dumbbell','Machine','Cable','Bodyweight','Cardio','Other'] as $c): ?>
                            <option <?= $ex['category'] === $c ? 'selected' : '' ?>><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6"><label class="stat-label d-block mb-1">PRIMARY MUSCLE</label>
                        <select id="ex-muscle" class="form-select">
                            <option value="">— none —</option>
                            <?php foreach (['Chest','Back','Legs','Shoulders','Arms','Core','Glutes','Cardio'] as $m): ?>
                            <option <?= $ex['muscle_primary'] === $m ? 'selected' : '' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-3"><label class="stat-label d-block mb-1">SECONDARY MUSCLE</label>
                    <input type="text" id="ex-secondary" class="form-control" value="<?= htmlspecialchars($ex['muscle_secondary'] ?? '') ?>"></div>
                <div class="mb-3"><label class="stat-label d-block mb-1">REST / WARMUP CATEGORY</label>
                    <select id="ex-rest-cat" class="form-select">
                        <option value="">— none —</option>
                        <?php foreach ([
                            'compound_big4' => 'Big 4 Compound (squat, deadlift, bench, OHP)',
                            'compound'      => 'Compound (rows, pull-ups, lunges…)',
                            'bodyweight'    => 'Bodyweight (push-ups, dips, planks…)',
                            'isolation'     => 'Isolation (curls, flyes, extensions…)',
                        ] as $v => $label): ?>
                        <option value="<?= $v ?>" <?= ($ex['rest_category'] ?? '') === $v ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="text-muted mt-1" style="font-size:0.7rem;">Determines rest timer duration and warmup routine</div>
                </div>
                <div><label class="stat-label d-block mb-1">NOTES</label>
                    <textarea id="ex-notes" class="form-control" rows="2"><?= htmlspecialchars($ex['notes'] ?? '') ?></textarea></div>
            </div>
            <div class="modal-footer" style="border-top:1px solid #333;">
                <button type="button" class="btn" data-bs-dismiss="modal" style="background:#1e1e1e;border:1px solid #333;color:#888;">CANCEL</button>
                <button type="button" class="btn btn-forge flex-grow-1" onclick="saveEdit()">SAVE</button>
            </div>
        </div>
    </div>
</div>

<script>
function openEditModal() { new bootstrap.Modal(document.getElementById('editExModal')).show(); }
async function saveEdit() {
    const fd = new FormData();
    fd.append('action', 'update_exercise');
    fd.append('id', <?= $exercise_id ?>);
    fd.append('name', document.getElementById('ex-name').value.trim());
    fd.append('category', document.getElementById('ex-cat').value);
    fd.append('muscle_primary', document.getElementById('ex-muscle').value);
    fd.append('muscle_secondary', document.getElementById('ex-secondary').value);
    fd.append('rest_category', document.getElementById('ex-rest-cat').value);
    fd.append('notes', document.getElementById('ex-notes').value);
    const r = await fetch('/forge/core/api.php', { method: 'POST', body: fd }).then(r => r.json());
    if (r.status === 'success') location.reload();
    else alert(r.message);
}
</script>

<?php if ($stats['session_count']): ?>
<script>
const UNIT   = <?= json_encode($unit) ?>;
const EX_ID  = <?= $exercise_id ?>;
const IS_CARDIO = <?= $is_cardio ? 'true' : 'false' ?>;
const ACCENT = '#FF5722';
const DIM    = 'rgba(255,87,34,0.15)';

Chart.defaults.color = '#888';
Chart.defaults.borderColor = '#333';
Chart.defaults.plugins.legend.display = false;
Chart.defaults.plugins.tooltip.backgroundColor = '#1e1e1e';
Chart.defaults.plugins.tooltip.borderColor = '#444';
Chart.defaults.plugins.tooltip.borderWidth = 1;
Chart.defaults.plugins.tooltip.titleColor = '#e0e0e0';
Chart.defaults.plugins.tooltip.bodyColor = '#e0e0e0';

const axisOpts = {
    grid:  { color: '#222' },
    ticks: { color: '#666', font: { size: 11 }, maxTicksLimit: 6 },
};
const baseOpts = { responsive: true, maintainAspectRatio: false, animation: false };

let charts = {};

function mkChart(id, type, labels, datasets, yCallback) {
    const el = document.getElementById(id);
    if (!el || !labels.length) return;
    return new Chart(el, {
        type,
        data: { labels, datasets },
        options: { ...baseOpts, scales: {
            x: { ...axisOpts },
            y: { ...axisOpts, ticks: { ...axisOpts.ticks, callback: yCallback || (v => v) } },
        }},
    });
}

function updateRmChart() {
    const history = window._oneRmHistory;
    if (!history || !history.length) return;
    const reps = parseInt(document.getElementById('rep-picker')?.value ?? '0');
    const label = document.getElementById('rm-chart-label');

    // Scale e1RM to target reps via Epley inverse: weight = e1rm / (1 + reps/30)
    const mult = reps > 0 ? 1 / (1 + reps / 30) : 1;
    const vals = history.map(r => {
        const v = parseFloat(r.estimated_1rm);
        return isNaN(v) ? null : Math.round(v * mult * 10) / 10;
    });

    if (label) label.textContent = reps > 0 ? `WEIGHT AT ${reps} REPS OVER TIME` : 'e1RM OVER TIME';

    if (charts.oneRm) { charts.oneRm.destroy(); delete charts.oneRm; }
    charts.oneRm = mkChart('chart-1rm', 'line',
        history.map(r => r.date),
        [{ data: vals, borderColor: ACCENT, backgroundColor: DIM, fill: true, tension: 0.3,
           pointRadius: 3, pointBackgroundColor: ACCENT, spanGaps: true }],
        v => v + ' ' + UNIT);
}

async function loadCharts() {
    Object.values(charts).forEach(c => c.destroy());
    charts = {};

    if (IS_CARDIO) {
        const fd = new FormData();
        fd.append('action', 'get_cardio_history');
        fd.append('exercise_id', EX_ID);
        const r = await fetch('/forge/core/progress_api.php', { method: 'POST', body: fd }).then(r => r.json());
        if (r.status !== 'success') return;
        const rows = r.data;
        const labels = rows.map(r => r.date);

        const speedData = rows.map(r => r.avg_speed_kmh ? parseFloat(r.avg_speed_kmh) : null);
        if (speedData.some(v => v !== null)) {
            charts.speed = mkChart('chart-speed', 'line', labels,
                [{ data: speedData, borderColor: ACCENT, backgroundColor: DIM, fill: true, tension: 0.3,
                   pointRadius: 3, pointBackgroundColor: ACCENT, spanGaps: true }],
                v => v.toFixed(1) + ' km/h');
        }

        const effData = rows.map(r => r.cardiac_efficiency ? parseFloat(r.cardiac_efficiency) : null);
        if (effData.some(v => v !== null)) {
            charts.eff = mkChart('chart-efficiency', 'line', labels,
                [{ data: effData, borderColor: '#2196f3', backgroundColor: 'rgba(33,150,243,0.1)', fill: true, tension: 0.3,
                   pointRadius: 3, pointBackgroundColor: '#2196f3', spanGaps: true }],
                v => v.toFixed(1));
        }

        const distData = rows.map(r => r.distance_km ? parseFloat(r.distance_km) : null);
        if (distData.some(v => v !== null)) {
            charts.dist = mkChart('chart-distance', 'bar', labels,
                [{ data: distData, backgroundColor: 'rgba(76,175,80,0.2)', borderColor: '#4caf50', borderWidth: 1, borderRadius: 3 }],
                v => v.toFixed(1) + ' km');
        }

    } else {
        const fd = new FormData();
        fd.append('action', 'get_exercise_progress');
        fd.append('exercise_id', EX_ID);
        const r = await fetch('/forge/core/progress_api.php', { method: 'POST', body: fd }).then(r => r.json());
        if (r.status !== 'success') return;
        const d = r.data;

        // Store raw e1RM data for client-side scaling
        window._oneRmHistory = d.one_rm_history;

        if (d.one_rm_history.length) {
            updateRmChart();
        }

        if (d.volume_history.length) {
            charts.vol = mkChart('chart-vol', 'bar',
                d.volume_history.map(r => r.date),
                [{ data: d.volume_history.map(r => parseFloat(r.volume_kg) || 0),
                   backgroundColor: DIM, borderColor: ACCENT, borderWidth: 1, borderRadius: 3 }],
                v => v >= 1000 ? (v/1000).toFixed(0)+'k' : v);
        }

        // %HRR + HRRec charts
        const hrrFd = new FormData();
        hrrFd.append('action', 'get_exercise_hrr');
        hrrFd.append('exercise_id', EX_ID);
        const hrrRes = await fetch('/forge/core/progress_api.php', { method: 'POST', body: hrrFd }).then(r => r.json());
        if (hrrRes.status === 'success' && hrrRes.data.length >= 2) {
            const hrrData = hrrRes.data;
            document.getElementById('hrr-section').style.display = '';

            const hrrVals = hrrData.map(r => parseFloat(r.avg_hrr_pct));
            const hrrMin  = Math.min(...hrrVals).toFixed(0);
            const hrrMax  = Math.max(...hrrVals).toFixed(0);
            document.getElementById('hrr-range').textContent = hrrMin + '% → ' + hrrMax + '%';

            charts.hrr = mkChart('chart-hrr', 'line',
                hrrData.map(r => r.date),
                [{ data: hrrVals, borderColor: '#ef5350', backgroundColor: 'rgba(239,83,80,0.1)',
                   fill: true, tension: 0.3, pointRadius: 3, pointBackgroundColor: '#ef5350', spanGaps: true }],
                v => v.toFixed(0) + '%');

            // HRRec chart — only when enough sessions have recovery data
            const hrrec = hrrData.filter(r => r.hrrec !== null);
            if (hrrec.length >= 2) {
                document.getElementById('hrrec-section').style.display = '';
                const rrecVals = hrrec.map(r => parseFloat(r.hrrec));
                const rrecMin  = Math.min(...rrecVals).toFixed(0);
                const rrecMax  = Math.max(...rrecVals).toFixed(0);
                document.getElementById('hrrec-range').textContent = rrecMin + ' → ' + rrecMax + ' bpm';

                charts.hrrec = mkChart('chart-hrrec', 'line',
                    hrrec.map(r => r.date),
                    [{ data: rrecVals, borderColor: '#ab47bc', backgroundColor: 'rgba(171,71,188,0.1)',
                       fill: true, tension: 0.3, pointRadius: 3, pointBackgroundColor: '#ab47bc', spanGaps: true }],
                    v => v.toFixed(0) + ' bpm');
            }
        }
    }
}

loadCharts();
</script>
<?php endif; ?>

<?php

// ============================================================
// LIST VIEW
// ============================================================
} else {

    // Exercises with logs, ordered by most recently used
    $recently_used = $db->query("
        SELECT e.*, MAX(w.started_at) as last_used, COUNT(ws.id) as sets_count
        FROM exercises e
        JOIN workout_sets ws ON ws.exercise_id = e.id
        JOIN workouts w ON w.id = ws.workout_id
        WHERE w.finished_at IS NOT NULL
        GROUP BY e.id
        ORDER BY last_used DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $recent_ids = array_column($recently_used, 'id');

    // All exercises
    $all_exercises = $db->query("SELECT * FROM exercises ORDER BY muscle_primary, name")->fetchAll(PDO::FETCH_ASSOC);

    // Groups for rendering
    $grouped = [];
    foreach ($all_exercises as $e) { $grouped[$e['muscle_primary'] ?? 'Other'][] = $e; }

    $page_title   = 'Exercises';
    $page_context = 'exercises';
    include __DIR__ . '/../core/page_head.php';
?>

<div class="container-fluid py-3 px-3" style="max-width:700px; margin:0 auto;">

    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="fw-black mb-0" style="font-size:1.6rem; letter-spacing:-1.5px;">
            EXERCISES<span style="color:#FF5722;">_</span>
        </h1>
        <div class="d-flex gap-2">
            <button class="btn btn-forge btn-sm px-3" data-bs-toggle="modal" data-bs-target="#addExModal">
                <i class="bi bi-plus-lg me-1"></i>CUSTOM
            </button>
        </div>
    </div>

    <!-- Search -->
    <div class="mb-3">
        <input type="search" id="ex-search" class="form-control"
               placeholder="Search exercises…" oninput="applyFilters()"
               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
    </div>

    <!-- Recently used -->
    <?php if (!empty($recently_used)): ?>
    <div id="section-recent">
        <div class="stat-label mb-2">RECENTLY USED</div>
        <div class="d-grid gap-1 mb-3">
        <?php foreach ($recently_used as $e): ?>
        <a href="/forge/exercises/?id=<?= $e['id'] ?>" class="text-decoration-none exercise-row"
           data-name="<?= htmlspecialchars(strtolower($e['name'])) ?>"
           data-muscle="<?= htmlspecialchars(strtolower($e['muscle_primary'] ?? '')) ?>"
           data-category="<?= htmlspecialchars(strtolower($e['category'] ?? '')) ?>">
            <div class="d-flex align-items-center justify-content-between py-2 px-3"
                 style="background:#1a1a1a; border-radius:6px; border:1px solid #222; min-height:52px;">
                <div class="min-w-0">
                    <div style="font-weight:700; font-size:0.9rem; color:#e0e0e0;"><?= htmlspecialchars($e['name']) ?></div>
                    <div style="font-size:0.7rem; color:#666;">
                        <?= $e['sets_count'] ?> sets &middot; <?= htmlspecialchars($e['category'] ?? '') ?>
                        <?php if ($e['muscle_primary']): ?>&middot; <?= htmlspecialchars($e['muscle_primary']) ?><?php endif; ?>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <i class="bi bi-chevron-right" style="color:#444; font-size:0.8rem;"></i>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- All exercises grouped by muscle (accordion) -->
    <div id="section-all">
    <?php foreach ($grouped as $muscle => $exList):
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $muscle));
    ?>
    <div class="muscle-group mb-1" data-muscle="<?= htmlspecialchars(strtolower($muscle)) ?>">
        <button class="accordion-hdr w-100 d-flex align-items-center justify-content-between"
                data-bs-toggle="collapse" data-bs-target="#mg-<?= $slug ?>"
                aria-expanded="false" type="button">
            <span class="d-flex align-items-center gap-2">
                <span><?= htmlspecialchars($muscle) ?></span>
                <span class="mg-count"><?= count($exList) ?></span>
            </span>
            <i class="bi bi-chevron-down accordion-chevron"></i>
        </button>
        <div class="collapse" id="mg-<?= $slug ?>">
            <div class="d-grid gap-1 pt-1 pb-2">
            <?php foreach ($exList as $e): ?>
            <a href="/forge/exercises/?id=<?= $e['id'] ?>" class="text-decoration-none exercise-row"
               data-name="<?= htmlspecialchars(strtolower($e['name'])) ?>"
               data-muscle="<?= htmlspecialchars(strtolower($e['muscle_primary'] ?? '')) ?>"
               data-category="<?= htmlspecialchars(strtolower($e['category'] ?? '')) ?>">
                <div class="d-flex align-items-center justify-content-between py-2 px-3"
                     style="background:#1a1a1a; border-radius:6px; border:1px solid #222; min-height:52px;">
                    <div class="min-w-0">
                        <div style="font-weight:700; font-size:0.9rem; color:#e0e0e0;">
                            <?= htmlspecialchars($e['name']) ?>
                            <?php if ($e['is_custom']): ?>
                            <span style="background:#2a2000; color:#ff9800; font-size:0.6rem; font-weight:700; padding:1px 5px; border-radius:3px; text-transform:uppercase; vertical-align:middle; margin-left:4px;">CUSTOM</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:0.72rem; color:#666;"><?= htmlspecialchars($e['category'] ?? '') ?><?= $e['muscle_secondary'] ? ' · ' . htmlspecialchars($e['muscle_secondary']) : '' ?></div>
                    </div>
                    <div class="d-flex align-items-center gap-1">
                        <?php if ($e['is_custom']): ?>
                        <button class="btn-icon" onclick="event.preventDefault(); deleteExercise(<?= $e['id'] ?>, this)" type="button">
                            <i class="bi bi-trash" style="font-size:0.85rem; color:#555;"></i>
                        </button>
                        <?php endif; ?>
                        <i class="bi bi-chevron-right" style="color:#444; font-size:0.8rem;"></i>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <div id="no-results" class="text-muted text-center py-4 d-none">No exercises match your search.</div>

</div>

<!-- Add/Edit Exercise Modal -->
<div class="modal fade" id="addExModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom:1px solid #333;">
                <h5 class="modal-title fw-black uppercase">ADD CUSTOM EXERCISE</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="modal-ex-id">
                <div class="mb-3"><label class="stat-label d-block mb-1">NAME</label>
                    <input type="text" id="modal-ex-name" class="form-control" placeholder="e.g. Bulgarian Split Squat" autocomplete="off"></div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="stat-label d-block mb-1">CATEGORY</label>
                        <select id="modal-ex-cat" class="form-select">
                            <option>Barbell</option><option>Dumbbell</option><option>Machine</option>
                            <option>Cable</option><option>Bodyweight</option><option>Cardio</option><option>Other</option>
                        </select>
                    </div>
                    <div class="col-6"><label class="stat-label d-block mb-1">PRIMARY MUSCLE</label>
                        <select id="modal-ex-muscle" class="form-select">
                            <option value="">— none —</option>
                            <option>Chest</option><option>Back</option><option>Legs</option>
                            <option>Shoulders</option><option>Arms</option><option>Core</option>
                            <option>Glutes</option><option>Cardio</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3"><label class="stat-label d-block mb-1">SECONDARY MUSCLE</label>
                    <input type="text" id="modal-ex-sec" class="form-control" placeholder="Optional"></div>
                <div><label class="stat-label d-block mb-1">NOTES</label>
                    <textarea id="modal-ex-notes" class="form-control" rows="2" placeholder="Optional"></textarea></div>
            </div>
            <div class="modal-footer" style="border-top:1px solid #333;">
                <button type="button" class="btn" data-bs-dismiss="modal" style="background:#1e1e1e;border:1px solid #333;color:#888;">CANCEL</button>
                <button type="button" class="btn btn-forge flex-grow-1" onclick="saveExercise()">SAVE</button>
            </div>
        </div>
    </div>
</div>

<style>
.accordion-hdr {
    background: transparent;
    border: none;
    border-bottom: 1px solid #222;
    color: #888;
    font-size: 0.68rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 10px 2px;
    min-height: 40px;
    cursor: pointer;
}
.accordion-hdr:hover { color: #bbb; }
.accordion-hdr[aria-expanded="true"] { color: #e0e0e0; border-bottom-color: transparent; }
.accordion-hdr[aria-expanded="true"] .accordion-chevron { transform: rotate(180deg); }
.accordion-chevron { transition: transform 0.2s; font-size: 0.75rem; color: #444; }
.mg-count { background: #222; color: #666; font-size: 0.6rem; padding: 1px 6px; border-radius: 10px; font-weight: 700; }
</style>

<script>
function applyFilters() {
    const q = document.getElementById('ex-search').value.toLowerCase().trim();
    let anyVisible = false;

    document.querySelectorAll('.exercise-row').forEach(row => {
        const show = !q || row.dataset.name.includes(q) || row.dataset.muscle.includes(q) || row.dataset.category.includes(q);
        row.style.display = show ? '' : 'none';
        if (show) anyVisible = true;
    });

    // Accordion sections: expand those with matches when searching, collapse all when cleared
    document.querySelectorAll('.muscle-group').forEach(grp => {
        const visibleRows = [...grp.querySelectorAll('.exercise-row')].filter(r => r.style.display !== 'none');
        const collapseEl  = grp.querySelector('.collapse');
        const headerBtn   = grp.querySelector('.accordion-hdr');

        // Bypass Bootstrap's animated collapse during search — direct class manipulation
        // avoids conflicts between display:none and CSS transitions
        if (q) {
            if (visibleRows.length > 0) {
                grp.style.display = '';
                collapseEl.classList.add('show');
                headerBtn.setAttribute('aria-expanded', 'true');
            } else {
                grp.style.display = 'none';
                collapseEl.classList.remove('show');
                headerBtn.setAttribute('aria-expanded', 'false');
            }
        } else {
            grp.style.display = '';
            collapseEl.classList.remove('show');
            headerBtn.setAttribute('aria-expanded', 'false');
        }
    });

    // Recently used section
    const recentSec = document.getElementById('section-recent');
    if (recentSec) {
        const hasVisible = [...recentSec.querySelectorAll('.exercise-row')].some(r => r.style.display !== 'none');
        recentSec.style.display = hasVisible ? '' : 'none';
    }

    document.getElementById('no-results').classList.toggle('d-none', anyVisible);
}

// Sync aria-expanded with Bootstrap collapse events
document.querySelectorAll('.muscle-group .collapse').forEach(el => {
    el.addEventListener('show.bs.collapse', () => {
        el.closest('.muscle-group').querySelector('.accordion-hdr').setAttribute('aria-expanded', 'true');
    });
    el.addEventListener('hide.bs.collapse', () => {
        el.closest('.muscle-group').querySelector('.accordion-hdr').setAttribute('aria-expanded', 'false');
    });
});

// Add / Edit exercise
async function apiCall(action, data) {
    const fd = new FormData(); fd.append('action', action);
    for (const [k,v] of Object.entries(data)) if (v !== null && v !== undefined) fd.append(k, v);
    return fetch('/forge/core/api.php', { method: 'POST', body: fd }).then(r => r.json());
}

document.getElementById('addExModal').addEventListener('hidden.bs.modal', () => {
    ['modal-ex-id','modal-ex-name','modal-ex-sec','modal-ex-notes'].forEach(id => document.getElementById(id).value = '');
});

async function saveExercise() {
    const name = document.getElementById('modal-ex-name').value.trim();
    if (!name) { document.getElementById('modal-ex-name').focus(); return; }
    const r = await apiCall('add_exercise', {
        name,
        category: document.getElementById('modal-ex-cat').value,
        muscle_primary: document.getElementById('modal-ex-muscle').value,
        muscle_secondary: document.getElementById('modal-ex-sec').value,
        notes: document.getElementById('modal-ex-notes').value,
    });
    if (r.status === 'success') location.reload();
    else alert(r.message);
}

async function deleteExercise(id, btn) {
    if (!confirm('Delete this custom exercise?')) return;
    const r = await apiCall('delete_exercise', { id });
    if (r.status === 'success') {
        const rows = document.querySelectorAll(`.exercise-row[href="/forge/exercises/?id=${id}"]`);
        rows.forEach(r => r.remove());
    } else alert(r.message);
}
</script>

<?php } ?>
</body>
</html>
