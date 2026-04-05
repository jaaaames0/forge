<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/db_helper.php';

$db       = get_db_connection();
$unit     = 'kg';
$time_fmt = 'H:i';

// ── ACTIVE WORKOUT ─────────────────────────────────────────────────────────
$active = $db->query("SELECT * FROM workouts WHERE finished_at IS NULL ORDER BY started_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// ── NEXT TEMPLATE ──────────────────────────────────────────────────────────
$next_template = $db->query("
    SELECT t.id, t.name,
           COUNT(DISTINCT te.id)    as exercise_count,
           COUNT(DISTINCT w.id)     as use_count,
           MAX(w.finished_at)       as last_used
    FROM templates t
    LEFT JOIN template_exercises te ON te.template_id = t.id
    LEFT JOIN workouts w ON w.template_id = t.id AND w.finished_at IS NOT NULL
    GROUP BY t.id
    ORDER BY COALESCE(MAX(w.finished_at), '1970-01-01') ASC, use_count DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// ── LAST SESSION ───────────────────────────────────────────────────────────
$last_session = $db->query("
    SELECT w.*,
           SUM(CASE WHEN ws.set_type='working' AND ws.weight_kg IS NOT NULL AND ws.reps IS NOT NULL
                    THEN ws.weight_kg * ws.reps ELSE 0 END) as total_volume_kg
    FROM workouts w
    LEFT JOIN workout_sets ws ON ws.workout_id = w.id
    WHERE w.finished_at IS NOT NULL
    GROUP BY w.id
    ORDER BY w.finished_at DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

$last_session_exercises = [];
if ($last_session) {
    $stmt = $db->prepare("
        SELECT e.id as exercise_id, e.name,
               COUNT(CASE WHEN ws.set_type='working' THEN 1 END) as working_sets
        FROM workout_sets ws
        JOIN exercises e ON e.id = ws.exercise_id
        WHERE ws.workout_id = ?
        GROUP BY ws.exercise_id ORDER BY MIN(ws.id)
    ");
    $stmt->execute([$last_session['id']]);
    $last_session_exercises = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── NEXT MOVES ─────────────────────────────────────────────────────────────
$next_moves = [];
if ($next_template && !$active) {
    $te_stmt = $db->prepare("
        SELECT te.*, e.name as exercise_name, e.category, e.bw_factor
        FROM template_exercises te
        JOIN exercises e ON e.id = te.exercise_id
        WHERE te.template_id = ?
          AND te.progression_scheme IS NOT NULL
          AND (te.ab_group IS NULL OR te.ab_group = (
              SELECT CASE WHEN t.last_ab_variant = 'A' THEN 'B' ELSE 'A' END
              FROM templates t WHERE t.id = te.template_id
          ))
        ORDER BY te.order_num
    ");
    $te_stmt->execute([$next_template['id']]);
    foreach ($te_stmt->fetchAll(PDO::FETCH_ASSOC) as $te) {
        $sug = compute_progression_suggestion($db, $te);
        if ($sug && $sug['increase']) {
            $next_moves[] = ['exercise_id' => $te['exercise_id'], 'name' => $te['exercise_name'], 'suggestion' => $sug['suggestion']];
        }
    }
}

// ── THIS WEEK vs LAST WEEK ─────────────────────────────────────────────────
$week_start      = date('Y-m-d', strtotime('monday this week'));
$last_week_start = date('Y-m-d', strtotime('monday last week'));
$last_week_end   = date('Y-m-d', strtotime('sunday last week'));

$week_stmt = $db->prepare("
    SELECT COUNT(DISTINCT w.id) as sessions,
           COALESCE(SUM(CASE WHEN ws.set_type='working' AND ws.weight_kg IS NOT NULL AND ws.reps IS NOT NULL
                             THEN ws.weight_kg * ws.reps ELSE 0 END), 0) as volume_kg
    FROM workouts w
    LEFT JOIN workout_sets ws ON ws.workout_id = w.id
    WHERE w.finished_at IS NOT NULL AND DATE(w.started_at) >= ? AND DATE(w.started_at) <= ?
");
$week_stmt->execute([$week_start, date('Y-m-d')]);
$this_week = $week_stmt->fetch(PDO::FETCH_ASSOC);
$week_stmt->execute([$last_week_start, $last_week_end]);
$last_week = $week_stmt->fetch(PDO::FETCH_ASSOC);

function vol_delta(float $cur, float $prev): ?string {
    if ($prev <= 0) return null;
    $pct = round(($cur - $prev) / $prev * 100);
    return ($pct >= 0 ? '+' : '') . $pct . '%';
}
$vol_delta     = vol_delta((float)$this_week['volume_kg'], (float)$last_week['volume_kg']);
$session_delta = (int)$this_week['sessions'] - (int)$last_week['sessions'];

// ── LATEST STRENGTH PR ─────────────────────────────────────────────────────
$latest_str_pr = $db->query("
    SELECT pr.exercise_id, pr.estimated_1rm, pr.achieved_at, e.name as exercise_name
    FROM personal_records pr
    JOIN exercises e ON e.id = pr.exercise_id
    ORDER BY pr.achieved_at DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC) ?: null;

// ── LATEST CARDIO PR ───────────────────────────────────────────────────────
$latest_cardio_pr = null;
try {
    $latest_cardio_pr = $db->query("
        SELECT cr.exercise_id, cr.value, cr.record_type, cr.achieved_at, e.name as exercise_name
        FROM cardio_records cr
        JOIN exercises e ON e.id = cr.exercise_id
        ORDER BY cr.achieved_at DESC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {}

// ── BODYWEIGHT ─────────────────────────────────────────────────────────────
$bw_rows = $db->query("
    SELECT bodyweight_kg FROM workouts
    WHERE bodyweight_kg IS NOT NULL AND bodyweight_kg > 0
    ORDER BY started_at DESC LIMIT 2
")->fetchAll(PDO::FETCH_COLUMN);
$bw_current = !empty($bw_rows) ? (float)$bw_rows[0] : null;
$bw_delta   = count($bw_rows) >= 2 ? round((float)$bw_rows[0] - (float)$bw_rows[1], 1) : null;

// ── STREAK (consecutive weeks with ≥1 completed workout) ──────────────────
$workout_dates = $db->query("
    SELECT DISTINCT DATE(started_at) as d
    FROM workouts WHERE finished_at IS NOT NULL
    ORDER BY d DESC
")->fetchAll(PDO::FETCH_COLUMN);

$streak = 0;
if (!empty($workout_dates)) {
    $tz = new DateTimeZone('Australia/Sydney');
    // Build set of week-start Mondays
    $week_starts = [];
    foreach ($workout_dates as $d) {
        $dt  = new DateTime($d, $tz);
        $dow = (int)$dt->format('N'); // 1=Mon
        $mon = (clone $dt)->modify('-' . ($dow - 1) . ' days');
        $week_starts[$mon->format('Y-m-d')] = true;
    }
    krsort($week_starts);
    $now = new DateTime('now', $tz);
    $dow_now     = (int)$now->format('N');
    $this_monday = (clone $now)->modify('-' . ($dow_now - 1) . ' days');
    $expected    = $this_monday->format('Y-m-d');
    foreach (array_keys($week_starts) as $w) {
        if ($w === $expected) {
            $streak++;
            $expected = date('Y-m-d', strtotime($expected . ' -7 days'));
        } else {
            break;
        }
    }
}

// ── HELPERS ────────────────────────────────────────────────────────────────
function fmtDuration(string $start, string $end): string {
    $d = max(0, strtotime($end) - strtotime($start));
    $h = floor($d / 3600); $m = floor(($d % 3600) / 60);
    return $h ? "{$h}h {$m}m" : "{$m}m";
}
function fmtVolume(float $v, string $unit): string {
    if ($v >= 1000) return number_format($v / 1000, 1) . 'k ' . $unit;
    return number_format($v, 0) . ' ' . $unit;
}
function fmtAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 86400)    return 'today';
    if ($diff < 172800)   return 'yesterday';
    if ($diff < 604800)   return (int)($diff / 86400) . 'd ago';
    if ($diff < 2592000)  return (int)($diff / 604800) . 'w ago';
    return (int)($diff / 2592000) . 'mo ago';
}
function fmtCardioVal(string $type, float $val): string {
    return match($type) {
        'avg_speed'          => round($val, 1) . ' km/h',
        'best_distance'      => round($val, 2) . ' km',
        'cardiac_efficiency' => round($val, 1),
        default              => round($val, 1),
    };
}
function fmtCardioLabel(string $type): string {
    return match($type) {
        'avg_speed'          => 'BEST SPEED',
        'best_distance'      => 'BEST DIST',
        'cardiac_efficiency' => 'EFFICIENCY',
        default              => strtoupper($type),
    };
}

$page_title   = 'Dashboard';
$page_context = 'home';
include __DIR__ . '/core/page_head.php';
?>

<div class="container-fluid py-3 px-3" style="max-width:700px; margin:0 auto;">

    <!-- Date + heading -->
    <div class="mb-3">
        <div class="stat-label"><?= date('l, j F Y') ?></div>
        <h1 class="fw-black mb-0" style="font-size:2rem; letter-spacing:-2px;">FORGE<span style="color:#FF5722;">_</span></h1>
    </div>

    <!-- ── WORKOUT CTA ───────────────────────────────────────────────────── -->
    <?php if ($active): ?>
    <a href="/forge/workout/" class="d-block text-decoration-none mb-3">
        <div style="background:rgba(255,87,34,0.1); border:2px solid #FF5722; border-radius:8px; padding:16px;">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label" style="color:#FF5722;">WORKOUT IN PROGRESS</div>
                    <div class="fw-black" style="font-size:1.1rem; color:#e0e0e0;">
                        <?= htmlspecialchars($active['name'] ?: date('g:ia', strtotime($active['started_at']))) ?>
                    </div>
                    <div class="text-muted" style="font-size:0.78rem;">Started <?= date($time_fmt, strtotime($active['started_at'])) ?></div>
                </div>
                <i class="bi bi-arrow-right-circle-fill" style="font-size:2rem; color:#FF5722;"></i>
            </div>
        </div>
    </a>
    <?php elseif ($next_template): ?>
    <div class="mb-3">
        <form method="POST" action="/forge/workout/">
            <input type="hidden" name="action" value="start_workout">
            <input type="hidden" name="template_id" value="<?= $next_template['id'] ?>">
            <button type="submit" class="btn btn-forge w-100 py-3 d-flex align-items-center justify-content-between px-4"
                    style="font-size:1rem; letter-spacing:0.5px;">
                <i class="bi bi-lightning-charge-fill" style="font-size:1.2rem;"></i>
                <span class="fw-black"><?= htmlspecialchars($next_template['name']) ?></span>
                <span style="font-size:0.75rem; opacity:0.8;"><?= $next_template['exercise_count'] ?> ex</span>
            </button>
        </form>
        <div class="text-center mt-2">
            <a href="/forge/workout/" style="font-size:0.72rem; color:#555; text-decoration:none;">different workout ↓</a>
        </div>
    </div>
    <?php else: ?>
    <a href="/forge/workout/" class="btn btn-forge w-100 mb-3 py-3" style="font-size:1.05rem; letter-spacing:0.5px; text-decoration:none;">
        <i class="bi bi-lightning-charge-fill me-2"></i>START WORKOUT
    </a>
    <?php endif; ?>

    <!-- ── NEXT MOVES ────────────────────────────────────────────────────── -->
    <?php if (!empty($next_moves)): ?>
    <div class="card mb-3" style="border-color:#2a1a0a;">
        <div class="card-body py-2 px-3">
            <div class="stat-label mb-1" style="font-size:0.6rem;color:#FF5722;">
                NEXT MOVES · <?= htmlspecialchars($next_template['name']) ?>
            </div>
            <div class="d-flex flex-wrap gap-2">
            <?php foreach ($next_moves as $move): ?>
                <span style="font-size:0.78rem;color:#e0e0e0;">
                    <a href="/forge/exercises/?id=<?= (int)$move['exercise_id'] ?>" style="color:inherit; text-decoration:none;" onclick="event.stopPropagation();"><?= htmlspecialchars($move['name']) ?></a>
                    <span style="color:#FF5722;font-weight:900;"> <?= $move['suggestion'] ?> <?= $unit ?> ↑</span>
                </span>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── LAST SESSION ──────────────────────────────────────────────────── -->
    <?php if ($last_session): ?>
    <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="stat-label">LAST SESSION</div>
        <a href="/forge/history/" style="font-size:0.75rem; color:#FF5722; font-weight:700; text-decoration:none;">HISTORY →</a>
    </div>
    <a href="/forge/history/?id=<?= $last_session['id'] ?>" class="text-decoration-none d-block mb-4">
        <div class="card">
            <div class="card-body py-3 px-3">
                <div class="d-flex align-items-start justify-content-between mb-2">
                    <div>
                        <div style="font-weight:700; font-size:0.95rem; color:#e0e0e0;">
                            <?= htmlspecialchars($last_session['name'] ?: date('l', strtotime($last_session['started_at']))) ?>
                        </div>
                        <div class="text-muted" style="font-size:0.72rem;">
                            <?= date('D j M · ' . $time_fmt, strtotime($last_session['started_at'])) ?>
                            · <?= fmtDuration($last_session['started_at'], $last_session['finished_at']) ?>
                        </div>
                    </div>
                    <?php if ($last_session['total_volume_kg'] > 0): ?>
                    <div class="text-end flex-shrink-0 ms-3">
                        <div class="fw-black" style="font-size:0.95rem; color:#FF5722;"><?= fmtVolume((float)$last_session['total_volume_kg'], $unit) ?></div>
                        <div class="text-muted" style="font-size:0.6rem;">volume</div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($last_session_exercises)): ?>
                <div class="d-flex flex-wrap gap-1">
                <?php foreach ($last_session_exercises as $ex): ?>
                    <span style="background:#1e1e1e; border:1px solid #2a2a2a; border-radius:4px; padding:2px 7px; font-size:0.68rem; color:#888;">
                        <?= htmlspecialchars($ex['name']) ?><?php if ($ex['working_sets']): ?><span style="color:#555;"> ×<?= $ex['working_sets'] ?></span><?php endif; ?>
                    </span>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </a>
    <?php elseif (!$active): ?>
    <div class="text-center py-4" style="color:#333;">
        <i class="bi bi-trophy" style="font-size:3rem;"></i>
        <p class="text-muted mt-3">No sessions logged yet.<br>Hit the button above to start.</p>
    </div>
    <?php endif; ?>

    <!-- ── STAT GRID 2×3 ─────────────────────────────────────────────────── -->
    <?php if ($last_session): ?>
    <div class="row g-2 mb-2 align-items-stretch">

        <!-- THIS WEEK -->
        <div class="col-6 d-flex">
            <div class="card text-center py-3 w-100 d-flex flex-column justify-content-center">
                <div class="stat-value"><?= (int)($this_week['sessions'] ?? 0) ?></div>
                <div class="stat-label">SESSIONS</div>
                <div style="font-size:0.65rem;margin-top:2px;min-height:1.2em;color:<?= $session_delta > 0 ? '#66bb6a' : ($session_delta < 0 ? '#ef5350' : 'transparent') ?>;">
                    <?= $session_delta !== 0 ? ($session_delta > 0 ? '+' : '') . $session_delta . ' vs last wk' : '&nbsp;' ?>
                </div>
            </div>
        </div>
        <div class="col-6 d-flex">
            <div class="card text-center py-3 w-100 d-flex flex-column justify-content-center">
                <div class="stat-value" style="font-size:1.2rem;">
                    <?= $this_week['volume_kg'] > 0 ? fmtVolume((float)$this_week['volume_kg'], $unit) : '—' ?>
                </div>
                <div class="stat-label">VOLUME</div>
                <div style="font-size:0.65rem;margin-top:2px;min-height:1.2em;color:<?= $vol_delta ? (str_starts_with($vol_delta, '+') ? '#66bb6a' : '#ef5350') : 'transparent' ?>;">
                    <?= $vol_delta ? $vol_delta . ' vs last wk' : '&nbsp;' ?>
                </div>
            </div>
        </div>

        <!-- RECORDS -->
        <div class="col-6 d-flex">
            <?php if ($latest_str_pr): ?>
            <a href="/forge/progress/" class="text-decoration-none w-100">
            <div class="card text-center py-3 w-100 h-100 d-flex flex-column justify-content-center">
                <div class="text-muted" style="font-size:0.62rem;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:0 8px;">
                    <?= htmlspecialchars($latest_str_pr['exercise_name']) ?>
                </div>
                <div class="stat-value" style="font-size:1.1rem;"><?= round((float)$latest_str_pr['estimated_1rm'], 1) ?></div>
                <div class="stat-label">STR PR</div>
                <div style="font-size:0.65rem;margin-top:2px;color:#555;"><?= fmtAgo($latest_str_pr['achieved_at']) ?></div>
            </div>
            </a>
            <?php else: ?>
            <div class="card text-center py-3 w-100 d-flex flex-column justify-content-center" style="opacity:0.4;">
                <div class="stat-value" style="color:#333;">—</div>
                <div class="stat-label">STR PR</div>
                <div style="font-size:0.65rem;margin-top:2px;min-height:1.2em;">&nbsp;</div>
            </div>
            <?php endif; ?>
        </div>
        <div class="col-6 d-flex">
            <?php if ($latest_cardio_pr): ?>
            <a href="/forge/progress/#cardio" class="text-decoration-none w-100">
            <div class="card text-center py-3 w-100 h-100 d-flex flex-column justify-content-center">
                <div class="text-muted" style="font-size:0.62rem;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:0 8px;">
                    <?= htmlspecialchars($latest_cardio_pr['exercise_name']) ?>
                </div>
                <div class="stat-value" style="font-size:1.1rem;"><?= fmtCardioVal($latest_cardio_pr['record_type'], (float)$latest_cardio_pr['value']) ?></div>
                <div class="stat-label"><?= fmtCardioLabel($latest_cardio_pr['record_type']) ?></div>
                <div style="font-size:0.65rem;margin-top:2px;color:#555;"><?= fmtAgo($latest_cardio_pr['achieved_at']) ?></div>
            </div>
            </a>
            <?php else: ?>
            <div class="card text-center py-3 w-100 d-flex flex-column justify-content-center" style="opacity:0.4;">
                <div class="stat-value" style="color:#333;">—</div>
                <div class="stat-label">CARDIO PR</div>
                <div style="font-size:0.65rem;margin-top:2px;min-height:1.2em;">&nbsp;</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- BODY + CONSISTENCY -->
        <div class="col-6 d-flex">
            <?php if ($bw_current): ?>
            <a href="/forge/profile/" class="text-decoration-none w-100">
            <div class="card text-center py-3 w-100 h-100 d-flex flex-column justify-content-center">
                <div class="stat-value" style="font-size:1.2rem;"><?= $bw_current ?></div>
                <div class="stat-label">BODYWEIGHT</div>
                <div style="font-size:0.65rem;margin-top:2px;min-height:1.2em;color:<?= $bw_delta === null ? 'transparent' : ($bw_delta <= 0 ? '#66bb6a' : '#ef5350') ?>;">
                    <?php if ($bw_delta !== null): ?>
                    <?= $bw_delta > 0 ? '+' : '' ?><?= $bw_delta ?> <?= $unit ?>
                    <?php else: ?>&nbsp;<?php endif; ?>
                </div>
            </div>
            </a>
            <?php else: ?>
            <a href="/forge/workout/" class="text-decoration-none w-100">
            <div class="card text-center py-3 w-100 d-flex flex-column justify-content-center" style="opacity:0.4;">
                <div class="stat-value" style="color:#333;">—</div>
                <div class="stat-label">BODYWEIGHT</div>
                <div style="font-size:0.65rem;margin-top:2px;color:#555;">log at workout</div>
            </div>
            </a>
            <?php endif; ?>
        </div>
        <div class="col-6 d-flex">
            <a href="/forge/history/" class="text-decoration-none w-100">
            <div class="card text-center py-3 w-100 h-100 d-flex flex-column justify-content-center">
                <div class="stat-value"><?= $streak ?></div>
                <div class="stat-label">STREAK</div>
                <div style="font-size:0.65rem;margin-top:2px;min-height:1.2em;color:#555;">
                    <?= $streak === 1 ? 'week' : 'weeks' ?>
                </div>
            </div>
            </a>
        </div>

    </div>
    <?php endif; ?>

</div>
</body>
</html>
