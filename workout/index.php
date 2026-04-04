<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/db_helper.php';

$db          = get_db_connection();
$unit        = 'kg';
$rest_default = 90;

// Handle form POST (start workout from start screen)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_workout') {
    $existing = $db->query("SELECT id FROM workouts WHERE finished_at IS NULL LIMIT 1")->fetchColumn();
    if (!$existing) {
        $template_id = !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null;
        $name = null;
        if ($template_id) {
            $stmt = $db->prepare("SELECT name FROM templates WHERE id = ?");
            $stmt->execute([$template_id]);
            $name = $stmt->fetchColumn() ?: null;
        }
        $db->prepare("INSERT INTO workouts (name, template_id, started_at) VALUES (?, ?, ?)")->execute([$name, $template_id, date('Y-m-d H:i:s')]);
    }
    header("Location: /forge/workout/");
    exit;
}

// Load active (in-progress) workout
$active = $db->query("SELECT * FROM workouts WHERE finished_at IS NULL ORDER BY started_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$exercises = []; // keyed by exercise_id
$templates = [];

if ($active) {
    // Primary source: workout_exercises (persists even when no sets logged)
    $stmt = $db->prepare("
        SELECT e.id as exercise_id, e.name as exercise_name, e.category,
               e.muscle_primary, e.bw_factor, e.rest_category, we.order_num
        FROM workout_exercises we
        JOIN exercises e ON e.id = we.exercise_id
        WHERE we.workout_id = ?
        ORDER BY we.order_num ASC
    ");
    $stmt->execute([$active['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $eid = (int)$row['exercise_id'];
        $exercises[$eid] = [
            'exercise_id'    => $eid,
            'exercise_name'  => $row['exercise_name'],
            'category'       => $row['category'],
            'muscle_primary' => $row['muscle_primary'],
            'bw_factor'      => $row['bw_factor'],
            'rest_category'  => $row['rest_category'],
            'sets'           => [],
            'prev'           => [],
            'target_sets'    => 3,
        ];
    }

    // Overlay sets (also catches exercises from older workouts lacking workout_exercises rows)
    $stmt = $db->prepare("
        SELECT ws.*, e.name as exercise_name, e.category, e.muscle_primary, e.bw_factor, e.rest_category
        FROM workout_sets ws
        JOIN exercises e ON e.id = ws.exercise_id
        WHERE ws.workout_id = ?
        ORDER BY ws.id ASC
    ");
    $stmt->execute([$active['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $eid = (int)$s['exercise_id'];
        if (!isset($exercises[$eid])) {
            // Backward compat: backfill workout_exercises for pre-migration workouts
            $exercises[$eid] = [
                'exercise_id'    => $eid,
                'exercise_name'  => $s['exercise_name'],
                'category'       => $s['category'],
                'muscle_primary' => $s['muscle_primary'],
                'bw_factor'      => $s['bw_factor'],
                'rest_category'  => $s['rest_category'],
                'sets'           => [],
                'prev'           => [],
                'target_sets'    => 3,
            ];
            $db->prepare("INSERT OR IGNORE INTO workout_exercises (workout_id, exercise_id, order_num) VALUES (?,?,?)")
               ->execute([$active['id'], $eid, count($exercises)]);
        }
        $exercises[$eid]['sets'][] = $s;
    }

    // If still empty and started from a template, seed from template + persist to workout_exercises
    if (empty($exercises) && $active['template_id']) {
        // Determine A/B variant to use (flip from last used; default to A)
        $tpl_row = $db->prepare("SELECT last_ab_variant FROM templates WHERE id = ?");
        $tpl_row->execute([$active['template_id']]);
        $last_ab = $tpl_row->fetchColumn();
        $use_ab  = ($last_ab === 'A') ? 'B' : 'A';

        // Check if this template has any A/B exercises
        $ab_check = $db->prepare("SELECT COUNT(*) FROM template_exercises WHERE template_id = ? AND ab_group IS NOT NULL");
        $ab_check->execute([$active['template_id']]);
        $has_ab_exercises = (int)$ab_check->fetchColumn() > 0;

        $stmt = $db->prepare("
            SELECT te.*, e.name as exercise_name, e.category, e.muscle_primary, e.bw_factor, e.rest_category
            FROM template_exercises te
            JOIN exercises e ON e.id = te.exercise_id
            WHERE te.template_id = ?
              AND (te.ab_group IS NULL OR te.ab_group = ?)
            ORDER BY te.order_num
        ");
        $stmt->execute([$active['template_id'], $use_ab]);
        $order = 0;
        $ins   = $db->prepare("INSERT OR IGNORE INTO workout_exercises (workout_id, exercise_id, order_num) VALUES (?,?,?)");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $te) {
            $eid = (int)$te['exercise_id'];
            $exercises[$eid] = [
                'exercise_id'    => $eid,
                'exercise_name'  => $te['exercise_name'],
                'category'       => $te['category'],
                'muscle_primary' => $te['muscle_primary'],
                'bw_factor'      => $te['bw_factor'],
                'rest_category'  => $te['rest_category'],
                'sets'           => [],
                'prev'           => [],
                'target_sets'    => (int)($te['target_sets'] ?? 3),
            ];
            $ins->execute([$active['id'], $eid, $order++]);
        }

        // Record which variant was used so next time we flip it
        if ($has_ab_exercises) {
            $db->prepare("UPDATE templates SET last_ab_variant = ? WHERE id = ?")
               ->execute([$use_ab, $active['template_id']]);
        }
    }

    // For template-started workouts, merge progression config into each exercise
    if ($active['template_id']) {
        $te_stmt = $db->prepare("
            SELECT te.exercise_id, te.id as te_id, te.target_sets,
                   te.target_reps_min, te.target_reps_max, te.target_rpe,
                   te.progression_scheme, te.progression_increment_kg
            FROM template_exercises te
            WHERE te.template_id = ?
        ");
        $te_stmt->execute([$active['template_id']]);
        foreach ($te_stmt->fetchAll(PDO::FETCH_ASSOC) as $te_row) {
            $eid = (int)$te_row['exercise_id'];
            if (isset($exercises[$eid])) {
                $exercises[$eid] = array_merge($exercises[$eid], $te_row);
            }
        }
    }

    // Fetch previous-session sets for each exercise (for the PREV column)
    foreach (array_keys($exercises) as $eid) {
        $stmt = $db->prepare("
            SELECT ws.set_number, ws.weight_kg, ws.added_weight_kg, ws.reps, ws.rpe,
                   ws.duration_sec, ws.distance_km
            FROM workout_sets ws
            JOIN workouts w ON w.id = ws.workout_id
            WHERE ws.exercise_id = ?
              AND ws.set_type = 'working'
              AND w.finished_at IS NOT NULL
              AND w.id != ?
            ORDER BY w.finished_at DESC, ws.set_number ASC
            LIMIT 20
        ");
        $stmt->execute([$eid, $active['id']]);
        $exercises[$eid]['prev'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Progression suggestion (only for exercises with no sets logged yet this session)
        $exercises[$eid]['suggestion'] = null;
        if (empty($exercises[$eid]['sets']) && !empty($exercises[$eid]['progression_scheme'])) {
            $exercises[$eid]['suggestion'] = compute_progression_suggestion($db, $exercises[$eid]);
        }
    }

} else {
    // Start screen: load templates
    // Order: most-used templates first (primary rotation rises naturally),
    // then within same use-count sort oldest last-used first (next in cycle).
    // Never-used templates fall to bottom.
    $templates = $db->query("
        SELECT t.*,
               COUNT(DISTINCT te.id)                                    as exercise_count,
               COUNT(DISTINCT w.id)                                     as use_count,
               MAX(w.finished_at)                                       as last_used
        FROM templates t
        LEFT JOIN template_exercises te ON te.template_id = t.id
        LEFT JOIN workouts w ON w.template_id = t.id AND w.finished_at IS NOT NULL
        GROUP BY t.id
        ORDER BY use_count DESC, last_used ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Helper: get previous set data for a given 1-based set number
function prevForSet(array $prevSets, int $setNum): ?array {
    if (empty($prevSets)) return null;
    return $prevSets[$setNum - 1] ?? $prevSets[count($prevSets) - 1];
}

function muscleBadge(?string $muscle): string {
    if (!$muscle) return '';
    $cls = 'muscle-' . strtolower(preg_replace('/[^a-zA-Z]/', '', $muscle));
    return '<span class="muscle-badge ' . $cls . '">' . htmlspecialchars($muscle) . '</span>';
}

$workout_name = $active['name'] ?? '';
$page_title   = $active ? 'Workout' : 'Start Workout';
$page_context = 'workout';
include __DIR__ . '/../core/page_head.php';
?>

<?php if (!$active): ?>
<!-- =========================================================
     START SCREEN
     ========================================================= -->
<div class="container-fluid py-4 px-3" style="max-width:600px; margin:0 auto;">

    <div class="mb-4">
        <div class="stat-label mb-1"><?= date('l, j F Y') ?></div>
        <h1 class="fw-black mb-0" style="font-size:2rem; letter-spacing:-1.5px;">START<span style="color:#FF5722;">_</span>WORKOUT</h1>
    </div>

    <!-- Quick start -->
    <form method="POST" class="mb-4">
        <input type="hidden" name="action" value="start_workout">
        <button type="submit" class="btn btn-forge w-100 py-3" style="font-size:1.1rem; letter-spacing:1px;">
            <i class="bi bi-lightning-charge-fill me-2"></i>START EMPTY WORKOUT
        </button>
    </form>

    <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="stat-label">FROM TEMPLATE</div>
        <div class="d-flex gap-2">
            <button id="edit-mode-btn" class="btn btn-sm" onclick="toggleEditMode()"
                    style="background:transparent; border:1px solid #333; color:#666; font-weight:700; font-size:0.75rem; letter-spacing:0.5px; min-height:32px; padding:0 10px;">
                <i class="bi bi-pencil me-1"></i>EDIT
            </button>
            <button class="btn btn-sm" onclick="openNewTemplateModal()"
                    style="background:transparent; border:1px solid #333; color:#FF5722; font-weight:700; font-size:0.75rem; letter-spacing:0.5px; min-height:32px; padding:0 10px;">
                <i class="bi bi-plus me-1"></i>NEW
            </button>
        </div>
    </div>
    <?php if (!empty($templates)): ?>
    <div class="d-grid gap-2" id="template-list">
        <?php foreach ($templates as $t): ?>
        <form method="POST" class="template-form" data-template-id="<?= $t['id'] ?>">
            <input type="hidden" name="action" value="start_workout">
            <input type="hidden" name="template_id" value="<?= $t['id'] ?>">
            <button type="submit" class="btn w-100 text-start d-flex align-items-center justify-content-between template-btn"
                    style="background:#1e1e1e; border:1px solid #333; color:#e0e0e0; padding:12px 14px; min-height:56px;">
                <div class="min-w-0 me-2">
                    <div style="font-weight:700; font-size:0.95rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($t['name']) ?></div>
                    <?php if ($t['notes']): ?>
                    <div class="text-muted" style="font-size:0.75rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($t['notes']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                    <i class="bi bi-pencil edit-mode-icon d-none" style="font-size:0.85rem;color:#FF5722;"></i>
                    <span class="text-accent fw-black" style="font-size:1rem;"><?= $t['exercise_count'] ?></span><span class="text-muted" style="font-size:0.75rem;font-weight:400;"> ex</span>
                </div>
            </button>
        </form>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="text-muted small text-center py-3" style="color:#444;">
        No templates yet
    </div>
    <?php endif; ?>

<!-- New Template Modal -->
<div class="modal fade" id="newTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom:1px solid #333; padding:12px 16px;">
                <h5 class="modal-title fw-black uppercase" style="font-size:1rem; letter-spacing:-0.3px;">NEW TEMPLATE</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-3">
                <input type="text" id="new-template-name" class="form-control"
                       placeholder="Template name…" autocomplete="off"
                       style="font-weight:700; font-size:1rem;">
            </div>
            <div class="modal-footer" style="border-top:1px solid #333;">
                <button type="button" class="btn" data-bs-dismiss="modal"
                        style="background:#1e1e1e; border:1px solid #333; color:#888;">CANCEL</button>
                <button type="button" class="btn btn-forge flex-grow-1" onclick="createTemplate()">
                    CREATE &amp; EDIT
                </button>
            </div>
        </div>
    </div>
</div>

</div>

<?php else: ?>
<!-- =========================================================
     ACTIVE WORKOUT LOGGER
     ========================================================= -->

<!-- Workout header bar -->
<div class="sticky-top" style="background:#0d0d0d; border-bottom:1px solid #222; padding:10px 12px; z-index:900;">
    <div class="d-flex align-items-center gap-2">
        <div class="flex-grow-1 min-w-0">
            <input type="text" id="workout-name-input"
                   class="form-control form-control-sm"
                   placeholder="Workout name…"
                   value="<?= htmlspecialchars($workout_name) ?>"
                   style="background:transparent !important; border:none !important; border-bottom:1px solid #333 !important; border-radius:0 !important; padding:4px 0 !important; font-weight:700; font-size:0.95rem; color:#e0e0e0 !important; box-shadow:none !important;">
        </div>
        <div style="font-family:monospace; font-weight:900; color:#666; font-size:0.9rem; white-space:nowrap;">
            <i class="bi bi-clock me-1" style="font-size:0.75rem;"></i><span id="workout-duration">0:00</span>
        </div>
        <button class="btn btn-forge btn-sm px-3" onclick="openFinishModal()" style="white-space:nowrap; min-height:36px;">
            <i class="bi bi-check-lg me-1"></i>FINISH
        </button>
        <button class="btn btn-sm" onclick="cancelWorkout()" style="background:transparent; border:1px solid #333; color:#555; min-height:36px; padding:0 10px;" title="Cancel workout">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
</div>

<div class="container-fluid py-3 px-3" style="max-width:700px; margin:0 auto;">

    <!-- Exercise blocks -->
    <div id="exercise-blocks">
    <?php foreach ($exercises as $ex):
        $eid      = $ex['exercise_id'];
        $exType   = $ex['category'] === 'Cardio' ? 'cardio'
                  : ($ex['bw_factor'] !== null    ? 'bodyweight'
                  :                                 'strength');
        $bwFactor = $ex['bw_factor'] !== null ? (float)$ex['bw_factor'] : '';
        $prevJson = htmlspecialchars(json_encode($ex['prev']), ENT_QUOTES);
        $existingSets = $ex['sets'];
        // Cardio: show 1 empty row until first set logged, then 0 (single session set)
        // Strength: always maintain (target - logged) empty rows, min 1 so user can always add
        $emptyCount = $exType === 'cardio'
            ? (empty($existingSets) ? 1 : 0)
            : max(0, ($ex['target_sets'] ?? 3) - count($existingSets));

        // Column layout
        if ($exType === 'cardio') {
            $colgroup = '<col style="width:70px;"><col><col style="width:36px;"><col style="width:36px;"><col style="width:32px;"><col style="width:44px;">';
            $thead    = '<th>TIME</th><th>KM</th><th><i class="bi bi-heart-pulse" title="Avg HR"></i></th><th><i class="bi bi-arrow-up-circle" title="Max HR"></i></th><th>INT</th><th></th>';
        } else {
            $kgLabel  = $exType === 'bodyweight' ? '+KG' : strtoupper($unit);
            $colgroup = '<col style="width:28px;"><col style="width:64px;"><col><col><col style="width:52px;"><col style="width:44px;">';
            $thead    = "<th>#</th><th>PREV</th><th>{$kgLabel}</th><th>REPS</th><th>RPE</th><th></th>";
        }

        // PREV cell text builder
        $mkPrev = function(?array $p) use ($exType): string {
            if (!$p) return '—';
            if ($exType === 'cardio') {
                $dur  = $p['duration_sec'] ? gmdate('i:s', (int)$p['duration_sec']) : '—';
                $dist = ($p['distance_km'] ?? null) ? $p['distance_km'] . 'k' : '';
                return $dist ? $dur . '/' . $dist : $dur;
            }
            if ($exType === 'bodyweight') {
                $added = (float)($p['added_weight_kg'] ?? 0);
                $reps  = $p['reps'] ?? '—';
                return ($added > 0 ? '+' . $added : 'BW') . '×' . $reps;
            }
            return ($p['weight_kg'] ?? '—') . '×' . ($p['reps'] ?? '—');
        };
    ?>
    <div class="exercise-block mb-3"
         data-exercise-id="<?= $eid ?>"
         data-ex-type="<?= $exType ?>"
         data-bw-factor="<?= $bwFactor ?>"
         data-category="<?= htmlspecialchars($ex['category']) ?>"
         data-rest-category="<?= htmlspecialchars($ex['rest_category'] ?? '') ?>"
         data-prev='<?= $prevJson ?>'>
        <div class="card">
            <div class="card-body pb-2">
                <div class="d-flex align-items-start justify-content-between mb-2">
                    <div class="min-w-0">
                        <a href="/forge/exercises/?id=<?= (int)$ex['exercise_id'] ?>" class="fw-black uppercase text-decoration-none" style="font-size:0.95rem; letter-spacing:-0.3px; color:#e0e0e0;"><?= htmlspecialchars($ex['exercise_name']) ?></a>
                        <?= muscleBadge($ex['muscle_primary']) ?>
                        <?php if ($exType === 'bodyweight' && $bwFactor > 0): ?>
                        <span class="text-muted" style="font-size:0.68rem; margin-left:6px;">
                            ~<?= round($bwFactor * 100) ?>% BW
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center">
                        <?php if ($exType !== 'cardio'): ?>
                        <button class="btn-icon" onclick="openWarmupCalc(<?= $eid ?>)" type="button" aria-label="Warmup calculator" title="Warmup calculator">
                            <i class="bi bi-thermometer-half" style="font-size:1rem; color:#555;"></i>
                        </button>
                        <?php endif; ?>
                        <button class="btn-icon" onclick="removeExercise(<?= $eid ?>)" type="button" aria-label="Remove exercise">
                            <i class="bi bi-x-lg" style="font-size:1rem; color:#555;"></i>
                        </button>
                    </div>
                </div>

                <?php if (!empty($ex['suggestion']) && $ex['suggestion']['suggestion'] !== null): $sug = $ex['suggestion']; ?>
                <div class="progression-hint mb-2"
                     style="background:#1e1e1e; border-left:3px solid <?= $sug['increase'] ? '#FF5722' : '#555' ?>; padding:8px 10px; border-radius:0 4px 4px 0; cursor:pointer;"
                     onclick="fillSuggestedWeight(<?= $eid ?>, <?= (float)$sug['suggestion'] ?>)"
                     title="Tap to fill all sets with this weight">
                    <div style="font-size:0.68rem; color:#888; font-weight:700; letter-spacing:0.5px; text-transform:uppercase; margin-bottom:2px;">
                        <?= strtoupper($sug['scheme']) ?> PROGRESSION
                    </div>
                    <div class="d-flex align-items-center justify-content-between gap-2">
                        <div style="font-size:0.82rem; color:#ccc; min-width:0;">
                            <?= htmlspecialchars($sug['message']) ?>
                        </div>
                        <div style="font-family:monospace; font-weight:900; font-size:1rem; color:<?= $sug['increase'] ? '#FF5722' : '#aaa' ?>; white-space:nowrap; flex-shrink:0;">
                            <?= $sug['is_bw'] ? '+' : '' ?><?= $sug['suggestion'] ?>kg <i class="bi bi-arrow-right-circle" style="font-size:0.8rem;"></i>
                        </div>
                    </div>
                </div>
                <?php elseif (!empty($ex['suggestion'])): // first-time message ?>
                <div class="mb-2" style="font-size:0.75rem; color:#555; padding:4px 2px;">
                    <i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($ex['suggestion']['message']) ?>
                </div>
                <?php endif; ?>

                <div style="overflow:hidden;">
                <table class="set-table" style="table-layout:fixed; width:100%;">
                    <colgroup><?= $colgroup ?></colgroup>
                    <thead><tr><?= $thead ?></tr></thead>
                    <tbody class="set-tbody">

                    <?php foreach ($existingSets as $i => $s):
                        $setNum = $i + 1;
                        $p      = prevForSet($ex['prev'], $setNum);
                        $prevTxt = $mkPrev($p);
                        $stype  = htmlspecialchars($s['set_type']);
                    ?>
                    <tr class="set-row ticked" data-set-id="<?= $s['id'] ?>" data-set-num="<?= $setNum ?>" data-set-type="<?= $stype ?>">
                        <?php if ($exType !== 'cardio'): ?>
                        <td class="set-num-cell"><span class="set-num" style="font-weight:900;font-size:.9rem;color:#888;"><?= $setNum ?></span></td>
                        <?php endif; ?>
                        <?php if ($exType === 'cardio'): ?>
                        <td><input type="text"   class="input-set dur-input"   value="<?= $s['duration_sec'] ? gmdate('i:s', (int)$s['duration_sec']) : '' ?>" disabled></td>
                        <td><input type="number" class="input-set dist-input"  inputmode="decimal" value="<?= $s['distance_km'] ?? '' ?>" disabled></td>
                        <td><input type="number" class="input-set hr-avg-input" inputmode="numeric" value="<?= $s['hr_avg'] ?? '' ?>" disabled></td>
                        <td><input type="number" class="input-set hr-max-input" inputmode="numeric" value="<?= $s['hr_max'] ?? '' ?>" disabled></td>
                        <td><input type="number" class="input-set int-input"   inputmode="numeric" value="<?= $s['intensity'] ?? '' ?>" disabled></td>
                        <?php else: ?>
                        <td style="font-size:.78rem;color:#888;white-space:nowrap;"><?= $prevTxt ?></td>
                        <td><input type="number" class="input-set weight-input" inputmode="decimal"
                                   value="<?= $exType === 'bodyweight' ? ($s['added_weight_kg'] ?? 0) : ($s['weight_kg'] ?? '') ?>"
                                   step="0.5" min="0" disabled></td>
                        <td><input type="number" class="input-set reps-input"   inputmode="numeric" value="<?= $s['reps'] ?? '' ?>" disabled></td>
                        <td><input type="number" class="input-set rpe-input"    inputmode="decimal" value="<?= $s['rpe'] ?? '' ?>" step="0.5" min="6" max="10" disabled></td>
                        <?php endif; ?>
                        <td><button class="btn-tick btn-icon" onclick="tickSet(this)" type="button">
                            <i class="bi bi-check-circle-fill" style="font-size:1.4rem;color:#4caf50;"></i>
                        </button></td>
                    </tr>
                    <?php endforeach; ?>

                    <?php for ($i = 0; $i < $emptyCount; $i++):
                        $setNum  = count($existingSets) + $i + 1;
                        $p       = prevForSet($ex['prev'], $setNum);
                        $prevTxt = $mkPrev($p);
                    ?>
                    <tr class="set-row" data-set-id="" data-set-num="<?= $setNum ?>" data-set-type="working">
                        <?php if ($exType !== 'cardio'): ?>
                        <td class="set-num-cell"><span class="set-num" style="font-weight:900;font-size:.9rem;color:#888;"><?= $setNum ?></span></td>
                        <?php endif; ?>
                        <?php if ($exType === 'cardio'):
                            $defDur  = $p && $p['duration_sec'] ? gmdate('i:s', (int)$p['duration_sec']) : '';
                            $defDist = $p['distance_km'] ?? '';
                        ?>
                        <td><input type="text" class="input-set dur-input" inputmode="numeric" placeholder="mm:ss" value="<?= $defDur ?>"
                                   oninput="formatDurInput(this)" onblur="normalizeDurInput(this)"></td>
                        <td><input type="number" class="input-set dist-input"  inputmode="decimal" placeholder="0" step="0.01" min="0" value="<?= $defDist ?>"></td>
                        <td><input type="number" class="input-set hr-avg-input" inputmode="numeric" placeholder="—" min="40" max="220"></td>
                        <td><input type="number" class="input-set hr-max-input" inputmode="numeric" placeholder="—" min="40" max="220"></td>
                        <td><input type="number" class="input-set int-input"   inputmode="numeric" placeholder="—" min="1" max="10"
                                   value="<?= $p['intensity'] ?? '' ?>"></td>
                        <?php elseif ($exType === 'bodyweight'):
                            $defAdded = $p ? ($p['added_weight_kg'] ?? '') : '';
                        ?>
                        <td style="font-size:.78rem;color:#888;white-space:nowrap;"><?= $prevTxt ?></td>
                        <td><input type="number" class="input-set weight-input" inputmode="decimal" placeholder="0" value="<?= $defAdded ?>" step="0.5" min="0"></td>
                        <td><input type="number" class="input-set reps-input"   inputmode="numeric" placeholder="—" min="1" max="999"></td>
                        <td><input type="number" class="input-set rpe-input"    inputmode="decimal" placeholder="—" step="0.5" min="6" max="10"></td>
                        <?php else:
                            $defWeight = $p['weight_kg'] ?? '';
                        ?>
                        <td style="font-size:.78rem;color:#888;white-space:nowrap;"><?= $prevTxt ?></td>
                        <td><input type="number" class="input-set weight-input" inputmode="decimal" placeholder="—" value="<?= $defWeight ?>" step="0.5" min="0"></td>
                        <td><input type="number" class="input-set reps-input"   inputmode="numeric" placeholder="—" min="1" max="999"></td>
                        <td><input type="number" class="input-set rpe-input"    inputmode="decimal" placeholder="—" step="0.5" min="6" max="10"></td>
                        <?php endif; ?>
                        <td><button class="btn-tick btn-icon" onclick="tickSet(this)" type="button" aria-label="Log set">
                            <i class="bi bi-circle" style="font-size:1.4rem;color:#444;"></i>
                        </button></td>
                    </tr>
                    <?php endfor; ?>

                    </tbody>
                </table>
                </div>

                <?php if ($exType !== 'cardio'): ?>
                <div class="d-flex gap-2 mt-2 justify-content-end">
                    <button class="btn btn-set-action" onclick="deleteLastSet(<?= $eid ?>)" type="button">
                        <i class="bi bi-dash"></i> SET
                    </button>
                    <button class="btn btn-set-action" onclick="addDropSet(<?= $eid ?>)" type="button"
                            style="border-color:#4a148c; color:#ce93d8;">
                        <i class="bi bi-plus"></i> DROP
                    </button>
                    <button class="btn btn-set-action" onclick="addSet(<?= $eid ?>)" type="button"
                            style="border-color:#555; color:#aaa;">
                        <i class="bi bi-plus"></i> SET
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div><!-- #exercise-blocks -->

    <!-- Add Exercise button -->
    <button class="btn btn-forge-outline w-100 mb-5" type="button"
            data-bs-toggle="modal" data-bs-target="#addExerciseModal"
            onclick="loadExerciseList()"
            style="font-size:0.9rem; padding:14px;">
        <i class="bi bi-plus-lg me-2"></i>ADD EXERCISE
    </button>

</div><!-- container -->



<!-- =========================================================
     ADD EXERCISE MODAL
     ========================================================= -->
<div class="modal fade" id="addExerciseModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen-sm-down modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom:1px solid #333; padding:12px 16px;">
                <div class="flex-grow-1 me-2">
                    <input type="search" id="exercise-search" class="form-control form-control-sm"
                           placeholder="Search exercises…" oninput="filterExercises(this.value)"
                           autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" id="exercise-list-container">
                <div class="text-center text-muted py-5" id="exercise-list-loading">
                    <div class="spinner-border spinner-border-sm text-accent" role="status"></div>
                </div>
                <div id="exercise-list"></div>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================
     FINISH WORKOUT MODAL
     ========================================================= -->
<!-- Warmup Calculator Modal -->
<div class="modal fade" id="warmupModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom:1px solid #333;">
                <h5 class="modal-title fw-black uppercase" style="letter-spacing:-0.5px;">
                    <i class="bi bi-thermometer-half me-2" style="color:#FF5722;"></i>WARMUP SETS
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="warmup-modal-body"></div>
        </div>
    </div>
</div>

<div class="modal fade" id="finishModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom:1px solid #333;">
                <h5 class="modal-title fw-black uppercase" style="letter-spacing:-0.5px;">
                    <span style="color:#FF5722;">FINISH</span> WORKOUT
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Stats summary -->
                <div class="row g-3 mb-3 text-center">
                    <div class="col-4">
                        <div class="stat-label">EXERCISES</div>
                        <div class="stat-value" id="finish-exercise-count">—</div>
                    </div>
                    <div class="col-4">
                        <div class="stat-label">SETS</div>
                        <div class="stat-value" id="finish-set-count">—</div>
                    </div>
                    <div class="col-4">
                        <div class="stat-label">TIME</div>
                        <div class="stat-value" id="finish-duration" style="font-size:1.1rem;">—</div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="stat-label d-block mb-1">NOTES</label>
                    <textarea id="finish-notes" class="form-control" rows="2" placeholder="How'd it go?"></textarea>
                </div>
                <div class="d-flex gap-2">
                    <input type="number" id="finish-bodyweight" class="form-control form-control-sm"
                           inputmode="decimal" placeholder="Bodyweight (<?= $unit ?>)" step="0.1" min="0">
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid #333;">
                <button type="button" class="btn" data-bs-dismiss="modal"
                        style="background:#1e1e1e; border:1px solid #333; color:#888;">CANCEL</button>
                <button type="button" class="btn btn-forge flex-grow-1" onclick="confirmFinish()">
                    <i class="bi bi-check-lg me-1"></i>SAVE &amp; FINISH
                </button>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- =========================================================
     JAVASCRIPT
     ========================================================= -->
<?php if ($active): ?>
<script>
const WORKOUT_ID    = <?= (int)$active['id'] ?>;
const REST_DEFAULT  = <?= $rest_default ?>;
const REST_TIMES    = {
    compound_big4: <?= (int)get_user_setting($db, 'rest_compound_big4_sec', '300') ?>,
    compound:      <?= (int)get_user_setting($db, 'rest_compound_sec',      '240') ?>,
    bodyweight:    <?= (int)get_user_setting($db, 'rest_bodyweight_sec',    '180') ?>,
    isolation:     <?= (int)get_user_setting($db, 'rest_isolation_sec',     '120') ?>,
};
const UNIT          = <?= json_encode($unit) ?>;
const USER_BW_KG    = <?= (float)get_user_setting($db, 'bodyweight_kg', '70') ?>;

// Previous-sets data keyed by exercise_id — used when adding set rows
const prevSetsMap = {
<?php foreach ($exercises as $eid => $ex): ?>
    <?= $eid ?>: <?= json_encode($ex['prev']) ?>,
<?php endforeach; ?>
};

// -------------------------------------------------------
// API helper
// -------------------------------------------------------
async function api(action, data = {}) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(data)) {
        if (v !== null && v !== undefined) fd.append(k, v);
    }
    const r = await fetch('/forge/core/api.php', { method: 'POST', body: fd });
    return r.json();
}

// -------------------------------------------------------
// Workout duration timer
// -------------------------------------------------------
const workoutStartMs = <?= strtotime($active['started_at']) ?> * 1000;
function updateDuration() {
    const elapsed = Math.max(0, Math.floor((Date.now() - workoutStartMs) / 1000));
    const h = Math.floor(elapsed / 3600);
    const m = Math.floor((elapsed % 3600) / 60);
    const s = elapsed % 60;
    const display = (h ? h + ':' : '') +
        String(m).padStart(h ? 2 : 1, '0') + ':' +
        String(s).padStart(2, '0');
    document.getElementById('workout-duration').textContent = display;
}
setInterval(updateDuration, 1000);
updateDuration();

// Save workout name on blur
document.getElementById('workout-name-input').addEventListener('change', function () {
    api('update_workout', { id: WORKOUT_ID, name: this.value });
});

// -------------------------------------------------------
// Rest timer
// -------------------------------------------------------


// -------------------------------------------------------
// Progression
// -------------------------------------------------------
function fillSuggestedWeight(exerciseId, weight) {
    if (weight === null || weight === undefined) return;
    const block = document.querySelector(`.exercise-block[data-exercise-id="${exerciseId}"]`);
    if (!block) return;
    block.querySelectorAll('.set-row:not(.ticked) .weight-input').forEach(input => {
        input.value = weight;
    });
    const hint = block.querySelector('.progression-hint');
    if (hint) { hint.style.opacity = '0.5'; hint.style.cursor = 'default'; hint.onclick = null; }
}

// -------------------------------------------------------
// Helpers
// -------------------------------------------------------
function getPrevForSetNum(exerciseId, setNum) {
    const prev = prevSetsMap[exerciseId] || [];
    if (!prev.length) return null;
    return prev[setNum - 1] || prev[prev.length - 1];
}

function formatDurInput(input) {
    // Keep only digits, cap at 4, auto-insert colon: first 2 = minutes, last 2 = seconds
    const raw = input.value.replace(/\D/g, '').slice(0, 4);
    input.value = raw.length > 2 ? raw.slice(0, 2) + ':' + raw.slice(2) : raw;
}

function normalizeDurInput(input) {
    // On blur: rollover overflow seconds (e.g. 10:90 → 11:30), pad display
    const raw = input.value.replace(/\D/g, '');
    if (!raw) return;
    let mins = 0, secs = 0;
    if (raw.length > 2) {
        mins = parseInt(raw.slice(0, 2), 10);
        secs = parseInt(raw.slice(2).padEnd(2, '0'), 10);
    } else {
        mins = parseInt(raw, 10);
    }
    if (secs >= 60) { mins += Math.floor(secs / 60); secs = secs % 60; }
    input.value = String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
}

function parseDuration(val) {
    val = String(val || '').trim();
    if (!val) return null;
    if (val.includes(':')) {
        const [m, s] = val.split(':');
        return parseInt(m) * 60 + parseInt(s || 0);
    }
    const n = parseInt(val);
    return isNaN(n) ? null : n;
}

function fmtDurInput(sec) {
    if (!sec) return '';
    return Math.floor(sec / 60) + ':' + String(sec % 60).padStart(2, '0');
}

// -------------------------------------------------------
// Set interactions
// -------------------------------------------------------
async function tickSet(btn) {
    const row        = btn.closest('tr');
    const block      = btn.closest('.exercise-block');
    const exerciseId = parseInt(block.dataset.exerciseId);
    const exType     = block.dataset.exType || 'strength';
    const setId      = row.dataset.setId || '';
    const setNum     = parseInt(row.dataset.setNum);
    const setType    = row.dataset.setType || 'working';

    // Un-tick: re-enable editing
    if (row.classList.contains('ticked')) {
        row.classList.remove('ticked', 'pr-flash');
        row.querySelectorAll('input').forEach(i => { i.disabled = false; });
        btn.innerHTML = '<i class="bi bi-circle" style="font-size:1.4rem; color:#444;"></i>';
        return;
    }

    const payload = {
        workout_id: WORKOUT_ID, exercise_id: exerciseId,
        set_id: setId, set_number: setNum, set_type: setType,
    };

    if (exType === 'cardio') {
        const durInput   = row.querySelector('.dur-input');
        const distInput  = row.querySelector('.dist-input');
        const hrAvgInput = row.querySelector('.hr-avg-input');
        const hrMaxInput = row.querySelector('.hr-max-input');
        const durSec = parseDuration(durInput.value);
        if (!durSec && (distInput.value === '' || distInput.value === null)) {
            durInput.style.borderColor = '#ff4444';
            setTimeout(() => { durInput.style.borderColor = ''; }, 1500);
            return;
        }
        const intInput = row.querySelector('.int-input');
        payload.duration_sec = durSec ?? '';
        payload.distance_km  = distInput.value  !== '' ? distInput.value  : '';
        payload.hr_avg       = hrAvgInput.value !== '' ? hrAvgInput.value : '';
        payload.hr_max       = hrMaxInput.value !== '' ? hrMaxInput.value : '';
        payload.intensity    = intInput && intInput.value !== '' ? intInput.value : '';
    } else {
        const wInput   = row.querySelector('.weight-input');
        const rInput   = row.querySelector('.reps-input');
        const rpeInput = row.querySelector('.rpe-input');
        const weight   = wInput.value !== '' ? parseFloat(wInput.value) : null;
        const reps     = rInput.value !== '' ? parseInt(rInput.value)   : null;
        const rpe      = rpeInput.value !== '' ? parseFloat(rpeInput.value) : null;

        if (setType === 'working' && weight === null && reps === null) {
            wInput.style.borderColor = '#ff4444';
            rInput.style.borderColor = '#ff4444';
            setTimeout(() => { wInput.style.borderColor = ''; rInput.style.borderColor = ''; }, 1500);
            return;
        }
        // For bodyweight exercises weight_kg = added kg (server computes effective)
        payload.weight_kg = weight ?? '';
        payload.reps      = reps ?? '';
        payload.rpe       = rpe ?? '';
    }

    btn.innerHTML = '<i class="bi bi-arrow-repeat spin" style="font-size:1.2rem; color:#888;"></i>';
    const result = await api('save_set', payload);

    if (result.status !== 'success') {
        btn.innerHTML = '<i class="bi bi-circle" style="font-size:1.4rem; color:#f44336;"></i>';
        return;
    }

    row.dataset.setId = result.data.id;
    row.classList.add('ticked');
    row.querySelectorAll('input').forEach(i => { i.disabled = true; });

    if (result.is_pr) {
        row.classList.add('pr-flash');
        btn.innerHTML = '<i class="bi bi-trophy-fill" style="font-size:1.3rem; color:#FF5722;" title="New PR!"></i>';
    } else {
        btn.innerHTML = '<i class="bi bi-check-circle-fill" style="font-size:1.4rem; color:#4caf50;"></i>';
    }

    if (exType !== 'cardio' && setType === 'working') {
        const restCat  = block.dataset.restCategory || '';
        const restSecs = REST_TIMES[restCat] ?? REST_DEFAULT;
        startRestTimer(restSecs);
    }
}

function addSet(exerciseId) {
    const block  = document.querySelector(`.exercise-block[data-exercise-id="${exerciseId}"]`);
    const exType = block.dataset.exType || 'strength';
    const tbody  = block.querySelector('.set-tbody');
    const setNum = tbody.querySelectorAll('tr').length + 1;
    const prev   = getPrevForSetNum(exerciseId, setNum);

    const row = document.createElement('tr');
    row.className = 'set-row';
    row.dataset.setId   = '';
    row.dataset.setNum  = setNum;
    row.dataset.setType = 'working';

    const numCell = `<td class="set-num-cell"><span class="set-num" style="font-weight:900;font-size:.9rem;color:#888;">${setNum}</span></td>`;
    const tick    = `<td><button class="btn-tick btn-icon" onclick="tickSet(this)" type="button" aria-label="Log set"><i class="bi bi-circle" style="font-size:1.4rem;color:#444;"></i></button></td>`;

    if (exType === 'cardio') {
        const defDur  = prev?.duration_sec ? fmtDurInput(prev.duration_sec) : '';
        const defDist = prev?.distance_km  ?? '';
        const defInt  = prev?.intensity    ?? '';
        row.innerHTML = `<td><input type="text" class="input-set dur-input" inputmode="numeric" placeholder="mm:ss" value="${defDur}"
                              oninput="formatDurInput(this)" onblur="normalizeDurInput(this)"></td>`
            + `<td><input type="number" class="input-set dist-input"   inputmode="decimal" placeholder="0" step="0.01" min="0" value="${defDist}"></td>`
            + `<td><input type="number" class="input-set hr-avg-input" inputmode="numeric" placeholder="—" min="40" max="220"></td>`
            + `<td><input type="number" class="input-set hr-max-input" inputmode="numeric" placeholder="—" min="40" max="220"></td>`
            + `<td><input type="number" class="input-set int-input"    inputmode="numeric" placeholder="—" min="1" max="10" value="${defInt}"></td>`
            + tick;
        setupLongPress(row);
        tbody.appendChild(row);
        row.querySelector('.dur-input').focus();
    } else if (exType === 'bodyweight') {
        const defAdded = prev?.added_weight_kg ?? '';
        const prevAdded = parseFloat(prev?.added_weight_kg || 0);
        const prevTxt = prev ? ((prevAdded > 0 ? '+' + prevAdded : 'BW') + '×' + (prev.reps ?? '—')) : '—';
        row.innerHTML = numCell
            + `<td style="font-size:.78rem;color:#888;white-space:nowrap;">${prevTxt}</td>`
            + `<td><input type="number" class="input-set weight-input" inputmode="decimal" placeholder="0" value="${defAdded}" step="0.5" min="0"></td>`
            + `<td><input type="number" class="input-set reps-input"   inputmode="numeric" placeholder="—" min="1" max="999"></td>`
            + `<td><input type="number" class="input-set rpe-input"    inputmode="decimal" placeholder="—" step="0.5" min="6" max="10"></td>`
            + tick;
        setupLongPress(row);
        tbody.appendChild(row);
        row.querySelector('.reps-input').focus();
    } else {
        const defWeight = prev?.weight_kg ?? '';
        const prevTxt   = prev ? ((prev.weight_kg ?? '—') + '×' + (prev.reps ?? '—')) : '—';
        row.innerHTML = numCell
            + `<td style="font-size:.78rem;color:#888;white-space:nowrap;">${prevTxt}</td>`
            + `<td><input type="number" class="input-set weight-input" inputmode="decimal" placeholder="—" value="${defWeight}" step="0.5" min="0"></td>`
            + `<td><input type="number" class="input-set reps-input"   inputmode="numeric" placeholder="—" min="1" max="999"></td>`
            + `<td><input type="number" class="input-set rpe-input"    inputmode="decimal" placeholder="—" step="0.5" min="6" max="10"></td>`
            + tick;
        setupLongPress(row);
        tbody.appendChild(row);
        row.querySelector('.weight-input').focus();
    }
}

function addDropSet(exerciseId) {
    const block  = document.querySelector(`.exercise-block[data-exercise-id="${exerciseId}"]`);
    const exType = block.dataset.exType || 'strength';
    const tbody  = block.querySelector('.set-tbody');
    const setNum = tbody.querySelectorAll('tr').length + 1;

    // Suggest 75% of the last ticked working set's weight, rounded to nearest 2.5kg
    const tickedWorking = [...block.querySelectorAll('.set-row.ticked[data-set-type="working"]')];
    const lastWorking   = tickedWorking[tickedWorking.length - 1];
    let suggestedWeight = '';
    if (lastWorking) {
        const wInput = lastWorking.querySelector('.weight-input');
        const lastW  = wInput ? parseFloat(wInput.value) : NaN;
        if (!isNaN(lastW) && lastW > 0) {
            suggestedWeight = Math.round(lastW * 0.75 / 2.5) * 2.5;
        }
    }

    const row = document.createElement('tr');
    row.className = 'set-row';
    row.dataset.setId   = '';
    row.dataset.setNum  = setNum;
    row.dataset.setType = 'dropset';

    const numCell = `<td class="set-num-cell"><span class="set-num" style="font-weight:900;font-size:.9rem;color:#ce93d8;">${setNum}</span></td>`;
    const tick    = `<td><button class="btn-tick btn-icon" onclick="tickSet(this)" type="button" aria-label="Log set"><i class="bi bi-circle" style="font-size:1.4rem;color:#444;"></i></button></td>`;

    if (exType === 'bodyweight') {
        row.innerHTML = numCell
            + `<td style="font-size:.78rem;color:#888;white-space:nowrap;">DROP</td>`
            + `<td><input type="number" class="input-set weight-input" inputmode="decimal" placeholder="0" value="${suggestedWeight}" step="0.5" min="0"></td>`
            + `<td><input type="number" class="input-set reps-input"   inputmode="numeric" placeholder="—" min="1" max="999"></td>`
            + `<td><input type="number" class="input-set rpe-input"    inputmode="decimal" placeholder="—" step="0.5" min="6" max="10"></td>`
            + tick;
    } else {
        row.innerHTML = numCell
            + `<td style="font-size:.78rem;color:#888;white-space:nowrap;">DROP</td>`
            + `<td><input type="number" class="input-set weight-input" inputmode="decimal" placeholder="—" value="${suggestedWeight}" step="0.5" min="0"></td>`
            + `<td><input type="number" class="input-set reps-input"   inputmode="numeric" placeholder="—" min="1" max="999"></td>`
            + `<td><input type="number" class="input-set rpe-input"    inputmode="decimal" placeholder="—" step="0.5" min="6" max="10"></td>`
            + tick;
    }

    setupLongPress(row);
    tbody.appendChild(row);
    row.querySelector('.weight-input').focus();
}

async function deleteLastSet(exerciseId) {
    const block = document.querySelector(`.exercise-block[data-exercise-id="${exerciseId}"]`);
    const tbody = block.querySelector('.set-tbody');
    const rows  = tbody.querySelectorAll('tr');
    if (!rows.length) return;
    const last = rows[rows.length - 1];
    await deleteSetRow(last);
}

// -------------------------------------------------------
// Warmup calculator
// -------------------------------------------------------
function floorTo2_5(v) { return Math.floor(v / 2.5) * 2.5; }

function buildWarmupSets(isBarbell, working) {
    const s2 = floorTo2_5(working * 0.50);
    const s3 = floorTo2_5(working * 0.80);
    const sets = isBarbell
        ? [{ w: '20kg (bar)', r: 8, note: 'empty bar' },
           { w: s2 + 'kg',    r: 5, note: '50%' },
           { w: s3 + 'kg',    r: 3, note: '80%' }]
        : [{ w: floorTo2_5(working * 0.30) + 'kg', r: 8, note: '30%' },
           { w: s2 + 'kg', r: 5, note: '50%' },
           { w: s3 + 'kg', r: 3, note: '80%' }];

    let html = `<div class="stat-label mb-3">WORKING: <span style="font-family:monospace;color:#fff;">${working}kg</span></div>`;
    sets.forEach((s, i) => {
        html += `<div class="d-flex align-items-center justify-content-between py-2" style="border-bottom:1px solid #2a2a2a;">
            <div>
                <span class="stat-label">SET ${i + 1}</span>
                <span class="text-muted" style="font-size:.7rem;margin-left:6px;">${s.note}</span>
            </div>
            <div style="font-family:monospace;font-weight:900;font-size:1rem;color:#e0e0e0;">
                ${s.w} <span style="color:#888;font-size:.85rem;">× ${s.r}</span>
            </div>
        </div>`;
    });
    return html;
}

function openWarmupCalc(exerciseId) {
    const block      = document.querySelector(`.exercise-block[data-exercise-id="${exerciseId}"]`);
    const isBarbell  = (block.dataset.category || '') === 'Barbell';

    // Find working weight: first working-set weight input with a value
    let working = null;
    for (const row of block.querySelectorAll('.set-row[data-set-type="working"]')) {
        const w = row.querySelector('.weight-input');
        if (w && w.value !== '') { working = parseFloat(w.value); break; }
    }

    const body = document.getElementById('warmup-modal-body');
    if (working && working > 0) {
        body.innerHTML = buildWarmupSets(isBarbell, working)
            + `<div class="mt-3 d-flex gap-2 align-items-center">
                <input type="number" id="warmup-override" class="form-control" inputmode="decimal"
                       placeholder="Different weight?" style="max-width:160px;" value="${working}">
                <button class="btn btn-forge" style="font-size:.8rem;" onclick="recalcWarmup(${exerciseId})">RECALC</button>
               </div>`;
    } else {
        body.innerHTML = `<p class="text-muted" style="font-size:.82rem;">Enter your working weight:</p>
            <div class="d-flex gap-2 align-items-center">
                <input type="number" id="warmup-override" class="form-control" inputmode="decimal" placeholder="e.g. 100" style="max-width:160px;">
                <button class="btn btn-forge" style="font-size:.8rem;" onclick="recalcWarmup(${exerciseId})">CALC</button>
            </div>
            <div id="warmup-sets-out" class="mt-3"></div>`;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('warmupModal'));
    modal.show();
}

function recalcWarmup(exerciseId) {
    const block     = document.querySelector(`.exercise-block[data-exercise-id="${exerciseId}"]`);
    const isBarbell = (block.dataset.category || '') === 'Barbell';
    const inp       = document.getElementById('warmup-override');
    const working   = parseFloat(inp.value);
    if (isNaN(working) || working <= 0) { inp.style.borderColor = '#ff4444'; return; }
    inp.style.borderColor = '';

    const out = document.getElementById('warmup-sets-out');
    if (out) {
        // Inline recalc (no-working-weight path)
        out.innerHTML = buildWarmupSets(isBarbell, working);
    } else {
        // Replace full body (had-working-weight path)
        document.getElementById('warmup-modal-body').innerHTML =
            buildWarmupSets(isBarbell, working)
            + `<div class="mt-3 d-flex gap-2 align-items-center">
                <input type="number" id="warmup-override" class="form-control" inputmode="decimal"
                       placeholder="Different weight?" style="max-width:160px;" value="${working}">
                <button class="btn btn-forge" style="font-size:.8rem;" onclick="recalcWarmup(${exerciseId})">RECALC</button>
               </div>`;
    }
}

async function deleteSetRow(row) {
    const setId = row.dataset.setId;
    if (setId) {
        await api('delete_set', { set_id: setId, workout_id: WORKOUT_ID });
    }
    const tbody = row.closest('tbody');
    row.remove();
    // Renumber remaining rows
    tbody.querySelectorAll('tr').forEach((r, i) => {
        r.dataset.setNum = i + 1;
        const n = r.querySelector('.set-num');
        if (n) n.textContent = i + 1;
    });
}

function setupLongPress(row) {
    let timer = null;
    const LONG_MS = 600;
    const start = () => { timer = setTimeout(() => confirmDeleteRow(row), LONG_MS); };
    const cancel = () => clearTimeout(timer);
    row.addEventListener('touchstart', start, { passive: true });
    row.addEventListener('touchend',   cancel);
    row.addEventListener('touchmove',  cancel);
    row.addEventListener('mousedown',  start);
    row.addEventListener('mouseup',    cancel);
    row.addEventListener('mouseleave', cancel);
}

function confirmDeleteRow(row) {
    row.style.background = '#3a0000';
    if (confirm('Delete this set?')) {
        deleteSetRow(row);
    } else {
        row.style.background = '';
    }
}

// Apply long-press to server-rendered set rows
document.querySelectorAll('.set-row').forEach(setupLongPress);

// -------------------------------------------------------
// Exercise management
// -------------------------------------------------------
function removeExercise(exerciseId) {
    const block = document.querySelector(`.exercise-block[data-exercise-id="${exerciseId}"]`);
    if (!block) return;
    const savedRows = [...block.querySelectorAll('.set-row.ticked')].filter(r => r.dataset.setId);
    const doRemove = () => {
        const deletes = savedRows.map(r => api('delete_set', { set_id: r.dataset.setId, workout_id: WORKOUT_ID }));
        Promise.all(deletes).then(() => block.remove());
    };
    const name = block.querySelector('.fw-black').textContent.trim();
    const msg  = savedRows.length > 0
        ? `Remove ${name}? This will delete ${savedRows.length} logged set(s).`
        : `Remove ${name}?`;
    if (confirm(msg)) {
        if (savedRows.length > 0) {
            doRemove();
        } else {
            block.remove();
        }
        api('remove_workout_exercise', { workout_id: WORKOUT_ID, exercise_id: exerciseId });
    }
}

// -------------------------------------------------------
// Add Exercise modal
// -------------------------------------------------------
let allExercises = [];
let exerciseListLoaded = false;

async function loadExerciseList() {
    if (exerciseListLoaded) return;
    const r = await api('list_exercises');
    allExercises = r.data || [];
    exerciseListLoaded = true;
    document.getElementById('exercise-list-loading').classList.add('d-none');
    renderExerciseList(allExercises);
}

function renderExerciseList(list) {
    const container = document.getElementById('exercise-list');
    if (!list.length) {
        container.innerHTML = '<p class="text-muted text-center py-4">No exercises found.</p>';
        return;
    }

    // Group by muscle_primary
    const groups = {};
    list.forEach(ex => {
        const g = ex.muscle_primary || 'Other';
        if (!groups[g]) groups[g] = [];
        groups[g].push(ex);
    });

    let html = '';
    for (const [group, exList] of Object.entries(groups)) {
        html += `<div class="px-3 pt-3 pb-1 stat-label" style="background:#0a0a0a;">${group}</div>`;
        exList.forEach(ex => {
            const inWorkout = !!document.querySelector(`.exercise-block[data-exercise-id="${ex.id}"]`);
            html += `
                <div class="exercise-pick-item d-flex align-items-center justify-content-between px-3 py-3"
                     data-ex-id="${ex.id}"
                     style="border-bottom:1px solid #1a1a1a; cursor:pointer; min-height:56px; background:${inWorkout ? '#1a2a1a' : 'transparent'};">
                    <div>
                        <div style="font-weight:700; font-size:0.9rem;">${ex.name}</div>
                        <div class="text-muted" style="font-size:0.72rem;">${ex.category || ''}${ex.muscle_secondary ? ' · ' + ex.muscle_secondary : ''}</div>
                    </div>
                    <i class="bi ${inWorkout ? 'bi-check-circle-fill text-accent' : 'bi-plus-circle'}" style="font-size:1.2rem; color:${inWorkout ? '#FF5722' : '#555'};"></i>
                </div>`;
        });
    }
    container.innerHTML = html;

    // Event delegation — safer than inline onclick with complex data
    container.querySelectorAll('.exercise-pick-item').forEach(item => {
        item.addEventListener('click', () => {
            const ex = allExercises.find(e => e.id === parseInt(item.dataset.exId));
            if (ex) selectExercise(ex);
        });
    });
}

function filterExercises(query) {
    const q = query.toLowerCase().trim();
    const filtered = q
        ? allExercises.filter(e =>
            e.name.toLowerCase().includes(q) ||
            (e.muscle_primary || '').toLowerCase().includes(q) ||
            (e.category || '').toLowerCase().includes(q))
        : allExercises;
    renderExerciseList(filtered);
}

function selectExercise(ex) {
    // If already in workout, scroll to it
    const existing = document.querySelector(`.exercise-block[data-exercise-id="${ex.id}"]`);
    if (existing) {
        bootstrap.Modal.getInstance(document.getElementById('addExerciseModal')).hide();
        setTimeout(() => existing.scrollIntoView({ behavior: 'smooth', block: 'start' }), 300);
        return;
    }
    bootstrap.Modal.getInstance(document.getElementById('addExerciseModal')).hide();
    addExerciseBlock(ex);
}

function addExerciseBlock(ex) {
    const exType = ex.category === 'Cardio' ? 'cardio'
                 : (ex.bw_factor !== null && ex.bw_factor !== undefined ? 'bodyweight' : 'strength');
    const bwFactor = ex.bw_factor ?? null;

    const muscleCls   = 'muscle-' + (ex.muscle_primary || 'other').toLowerCase().replace(/[^a-z]/g, '');
    const muscleBadge = ex.muscle_primary
        ? `<span class="muscle-badge ${muscleCls}">${ex.muscle_primary}</span>` : '';
    const bwHint = (exType === 'bodyweight' && bwFactor > 0)
        ? `<span class="text-muted" style="font-size:.68rem;margin-left:6px;">~${Math.round(bwFactor*100)}% BW</span>` : '';

    let colgroup, thead;
    if (exType === 'cardio') {
        colgroup = '<col><col style="width:44px;"><col style="width:36px;"><col style="width:36px;"><col style="width:36px;"><col style="width:44px;">';
        thead    = '<th>TIME</th><th>KM</th><th><i class="bi bi-heart-pulse"></i></th><th><i class="bi bi-arrow-up-circle"></i></th><th>INT</th><th></th>';
    } else if (exType === 'bodyweight') {
        colgroup = '<col style="width:28px;"><col style="width:64px;"><col><col><col style="width:52px;"><col style="width:44px;">';
        thead    = '<th>#</th><th>PREV</th><th>+KG</th><th>REPS</th><th>RPE</th><th></th>';
    } else {
        colgroup = '<col style="width:28px;"><col style="width:64px;"><col><col><col style="width:52px;"><col style="width:44px;">';
        thead    = `<th>#</th><th>PREV</th><th>${UNIT.toUpperCase()}</th><th>REPS</th><th>RPE</th><th></th>`;
    }

    const block = document.createElement('div');
    block.className = 'exercise-block mb-3';
    block.dataset.exerciseId   = ex.id;
    block.dataset.exType       = exType;
    block.dataset.category     = ex.category || '';
    block.dataset.restCategory = ex.rest_category || '';
    if (bwFactor !== null) block.dataset.bwFactor = bwFactor;

    block.innerHTML = `
        <div class="card">
            <div class="card-body pb-2">
                <div class="d-flex align-items-start justify-content-between mb-2">
                    <div class="min-w-0">
                        <span class="fw-black uppercase" style="font-size:.95rem;letter-spacing:-.3px;">${ex.name}</span>
                        ${muscleBadge}${bwHint}
                    </div>
                    <div class="d-flex align-items-center">
                        ${exType !== 'cardio' ? `<button class="btn-icon" onclick="openWarmupCalc(${ex.id})" type="button" title="Warmup calculator">
                            <i class="bi bi-thermometer-half" style="font-size:1rem;color:#555;"></i>
                        </button>` : ''}
                        <button class="btn-icon" onclick="removeExercise(${ex.id})" type="button">
                            <i class="bi bi-x-lg" style="font-size:1rem;color:#555;"></i>
                        </button>
                    </div>
                </div>
                <div style="overflow:hidden;">
                <table class="set-table" style="table-layout:fixed;width:100%;">
                    <colgroup>${colgroup}</colgroup>
                    <thead><tr>${thead}</tr></thead>
                    <tbody class="set-tbody"></tbody>
                </table>
                </div>
                ${exType !== 'cardio' ? `<div class="d-flex gap-2 mt-2 justify-content-end">
                    <button class="btn btn-set-action" onclick="deleteLastSet(${ex.id})" type="button">
                        <i class="bi bi-dash"></i> SET
                    </button>
                    <button class="btn btn-set-action" onclick="addDropSet(${ex.id})" type="button"
                            style="border-color:#4a148c;color:#ce93d8;">
                        <i class="bi bi-plus"></i> DROP
                    </button>
                    <button class="btn btn-set-action" onclick="addSet(${ex.id})" type="button"
                            style="border-color:#555;color:#aaa;">
                        <i class="bi bi-plus"></i> SET
                    </button>
                </div>` : ''}
            </div>
        </div>`;

    document.getElementById('exercise-blocks').appendChild(block);

    const orderNum = document.querySelectorAll('.exercise-block').length - 1;
    api('add_workout_exercise', { workout_id: WORKOUT_ID, exercise_id: ex.id, order_num: orderNum });

    api('get_last_exercise_sets', { exercise_id: ex.id, workout_id: WORKOUT_ID })
        .then(r => {
            if (r.status === 'success') prevSetsMap[ex.id] = r.data || [];
            addSet(ex.id);
        });
}

// Clear search when modal opens
document.getElementById('addExerciseModal').addEventListener('show.bs.modal', () => {
    document.getElementById('exercise-search').value = '';
    if (exerciseListLoaded) renderExerciseList(allExercises);
});

// -------------------------------------------------------
// Finish workout
// -------------------------------------------------------
function openFinishModal() {
    const tickedRows     = document.querySelectorAll('.set-row.ticked');
    const exerciseBlocks = document.querySelectorAll('.exercise-block');

    document.getElementById('finish-exercise-count').textContent = exerciseBlocks.length;
    document.getElementById('finish-set-count').textContent = tickedRows.length;
    document.getElementById('finish-duration').textContent = document.getElementById('workout-duration').textContent;

    new bootstrap.Modal(document.getElementById('finishModal')).show();
}

async function cancelWorkout() {
    const tickedCount = document.querySelectorAll('.set-row.ticked').length;
    const msg = tickedCount > 0
        ? `Cancel workout? ${tickedCount} logged set(s) will be deleted.`
        : 'Cancel this workout?';
    if (!confirm(msg)) return;
    const r = await api('delete_workout', { id: WORKOUT_ID });
    if (r.status === 'success') window.location.href = '/forge/';
    else alert('Error: ' + r.message);
}

async function confirmFinish() {
    const btn = document.querySelector('#finishModal .btn-forge');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

    const notes      = document.getElementById('finish-notes').value;
    const bodyweight = document.getElementById('finish-bodyweight').value;

    const result = await api('finish_workout', {
        id:           WORKOUT_ID,
        notes:        notes,
        bodyweight_kg: bodyweight,
    });

    if (result.status === 'success') {
        window.location.href = '/forge/history/';
    } else {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>SAVE & FINISH';
        alert('Error: ' + result.message);
    }
}
</script>
<?php else: ?>
<script>
let editMode = false;
function toggleEditMode() {
    editMode = !editMode;
    const btn = document.getElementById('edit-mode-btn');
    btn.style.color       = editMode ? '#FF5722' : '#666';
    btn.style.borderColor = editMode ? '#FF5722' : '#333';
    document.querySelectorAll('.edit-mode-icon').forEach(el => el.classList.toggle('d-none', !editMode));
}

document.querySelectorAll('.template-form').forEach(form => {
    form.addEventListener('submit', function(e) {
        if (editMode) {
            e.preventDefault();
            window.location.href = '/forge/templates/?id=' + this.dataset.templateId;
        }
    });
});

function openNewTemplateModal() {
    document.getElementById('new-template-name').value = '';
    new bootstrap.Modal(document.getElementById('newTemplateModal')).show();
    setTimeout(() => document.getElementById('new-template-name').focus(), 300);
}

async function createTemplate() {
    const name = document.getElementById('new-template-name').value.trim();
    if (!name) { document.getElementById('new-template-name').focus(); return; }

    const fd = new FormData();
    fd.append('action', 'add_template');
    fd.append('name', name);
    const r = await fetch('/forge/core/api.php', { method: 'POST', body: fd }).then(r => r.json());
    if (r.status === 'success') {
        window.location.href = '/forge/templates/?id=' + r.data.id;
    } else {
        alert('Error: ' + r.message);
    }
}

document.getElementById('new-template-name').addEventListener('keydown', e => {
    if (e.key === 'Enter') createTemplate();
});
</script>
<?php endif; ?>
</body>
</html>
