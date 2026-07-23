<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/db_helper.php';

$db          = get_db_connection();
$stale_workouts = cleanup_stale_active_workouts($db);
$active_era_id = active_training_era_id($db);
$unit        = 'kg';
$rest_default = 90;

// Warmup presets for conditional warmup icon rendering
$warmup_keys = [
    'compound_big4' => 'compound',
    'compound'      => 'compound',
    'bodyweight'    => 'bodyweight',
    'isolation'     => 'accessory',
];
$warmup_preset_exists = [];
foreach (['compound', 'bodyweight', 'accessory'] as $cat) {
    $warmup_preset_exists[$cat] = (bool)get_user_setting($db, 'warmup_presets_' . $cat, '');
}
function warmup_icon_visible(string $rest_category, array $exists): bool {
    // Bodyweight exercises are not eligible for warmup calculations
    if ($rest_category === 'bodyweight') return false;
    global $warmup_keys;
    $key = $warmup_keys[$rest_category] ?? null;
    return $key && ($exists[$key] ?? false);
}

// Warmup presets for JS global (addExercise path)
$wp_compound   = get_user_setting($db, 'warmup_presets_compound',   '');
$wp_bodyweight = get_user_setting($db, 'warmup_presets_bodyweight', '');
$wp_accessory  = get_user_setting($db, 'warmup_presets_accessory',  '');
$warmup_presets_json = json_encode([
    'compound'   => $wp_compound   ? json_decode($wp_compound)   : null,
    'bodyweight' => $wp_bodyweight ? json_decode($wp_bodyweight) : null,
    'accessory'  => $wp_accessory  ? json_decode($wp_accessory)  : null,
]);

// HR profile settings (for %HRR badge on set rows)
$profile_resting_hr = (int)get_user_setting($db, 'profile_resting_hr', '0');
$profile_max_hr     = (int)get_user_setting($db, 'profile_max_hr',     '0');

function render_hr_set_badge(int $hr_max, int $resting_hr, int $max_hr): string {
    if ($resting_hr > 0 && $max_hr > $resting_hr) {
        $pct = (int)round(($hr_max - $resting_hr) / ($max_hr - $resting_hr) * 100);
        if ($pct >= 0 && $pct <= 200) {
            return '<span class="hr-set-badge" title="' . $hr_max . ' bpm peak">♥ ' . $pct . '%</span>';
        }
    }
    return '<span class="hr-set-badge">♥ ' . $hr_max . '</span>';
}

// Handle form POST (start workout from start screen)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_workout') {
    $existing = $db->query("SELECT id FROM workouts WHERE finished_at IS NULL LIMIT 1")->fetchColumn();
    if (!$existing) {
        if (($_POST['workout_type'] ?? '') === 'cardio') {
            $exercise_id = (int)($_POST['exercise_id'] ?? 0);
            if ($exercise_id) {
                $ex_row = $db->prepare("SELECT name FROM exercises WHERE id = ? AND category = 'Cardio'");
                $ex_row->execute([$exercise_id]);
                $ex_name = $ex_row->fetchColumn();
                if ($ex_name) {
                    $db->prepare("INSERT INTO workouts (name, type, started_at, era_id) VALUES (?, 'cardio', ?, ?)")
                       ->execute([$ex_name, date('Y-m-d H:i:s'), active_training_era_id($db)]);
                    $wid = (int)$db->lastInsertId();
                    $db->prepare("INSERT INTO workout_exercises (workout_id, exercise_id, order_num, target_sets) VALUES (?, ?, 0, 1)")
                       ->execute([$wid, $exercise_id]);
                }
            }
        } else {
            $template_id = !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null;
            $name = null;
            if ($template_id) {
                $stmt = $db->prepare("SELECT name FROM templates WHERE id = ?");
                $stmt->execute([$template_id]);
                $name = $stmt->fetchColumn() ?: null;
            }
            $db->prepare("INSERT INTO workouts (name, template_id, started_at, era_id) VALUES (?, ?, ?, ?)")
               ->execute([$name, $template_id, date('Y-m-d H:i:s'), active_training_era_id($db)]);
        }
    }
    header("Location: /forge/workout/");
    exit;
}

// Load active (in-progress) workout
$active = $db->query("SELECT * FROM workouts WHERE finished_at IS NULL ORDER BY started_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$resume_idle_seconds = $active ? max(0, time() - strtotime(workout_last_activity_at($db, (int)$active['id'], $active['started_at']))) : 0;

$exercises         = []; // keyed by exercise_id
$templates         = [];
$cardio_exercises  = [];

if ($active) {
    // Primary source: workout_exercises (persists even when no sets logged)
    $stmt = $db->prepare("
        SELECT e.id as exercise_id, e.name as exercise_name, e.category,
               e.muscle_primary, e.bw_factor, e.rest_category, we.order_num, we.target_sets,
               we.progression_applied_weight
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
            'prev_drops'     => [],
            'target_sets'    => (int)($row['target_sets'] ?? 3),
            'progression_applied_weight' => $row['progression_applied_weight'] !== null ? (float)$row['progression_applied_weight'] : null,
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
            $db->prepare("INSERT OR IGNORE INTO workout_exercises (workout_id, exercise_id, order_num, target_sets) VALUES (?,?,?,?)")
               ->execute([$active['id'], $eid, count($exercises), 3]);
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
        $ab_check = $db->prepare("SELECT COUNT(*) FROM template_exercises WHERE template_id = ? AND ab_group IS NOT NULL AND ab_group != ''");
        $ab_check->execute([$active['template_id']]);
        $has_ab_exercises = (int)$ab_check->fetchColumn() > 0;

        $stmt = $db->prepare("
            SELECT te.*, e.name as exercise_name, e.category, e.muscle_primary, e.bw_factor, e.rest_category
            FROM template_exercises te
            JOIN exercises e ON e.id = te.exercise_id
            WHERE te.template_id = ?
              AND (te.ab_group IS NULL OR te.ab_group = '' OR te.ab_group = ?)
            ORDER BY te.order_num
        ");
        $stmt->execute([$active['template_id'], $use_ab]);
        $order = 0;
        $ins   = $db->prepare("INSERT OR IGNORE INTO workout_exercises (workout_id, exercise_id, order_num, target_sets) VALUES (?,?,?,?)");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $te) {
            $eid = (int)$te['exercise_id'];
            $targetSets = (int)($te['target_sets'] ?? 3);
            $exercises[$eid] = [
                'exercise_id'    => $eid,
                'exercise_name'  => $te['exercise_name'],
                'category'       => $te['category'],
                'muscle_primary' => $te['muscle_primary'],
                'bw_factor'      => $te['bw_factor'],
                'rest_category'  => $te['rest_category'],
                'sets'           => [],
                'prev'           => [],
                'target_sets'    => $targetSets,
            ];
            $ins->execute([$active['id'], $eid, $order++, $targetSets > 0 ? $targetSets : 3]);
        }

        // Store which variant was chosen on this workout; the template counter is
        // only advanced on finish_workout so cancelling doesn't skew the A/B sequence.
        if ($has_ab_exercises) {
            $db->prepare("UPDATE workouts SET ab_variant = ? WHERE id = ?")
               ->execute([$use_ab, $active['id']]);
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
                $existingTarget = (int)($exercises[$eid]['target_sets'] ?? 0);
                $exercises[$eid] = array_merge($exercises[$eid], $te_row);
                if ($existingTarget > 0) {
                    $exercises[$eid]['target_sets'] = $existingTarget;
                }
            }
        }
    }

        // Fetch previous-session sets for each exercise (for the PREV column).
    // Constrained to the single most recent finished workout that included this exercise,
    // so set N always maps to set N from that session — no cross-session bleed.
    foreach (array_keys($exercises) as $eid) {
        $stmt = $db->prepare("
            SELECT ws.set_number, ws.set_type, ws.weight_kg, ws.added_weight_kg, ws.reps, ws.rpe,
                   ws.duration_sec, ws.distance_km
            FROM workout_sets ws
            WHERE ws.exercise_id = ?
              AND ws.set_type IN ('working', 'dropset')
              AND ws.workout_id = (
                  SELECT w2.id FROM workouts w2
                  JOIN workout_sets ws2 ON ws2.workout_id = w2.id
                  WHERE ws2.exercise_id = ? AND w2.finished_at IS NOT NULL AND w2.id != ?
                    AND w2.era_id = ?
                  ORDER BY w2.finished_at DESC LIMIT 1
              )
            ORDER BY ws.set_number ASC
        ");
        $stmt->execute([$eid, $eid, $active['id'], $active_era_id]);
        $previous_sets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $exercises[$eid]['prev'] = array_values(array_filter($previous_sets, fn($set) => $set['set_type'] === 'working'));
        $exercises[$eid]['prev_drops'] = array_values(array_filter($previous_sets, fn($set) => $set['set_type'] === 'dropset'));

        // Progression suggestion: compute whenever a scheme exists, so the hint
        // survives partial-logging reloads. Display rules (handled at render time):
        //   - Always show if any working set is still un-ticked (cases 1, 3, 4)
        //   - Show in greyed/applied state if user clicked it (cases 2, 4, 6)
        //   - Hide only when all sets logged AND user never clicked it (case 5)
        $exercises[$eid]['suggestion'] = null;
        if (!empty($exercises[$eid]['progression_scheme'])) {
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

    // Fetch exercise names from the most recent workout that used each template,
    // for display in the template cards.
    $last_workout_ids = [];
    foreach ($templates as $t) {
        if ($t['last_used']) {
            $lw = $db->prepare("
                SELECT w.id FROM workouts w
                WHERE w.template_id = ? AND w.finished_at IS NOT NULL
                ORDER BY w.finished_at DESC LIMIT 1
            ");
            $lw->execute([$t['id']]);
            $wid = $lw->fetchColumn();
            if ($wid) $last_workout_ids[$t['id']] = (int)$wid;
        }
    }
    $template_last_exercises = [];
    if ($last_workout_ids) {
        $placeholders = implode(',', array_fill(0, count($last_workout_ids), '?'));
        $lw_stmt = $db->prepare("
            SELECT ws.workout_id, e.name,
                   COUNT(CASE WHEN ws.set_type = 'working' THEN 1 END) as working_sets
            FROM workout_sets ws
            JOIN exercises e ON e.id = ws.exercise_id
            WHERE ws.workout_id IN ($placeholders)
            GROUP BY ws.workout_id, ws.exercise_id, e.name
            ORDER BY ws.workout_id, MIN(ws.id)
        ");
        $lw_stmt->execute(array_values($last_workout_ids));
        foreach ($lw_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tid = array_search((int)$row['workout_id'], $last_workout_ids);
            if ($tid !== false) {
                if (!isset($template_last_exercises[$tid])) $template_last_exercises[$tid] = [];
                $template_last_exercises[$tid][] = $row;
            }
        }
    }

    // Cardio exercise picker
    $cardio_exercises = $db->query("
        SELECT id, name FROM exercises WHERE category = 'Cardio' ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function fmtAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 86400)    return 'today';
    if ($diff < 172800)   return 'yesterday';
    if ($diff < 604800)   return (int)($diff / 86400) . 'd ago';
    if ($diff < 2592000)  return (int)($diff / 604800) . 'w ago';
    return date('j M', strtotime($dt));
}

// Helper: get previous set data for a given 1-based set number
function prevForSet(array $prevSets, int $setNum): ?array {
    if (empty($prevSets)) return null;
    return $prevSets[$setNum - 1] ?? null;
}

function muscleBadge(?string $muscle): string {
    if (!$muscle) return '';
    $cls = 'muscle-' . strtolower(preg_replace('/[^a-zA-Z]/', '', $muscle));
    return '<span class="muscle-badge ' . $cls . '">' . htmlspecialchars($muscle) . '</span>';
}

$workout_name = $active['name'] ?? '';
$page_title   = $active ? 'Workout' : 'Start Workout';
$page_context = 'workout';
?>
<script>window.forgeWarmupPresets = <?= $warmup_presets_json ?>;
// Normalise PHP null (string "null") to actual JS null
['compound','bodyweight','accessory'].forEach(k=>{
    if(window.forgeWarmupPresets[k]==='null'||window.forgeWarmupPresets[k]==='') window.forgeWarmupPresets[k]=null;
});</script>
<?php include __DIR__ . '/../core/page_head.php'; ?>

<?php if (!$active): ?>
<!-- =========================================================
     START SCREEN
     ========================================================= -->
<div class="container-fluid py-4 px-3" style="max-width:600px; margin:0 auto;">

    <div class="mb-4">
        <div class="stat-label mb-1"><?= date('l, j F Y') ?></div>
        <h1 class="fw-black mb-0" style="font-size:2rem; letter-spacing:-1.5px;">START<span style="color:#FF5722;">_</span>WORKOUT</h1>
    </div>

    <!-- Quick start: Lift or Cardio -->
    <div class="row g-2 mb-3">
        <div class="col-6">
            <form method="POST">
                <input type="hidden" name="action" value="start_workout">
                <button type="submit" class="btn btn-forge w-100 py-3" style="font-size:1rem; font-weight:900; letter-spacing:0.5px;">
                    <i class="bi bi-lightning-charge-fill me-1"></i>LIFT
                </button>
            </form>
        </div>
        <div class="col-6">
            <button type="button" id="cardio-toggle-btn" onclick="toggleCardioPicker()"
                    class="btn w-100 py-3"
                    style="background:#1e1e1e; border:1px solid #FF5722; color:#FF5722; font-size:1rem; font-weight:900; letter-spacing:0.5px;">
                <i class="bi bi-activity me-1"></i>CARDIO
            </button>
        </div>
    </div>

    <!-- Cardio exercise picker (hidden until CARDIO tapped) -->
    <div id="cardio-picker" style="display:none; margin-bottom:1rem;">
        <?php if (!empty($cardio_exercises)): ?>
        <div class="d-grid gap-2">
            <?php foreach ($cardio_exercises as $ce): ?>
            <form method="POST">
                <input type="hidden" name="action" value="start_workout">
                <input type="hidden" name="workout_type" value="cardio">
                <input type="hidden" name="exercise_id" value="<?= (int)$ce['id'] ?>">
                <button type="submit" class="btn w-100 py-2"
                        style="background:#1a1a1a; border:1px solid #FF5722; color:#e0e0e0; font-weight:700; font-size:0.9rem; letter-spacing:0.5px; text-align:left; padding-left:16px; min-height:48px;">
                    <i class="bi bi-activity me-2" style="color:#FF5722;"></i><?= htmlspecialchars($ce['name']) ?>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="text-muted small text-center py-2" style="color:#555; font-size:0.75rem;">
            No cardio exercises found — add some in the Exercises tab
        </div>
        <?php endif; ?>
    </div>

    <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="stat-label">FROM TEMPLATE</div>
        <button class="btn btn-sm" onclick="openNewTemplateModal()"
                style="background:transparent; border:1px solid #333; color:#FF5722; font-weight:700; font-size:0.75rem; letter-spacing:0.5px; min-height:32px; padding:0 10px;">
            <i class="bi bi-plus me-1"></i>NEW
        </button>
    </div>
    <?php if (!empty($templates)): ?>
    <div class="d-grid gap-2" id="template-list">
        <?php foreach ($templates as $t):
            $tExercises = $template_last_exercises[$t['id']] ?? [];
        ?>
        <div class="card template-card" style="position:relative; background:#1e1e1e; border:1px solid #333;">
            <!-- Edit — top right absolute -->
            <a href="/forge/templates/?id=<?= (int)$t['id'] ?>"
               class="btn btn-sm"
               style="position:absolute; top:10px; right:10px; background:transparent; border:1px solid #333; color:#888; font-weight:700; font-size:0.7rem; letter-spacing:0.5px; min-height:28px; padding:0 8px; display:flex; align-items:center; z-index:1;">
                <i class="bi bi-pencil me-1" style="font-size:0.75rem;"></i>EDIT
            </a>
            <div class="card-body py-3 px-3">
                <div class="mb-2">
                    <div style="font-weight:700; font-size:0.95rem; color:#e0e0e0; padding-right:70px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        <?= htmlspecialchars($t['name']) ?>
                    </div>
                    <?php if ($t['notes']): ?>
                    <div class="text-muted" style="font-size:0.75rem; padding-right:70px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        <?= htmlspecialchars($t['notes']) ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($t['last_used']): ?>
                    <div class="text-muted" style="font-size:0.65rem; margin-top:2px;">
                        Last performed <?= fmtAgo($t['last_used']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($tExercises)): ?>
                <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($tExercises as $ex): ?>
                    <span style="background:#141414; border:1px solid #2a2a2a; border-radius:4px; padding:2px 7px; font-size:0.65rem; color:#888; white-space:nowrap;">
                        <?= htmlspecialchars($ex['name']) ?><?php if ($ex['working_sets']): ?><span style="color:#444;"> ×<?= $ex['working_sets'] ?></span><?php endif; ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <!-- Start Workout — bottom right, full width -->
                <form method="POST" class="mt-3">
                    <input type="hidden" name="action" value="start_workout">
                    <input type="hidden" name="template_id" value="<?= $t['id'] ?>">
                    <button type="submit" class="btn btn-forge w-100 py-2" style="font-weight:900; font-size:0.9rem; letter-spacing:0.5px;">
                        <i class="bi bi-lightning-charge-fill me-2"></i>START WORKOUT
                    </button>
                </form>
            </div>
        </div>
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

<?php elseif (($active['type'] ?? 'strength') === 'strength'): ?>
<!-- =========================================================
     ACTIVE WORKOUT LOGGER (STRENGTH)
     ========================================================= -->

<!-- Workout header bar -->
<?php if ($resume_idle_seconds >= 900): ?>
<div class="px-3 py-2" style="background:#211713;border-bottom:1px solid #6d4c41;font-size:0.74rem;color:#e0e0e0;">
    <strong style="color:#ffab91;">RESUMED WORKOUT</strong> · Last activity <?= (int)floor($resume_idle_seconds / 60) ?> min ago. Continue, or finish/discard from the controls below.
</div>
<?php endif; ?>
<div class="sticky-top" style="background:#0d0d0d; border-bottom:1px solid #222; padding:10px 12px; z-index:900;">
    <div class="d-flex align-items-center gap-2">
        <div class="flex-grow-1 min-w-0">
            <input type="text" id="workout-name-input"
                   class="form-control form-control-sm"
                   placeholder="Workout name…"
                   value="<?= htmlspecialchars($workout_name) ?>"
                   style="background:transparent !important; border:none !important; border-bottom:1px solid #333 !important; border-radius:0 !important; padding:4px 0 !important; font-weight:700; font-size:0.95rem; color:#e0e0e0 !important; box-shadow:none !important;">
        </div>
        <!-- HR badge: hidden until BT available; tap to connect/disconnect -->
        <button id="hr-badge" onclick="window.forgeHR && forgeHR.toggleConnect()"
                style="display:none; flex-shrink:0; align-items:center; gap:3px; min-height:36px; padding:0 8px; background:#1a1a1a; border:1px solid #333; border-radius:6px; cursor:pointer; font-family:inherit;">
            <i class="bi bi-heart-fill" id="hr-badge-icon" style="font-size:0.7rem; color:#444; transition:color 0.3s;"></i>
            <span id="hr-badge-bpm" style="font-family:monospace; font-weight:900; font-size:0.82rem; color:#555; min-width:2ch; transition:color 0.3s;">--</span>
        </button>
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

        // Progression display state. Suggestion is hidden only when all sets are
        // already logged and the user never accepted the suggestion (case 5).
        $progressionAppliedWeight = $ex['progression_applied_weight'] ?? null;
        $progressionApplied       = $progressionAppliedWeight !== null;
        $workingTickedCount       = 0;
        foreach ($existingSets as $_s) {
            if (($_s['set_type'] ?? 'working') === 'working') $workingTickedCount++;
        }
        $allWorkingLogged = $workingTickedCount >= (int)($ex['target_sets'] ?? 0);
        $hideSuggestion   = $allWorkingLogged && !$progressionApplied;

        // Column layout
        if ($exType === 'cardio') {
            $colgroup = '<col style="width:1fr;"><col style="width:1fr;"><col style="width:1fr;"><col style="width:1fr;"><col style="width:1fr;"><col style="width:44px;">';
            $thead    = '<th>TIME</th><th>KM</th><th><i class="bi bi-heart-pulse" title="Avg HR"></i></th><th><i class="bi bi-arrow-up-circle" title="Max HR"></i></th><th>INT</th><th></th>';
        } else {
            $kgLabel  = $exType === 'bodyweight' ? '+KG' : strtoupper($unit);
            $colgroup = '<col style="width:28px;"><col style="width:60px;"><col><col><col style="width:44px;"><col style="width:36px;"><col style="width:44px;">';
            $thead    = "<th>#</th><th>PREV</th><th>{$kgLabel}</th><th>REPS</th><th>RPE</th><th></th><th></th>";
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
         data-target-sets="<?= (int)$ex['target_sets'] ?>"
         data-reps-min="<?= (int)($ex['target_reps_min'] ?? 0) ?>"
         data-reps-max="<?= (int)($ex['target_reps_max'] ?? 0) ?>"
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
                        <?php if ($exType !== 'cardio' && warmup_icon_visible($ex['rest_category'] ?? '', $warmup_preset_exists)): ?>
                        <button class="btn-icon" onclick="openWarmupCalc(<?= $eid ?>)" type="button" aria-label="Warmup calculator" title="Warmup calculator">
                            <i class="bi bi-thermometer-half" style="font-size:1rem; color:#555;"></i>
                        </button>
                        <?php endif; ?>
                        <button class="btn-icon" onclick="moveExercise(<?= $eid ?>, 'up')" type="button" aria-label="Move up">
                            <i class="bi bi-chevron-up" style="font-size:0.9rem; color:#555;"></i>
                        </button>
                        <button class="btn-icon" onclick="moveExercise(<?= $eid ?>, 'down')" type="button" aria-label="Move down">
                            <i class="bi bi-chevron-down" style="font-size:0.9rem; color:#555;"></i>
                        </button>
                        <button class="btn-icon" onclick="openReplaceExercise(<?= $eid ?>)" type="button" aria-label="Replace exercise" title="Replace exercise">
                            <i class="bi bi-arrow-left-right" style="font-size:0.85rem; color:#888;"></i>
                        </button>
                        <button class="btn-icon" onclick="removeExercise(<?= $eid ?>)" type="button" aria-label="Remove exercise">
                            <i class="bi bi-x-lg" style="font-size:1rem; color:#555;"></i>
                        </button>
                    </div>
                </div>

                <?php if (!empty($ex['suggestion']) && $ex['suggestion']['suggestion'] !== null && !$hideSuggestion): $sug = $ex['suggestion']; ?>
                <div class="progression-hint mb-2<?= $progressionApplied ? ' applied' : '' ?>"
                     style="background:#1e1e1e; border-left:3px solid <?= $sug['increase'] ? '#FF5722' : '#555' ?>; padding:8px 10px; border-radius:0 4px 4px 0; cursor:<?= $progressionApplied ? 'default' : 'pointer' ?>;<?= $progressionApplied ? ' opacity:0.5;' : '' ?>"
                     <?php if (!$progressionApplied): ?>onclick="fillSuggestedWeight(<?= $eid ?>, <?= (float)$sug['suggestion'] ?>)"
                     title="Tap to fill all sets with this weight"<?php endif; ?>>
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
                <?php elseif (!empty($ex['suggestion']) && !$hideSuggestion): // first-time message ?>
                <div class="mb-2" style="font-size:0.75rem; color:#555; padding:4px 2px;">
                    <i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($ex['suggestion']['message']) ?>
                </div>
                <?php endif; ?>

                <div class="exercise-complete-panel d-none mb-2" aria-live="polite"></div>

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
                        <td class="pr-cell">
                            <?= $s['is_pr'] ? '<span class="pr-badge">PR</span>' : '' ?>
                            <?php if (!empty($s['hr_max'])): ?>
                                <?= render_hr_set_badge((int)$s['hr_max'], $profile_resting_hr, $profile_max_hr) ?>
                            <?php endif; ?>
                        </td>
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
                            $defAdded = $progressionApplied
                                ? $progressionAppliedWeight
                                : ($p ? ($p['added_weight_kg'] ?? '') : '');
                        ?>
                        <td style="font-size:.78rem;color:#888;white-space:nowrap;"><?= $prevTxt ?></td>
                        <td><input type="number" class="input-set weight-input" inputmode="decimal" placeholder="0" value="<?= $defAdded ?>" step="0.5" min="0"></td>
                        <td><input type="number" class="input-set reps-input"   inputmode="numeric" placeholder="—" min="1" max="999"></td>
                        <td><input type="number" class="input-set rpe-input"    inputmode="decimal" placeholder="—" step="0.5" min="6" max="10"></td>
                        <td class="pr-cell"></td>
                        <?php else:
                            $defWeight = $progressionApplied
                                ? $progressionAppliedWeight
                                : ($p['weight_kg'] ?? '');
                        ?>
                        <td style="font-size:.78rem;color:#888;white-space:nowrap;"><?= $prevTxt ?></td>
                        <td><input type="number" class="input-set weight-input" inputmode="decimal" placeholder="—" value="<?= $defWeight ?>" step="0.5" min="0"></td>
                        <td><input type="number" class="input-set reps-input"   inputmode="numeric" placeholder="—" min="1" max="999"></td>
                        <td><input type="number" class="input-set rpe-input"    inputmode="decimal" placeholder="—" step="0.5" min="6" max="10"></td>
                        <td class="pr-cell"></td>
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

<?php else: ?>
<!-- =========================================================
     ACTIVE CARDIO SESSION
     ========================================================= -->

<!-- Sticky header bar -->
<div class="sticky-top" style="background:#0d0d0d; border-bottom:1px solid #222; padding:10px 12px; z-index:900;">
    <div class="d-flex align-items-center gap-2">
        <div class="flex-grow-1 min-w-0">
            <div style="font-weight:700; font-size:0.95rem; color:#e0e0e0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                <?= htmlspecialchars($active['name'] ?? 'Cardio') ?>
            </div>
        </div>
        <!-- HR badge -->
        <button id="hr-badge" onclick="window.forgeHR && forgeHR.toggleConnect()"
                style="display:none; flex-shrink:0; align-items:center; gap:3px; min-height:36px; padding:0 8px; background:#1a1a1a; border:1px solid #333; border-radius:6px; cursor:pointer; font-family:inherit;">
            <i class="bi bi-heart-fill" id="hr-badge-icon" style="font-size:0.7rem; color:#444; transition:color 0.3s;"></i>
            <span id="hr-badge-bpm" style="font-family:monospace; font-weight:900; font-size:0.82rem; color:#555; min-width:2ch; transition:color 0.3s;">--</span>
        </button>
        <div style="font-family:monospace; font-weight:900; color:#666; font-size:0.9rem; white-space:nowrap;">
            <i class="bi bi-clock me-1" style="font-size:0.75rem;"></i><span id="workout-duration">0:00</span>
        </div>
        <button class="btn btn-forge btn-sm px-3" onclick="openCardioFinishModal()" style="white-space:nowrap; min-height:36px;">
            <i class="bi bi-check-lg me-1"></i>FINISH
        </button>
        <button class="btn btn-sm" onclick="cancelWorkout()" style="background:transparent; border:1px solid #333; color:#555; min-height:36px; padding:0 10px;" title="Cancel session">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
</div>

<div class="container-fluid py-4 px-3" style="max-width:600px; margin:0 auto; text-align:center;">

    <!-- Big session timer -->
    <div style="font-family:monospace; font-weight:900; font-size:4rem; color:#e0e0e0; letter-spacing:-2px; line-height:1; margin:2rem 0 1.5rem;">
        <span id="cardio-timer">0:00</span>
    </div>

    <!-- Live HR display -->
    <div style="margin-bottom:1.5rem;">
        <div style="font-size:0.65rem; color:#555; letter-spacing:1px; font-weight:700; margin-bottom:0.5rem;">HEART RATE</div>
        <div style="display:flex; align-items:center; justify-content:center; gap:6px;">
            <i class="bi bi-heart-fill" id="cardio-hr-icon" style="font-size:1.2rem; color:#333; transition:color 0.3s;"></i>
            <span id="cardio-hr-bpm" style="font-family:monospace; font-weight:900; font-size:2.8rem; color:#e0e0e0; line-height:1;">--</span>
            <span style="font-size:0.75rem; color:#555; align-self:flex-end; padding-bottom:6px;">bpm</span>
        </div>
        <div id="cardio-hr-zone-label" style="font-size:0.72rem; color:#666; margin-top:4px; font-weight:700; letter-spacing:0.5px; min-height:1.2em;"></div>
    </div>

    <!-- HR zone bar -->
    <div style="height:5px; border-radius:3px; background:#1a1a1a; margin:0 auto 1.5rem; max-width:300px; overflow:hidden;">
        <div id="cardio-zone-fill" style="height:100%; width:0%; border-radius:3px; background:#ef5350; transition:width 0.5s, background 0.5s;"></div>
    </div>

    <!-- Stat chips -->
    <div class="d-flex gap-3 justify-content-center" style="margin-bottom:2rem; flex-wrap:wrap;">
        <div style="background:#1a1a1a; border:1px solid #333; border-radius:8px; padding:10px 18px; min-width:85px;">
            <div class="stat-label" style="font-size:0.6rem; margin-bottom:2px;">AVG HR</div>
            <div style="font-family:monospace; font-weight:900; font-size:1.25rem; color:#e0e0e0;" id="cardio-hr-avg">--</div>
        </div>
        <div style="background:#1a1a1a; border:1px solid #333; border-radius:8px; padding:10px 18px; min-width:85px;">
            <div class="stat-label" style="font-size:0.6rem; margin-bottom:2px;">PEAK HR</div>
            <div style="font-family:monospace; font-weight:900; font-size:1.25rem; color:#e0e0e0;" id="cardio-hr-peak">--</div>
        </div>
        <?php if ($profile_resting_hr && $profile_max_hr): ?>
        <div style="background:#1a1a1a; border:1px solid #333; border-radius:8px; padding:10px 18px; min-width:85px;">
            <div class="stat-label" style="font-size:0.6rem; margin-bottom:2px;">%HRR</div>
            <div style="font-family:monospace; font-weight:900; font-size:1.25rem; color:#ef5350;" id="cardio-hrr-pct">--</div>
        </div>
        <?php endif; ?>
    </div>

    <div class="text-muted" style="font-size:0.75rem; color:#444;">
        Tap FINISH when done to log distance and details
    </div>
</div>

<!-- Cardio Finish Modal -->
<div class="modal fade" id="cardioFinishModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom:1px solid #333; padding:12px 16px;">
                <h5 class="modal-title fw-black" style="font-size:1rem; letter-spacing:-0.3px;">
                    <i class="bi bi-flag-fill me-2" style="color:#FF5722;"></i>FINISH SESSION
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-3">
                <div class="mb-3">
                    <div class="stat-label mb-1">ELAPSED TIME</div>
                    <div style="font-family:monospace; font-weight:900; font-size:1.5rem; color:#e0e0e0;" id="cardio-finish-duration">—</div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="stat-label d-block mb-1">DISTANCE (km)</label>
                        <input type="number" id="cardio-finish-distance" class="form-control"
                               inputmode="decimal" placeholder="0.00" step="0.01" min="0">
                    </div>
                    <div class="col-6">
                        <label class="stat-label d-block mb-1">ELEVATION (m)</label>
                        <input type="number" id="cardio-finish-elevation" class="form-control"
                               inputmode="decimal" placeholder="0" step="1" min="0">
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="stat-label d-block mb-1">INTENSITY (1–10)</label>
                        <input type="number" id="cardio-finish-intensity" class="form-control"
                               inputmode="numeric" placeholder="7" step="1" min="1" max="10">
                    </div>
                    <div class="col-6">
                        <label class="stat-label d-block mb-1">BODYWEIGHT (<?= $unit ?>)</label>
                        <input type="number" id="cardio-finish-bodyweight" class="form-control"
                               inputmode="decimal" placeholder="—" step="0.1" min="0">
                    </div>
                </div>
                <div class="mb-2">
                    <label class="stat-label d-block mb-1">NOTES</label>
                    <textarea id="cardio-finish-notes" class="form-control" rows="2" placeholder="How'd it go?"></textarea>
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid #333;">
                <button type="button" class="btn" data-bs-dismiss="modal"
                        style="background:#1e1e1e; border:1px solid #333; color:#888;">CANCEL</button>
                <button type="button" class="btn btn-forge flex-grow-1" onclick="confirmCardioFinish()">
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

<!-- Warmup Calculator Modal (present on all workout page states) -->
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

<script src="/forge/workout/warmups.js?v=1"></script>
<?php if ($active && ($active['type'] ?? 'strength') === 'strength'): ?>
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
const RESTING_HR    = <?= $profile_resting_hr ?>;
const MAX_HR_PROFILE = <?= $profile_max_hr ?>;

// Previous-sets data keyed by exercise_id — used when adding set rows
const prevSetsMap = {
<?php foreach ($exercises as $eid => $ex): ?>
    <?= $eid ?>: <?= json_encode($ex['prev']) ?>,
<?php endforeach; ?>
};
const prevDropSetsMap = {
<?php foreach ($exercises as $eid => $ex): ?>
    <?= $eid ?>: <?= json_encode($ex['prev_drops']) ?>,
<?php endforeach; ?>
};

async function api(action, data = {}) {
    return window.forgeApi(action, data);
}

function syncExerciseTargetSets(block) {
    if (!block) return;
    const exerciseId = parseInt(block.dataset.exerciseId || '0', 10);
    if (!exerciseId) return;
    const rows = block.querySelectorAll('.set-row').length;
    const targetSets = Math.max(1, rows);
    block.dataset.targetSets = String(targetSets);
    api('update_workout_exercise_target_sets', {
        workout_id: WORKOUT_ID,
        exercise_id: exerciseId,
        target_sets: targetSets,
    });
    updateBarNextUp();
}

async function showExerciseCompletion(block) {
    const panel = block.querySelector('.exercise-complete-panel');
    if (!panel || panel.dataset.loaded === '1') return;
    const exerciseId = parseInt(block.dataset.exerciseId || '0', 10);
    const progressionHint = block.querySelector('.progression-hint');
    if (progressionHint) progressionHint.style.display = 'none';
    panel.classList.remove('d-none');
    panel.style.cssText = 'background:#1e1e1e;border-left:3px solid #555;padding:8px 10px;border-radius:0 4px 4px 0;';
    panel.innerHTML = '<div class="stat-label">EXERCISE COMPLETE</div><div style="font-size:.78rem;color:#e0e0e0;">Calculating your next target…</div>';
    const result = await api('get_exercise_completion', { workout_id: WORKOUT_ID, exercise_id: exerciseId });
    if (result.status !== 'success') {
        panel.innerHTML = '<div class="stat-label">EXERCISE COMPLETE</div><div style="font-size:.78rem;color:#e0e0e0;">Working sets logged — finish the workout to lock in your next target.</div>';
        return;
    }
    const data = result.data || {};
    const progress = data.tone === 'progress';
    panel.dataset.loaded = '1';
    panel.classList.remove('d-none');
    panel.style.cssText = `background:#1e1e1e;border-left:3px solid ${progress ? '#81c784' : '#555'};padding:8px 10px;border-radius:0 4px 4px 0;`;
    panel.innerHTML = `<div class="stat-label" style="color:${progress ? '#81c784' : '#888'};">EXERCISE COMPLETE</div><div style="font-size:.78rem;color:#e0e0e0;">${data.message || 'Working sets logged.'}</div>`;
}

window.forgeWorkoutClockConfig = { startedMs: <?= strtotime($active['started_at']) ?> * 1000 };

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
    if (hint) {
        hint.classList.add('applied');
        hint.style.opacity = '0.5';
        hint.style.cursor = 'default';
        hint.onclick = null;
        hint.removeAttribute('title');
    }
    // Persist so the applied state and prefilled weight survive page reload.
    const fd = new FormData();
    fd.append('action', 'apply_progression');
    fd.append('workout_id', WORKOUT_ID);
    fd.append('exercise_id', exerciseId);
    fd.append('weight', weight);
    fetch('/forge/core/api.php', { method: 'POST', body: fd }).catch(() => {});
}

// -------------------------------------------------------
// Helpers
// -------------------------------------------------------
function getPrevForSetNum(exerciseId, setNum) {
    const prev = prevSetsMap[exerciseId] || [];
    if (!prev.length) return null;
    return prev[setNum - 1] ?? null;
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
// HR set badge helper
// -------------------------------------------------------
function renderHrSetBadge(hrMax) {
    if (RESTING_HR > 0 && MAX_HR_PROFILE > RESTING_HR) {
        const pct = Math.round((hrMax - RESTING_HR) / (MAX_HR_PROFILE - RESTING_HR) * 100);
        if (pct >= 0 && pct <= 200) {
            return `<span class="hr-set-badge" title="${hrMax} bpm peak">♥ ${pct}%</span>`;
        }
    }
    return `<span class="hr-set-badge">♥ ${hrMax}</span>`;
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
        row.classList.remove('ticked');
        const completionPanel = block.querySelector('.exercise-complete-panel');
        if (completionPanel) {
            completionPanel.classList.add('d-none');
            completionPanel.dataset.loaded = '';
            completionPanel.innerHTML = '';
        }
        const progressionHint = block.querySelector('.progression-hint');
        if (progressionHint) progressionHint.style.display = '';
        const prCellUntick = row.querySelector('.pr-cell');
        if (prCellUntick) prCellUntick.innerHTML = '';
        row.querySelectorAll('input').forEach(i => { i.disabled = false; });
        btn.innerHTML = '<i class="bi bi-circle" style="font-size:1.4rem; color:#444;"></i>';
        hideWorkoutDoneBar();
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
        if (reps === null || Number.isNaN(reps) || reps <= 0) {
            rInput.style.borderColor = '#ff4444';
            setTimeout(() => { rInput.style.borderColor = ''; }, 1500);
            rInput.focus();
            return;
        }
        // For bodyweight exercises weight_kg = added kg (server computes effective)
        payload.weight_kg = weight ?? '';
        payload.reps      = reps ?? '';
        payload.rpe       = rpe ?? '';
        // Capture peak HR from monitor over the last 20 seconds
        if (window.forgeHR) {
            const peak = forgeHR.getPeakBpm(20000);
            if (peak) payload.hr_max = peak;
        }
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
        const prCell = row.querySelector('.pr-cell');
        if (prCell) prCell.innerHTML = '<span class="pr-badge">PR</span>';
        btn.innerHTML = '<i class="bi bi-trophy-fill pr-trophy-anim" style="font-size:1.3rem; color:#FFD700;"></i>';
        setTimeout(() => {
            btn.innerHTML = '<i class="bi bi-check-circle-fill" style="font-size:1.4rem; color:#4caf50;"></i>';
        }, 2000);
    } else {
        btn.innerHTML = '<i class="bi bi-check-circle-fill" style="font-size:1.4rem; color:#4caf50;"></i>';
    }

    // Show peak HR badge in pr-cell (non-cardio sets only; cardio has manual HR inputs)
    if (exType !== 'cardio') {
        const savedHrMax = result.data?.hr_max;
        if (savedHrMax) {
            const prCell = row.querySelector('.pr-cell');
            if (prCell) prCell.insertAdjacentHTML('beforeend', renderHrSetBadge(savedHrMax));
        }
    }

    if (exType !== 'cardio' && setType === 'working') {
        if (isWorkoutComplete()) {
            skipRest();
            showWorkoutDoneBar();
        } else {
            const restCat  = block.dataset.restCategory || '';
            const restSecs = REST_TIMES[restCat] ?? REST_DEFAULT;
            startRestTimer(restSecs);
        }
    }

    updateBarNextUp();
    if (exType !== 'cardio' && setType === 'working') {
        const target = parseInt(block.dataset.targetSets || '0', 10);
        const done = block.querySelectorAll('.set-row.ticked[data-set-type="working"]').length;
        if (target > 0 && done >= target) showExerciseCompletion(block);
    }
}

// Returns true only when every exercise block has met its working-set target.
function isWorkoutComplete() {
    const blocks = document.querySelectorAll('.exercise-block');
    if (!blocks.length) return false;
    for (const block of blocks) {
        const target = parseInt(block.dataset.targetSets || '0');
        const done   = block.querySelectorAll('.set-row.ticked[data-set-type="working"]').length;
        if (done < target) return false;
    }
    return true;
}

// Cancel rest timer and surface the green Finish button in the active bar.
function showWorkoutDoneBar() {
    skipRest();
    const contBtn = document.getElementById('ab-continue-btn');
    const doneBtn = document.getElementById('ab-done-btn');
    if (contBtn) contBtn.style.display = 'none';
    if (doneBtn) doneBtn.style.display = 'flex';
}

// Scroll so that block is visible, accounting for the sticky header and active bar.
// mode='start' — always scroll the block to just below the header (new exercise).
// mode='nearest' — only scroll if needed; prefers minimal movement (move up/down).
function scrollToBlock(block, mode) {
    const headerH = document.querySelector('.sticky-top')?.offsetHeight || 0;
    const barH    = document.getElementById('active-bar')?.offsetHeight  || 0;
    const GAP     = 12;

    if (mode === 'start') {
        const top = block.getBoundingClientRect().top + window.scrollY - headerH - GAP;
        window.scrollTo({ top, behavior: 'smooth' });
        return;
    }

    // nearest: only adjust if the block falls outside the visible safe zone
    const rect    = block.getBoundingClientRect();
    const safeTop = headerH + GAP;
    const safeBot = window.innerHeight - barH - GAP;
    if (rect.top < safeTop) {
        window.scrollBy({ top: rect.top - safeTop, behavior: 'smooth' });
    } else if (rect.bottom > safeBot) {
        window.scrollBy({ top: rect.bottom - safeBot, behavior: 'smooth' });
    }
}

// After appending a new exercise block, move it to just after the most recently
// active exercise. If no sets have been ticked anywhere, leave it at the end.
function smartInsertExercise(block) {
    const container = document.getElementById('exercise-blocks');
    let lastActiveBlock = null;
    let highestId = -1;
    container.querySelectorAll('.exercise-block').forEach(b => {
        if (b === block) return;
        b.querySelectorAll('.set-row.ticked').forEach(row => {
            const sid = parseInt(row.dataset.setId || '0');
            if (sid > highestId) { highestId = sid; lastActiveBlock = b; }
        });
    });
    if (lastActiveBlock) lastActiveBlock.after(block);
}

// Move an exercise block one position up or down and persist the new order.
function moveExercise(exerciseId, dir) {
    const container = document.getElementById('exercise-blocks');
    const blocks = [...container.querySelectorAll('.exercise-block')];
    const block = container.querySelector(`.exercise-block[data-exercise-id="${exerciseId}"]`);
    const idx = blocks.indexOf(block);
    if (dir === 'up' && idx > 0) {
        blocks[idx - 1].before(block);
    } else if (dir === 'down' && idx < blocks.length - 1) {
        blocks[idx + 1].after(block);
    } else {
        return; // already at edge
    }
    requestAnimationFrame(() => scrollToBlock(block, 'nearest'));
    saveExerciseOrder();
}

// Persist the current DOM order of exercises to the database.
function saveExerciseOrder() {
    const ids = [...document.querySelectorAll('.exercise-block')].map(b => b.dataset.exerciseId);
    api('reorder_workout_exercises', { workout_id: WORKOUT_ID, order: JSON.stringify(ids) });
}

// Reverse done state when a new set or exercise is added after completion.
function hideWorkoutDoneBar() {
    const doneBtn = document.getElementById('ab-done-btn');
    if (!doneBtn || doneBtn.style.display === 'none') return;
    doneBtn.style.display = 'none';
    const contBtn = document.getElementById('ab-continue-btn');
    if (contBtn) contBtn.style.display = 'flex';
}

// Update the bar's "next up" label live after each set tick (no page reload needed).
function updateBarNextUp() {
    const labelEl = document.getElementById('ab-next-label');
    const nameEl  = document.getElementById('ab-next-name');
    if (!labelEl || !nameEl) return;

    const blocks = [...document.querySelectorAll('.exercise-block')];
    if (!blocks.length) return;

    // Find exercise with most recently saved ticked set (highest numeric set-id)
    let lastBlock  = null;
    let highestId  = -1;
    blocks.forEach(block => {
        block.querySelectorAll('.set-row.ticked').forEach(row => {
            const sid = parseInt(row.dataset.setId || '0');
            if (sid > highestId) { highestId = sid; lastBlock = block; }
        });
    });

    if (!lastBlock) return;

    const tickedCount = lastBlock.querySelectorAll('.set-row.ticked').length;
    const targetSets  = parseInt(lastBlock.dataset.targetSets || '3');
    const name = lastBlock.querySelector('a.fw-black, span.fw-black')?.textContent?.trim() || '';

    // Build a "weight × reps" hint for a block, matching what the PHP bar renders.
    // Weight: prefers the last ticked working-set row; falls back to the first
    // working-set row (pre-filled by PHP from last session).
    // Reps: from data-reps-min / data-reps-max on the block (template target).
    function hintFor(block) {
        const exType = block.dataset.exType || 'strength';
        if (exType === 'cardio') return '';
        const ticked = [...block.querySelectorAll('.set-row.ticked[data-set-type="working"]')];
        const sourceRow = ticked.length
            ? ticked[ticked.length - 1]
            : block.querySelector('.set-row[data-set-type="working"]');
        const wInput = sourceRow ? sourceRow.querySelector('.weight-input') : null;
        const w = wInput ? parseFloat(wInput.value) : NaN;
        const weightStr = (!w || isNaN(w)) ? ''
            : (exType === 'bodyweight' ? `+${w} kg` : `${w} kg`);

        const rMin = parseInt(block.dataset.repsMin || '0');
        const rMax = parseInt(block.dataset.repsMax || '0');
        const repsStr = rMin > 0
            ? ('× ' + (rMin === rMax || rMax === 0 ? rMin : `${rMin}–${rMax}`))
            : '';

        return [weightStr, repsStr].filter(Boolean).join('  ');
    }

    if (tickedCount < targetSets) {
        labelEl.style.color = '#FF5722';
        labelEl.textContent = `NEXT UP · SET ${tickedCount + 1} OF ${targetSets}`;
        const hint = hintFor(lastBlock);
        nameEl.innerHTML = hint
            ? `${name}<span style="color:#888;font-weight:400;font-size:0.72rem;margin-left:6px;">${hint}</span>`
            : name;
    } else {
        // Scan ALL blocks in order for the first with remaining working sets
        let found = false;
        for (const b of blocks) {
            const bt = b.querySelectorAll('.set-row.ticked[data-set-type="working"]').length;
            const bs = parseInt(b.dataset.targetSets || '0');
            if (bt < bs) {
                const bn = b.querySelector('a.fw-black, span.fw-black')?.textContent?.trim() || '';
                const hint = hintFor(b);
                labelEl.style.color = '#FF5722';
                labelEl.textContent = `NEXT UP · SET ${bt + 1} OF ${bs}`;
                nameEl.innerHTML = hint
                    ? `${bn}<span style="color:#888;font-weight:400;font-size:0.72rem;margin-left:6px;">${hint}</span>`
                    : bn;
                found = true;
                break;
            }
        }
        if (!found) {
            labelEl.style.color = '#4caf50';
            labelEl.textContent = 'ALL SETS DONE';
            nameEl.textContent  = 'Finish workout?';
            showWorkoutDoneBar();
        }
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
    const prCell  = `<td class="pr-cell"></td>`;
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
            + prCell + tick;
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
            + prCell + tick;
        setupLongPress(row);
        tbody.appendChild(row);
        row.querySelector('.weight-input').focus();
    }
    hideWorkoutDoneBar();
    syncExerciseTargetSets(block);
    // Keep the button bar in viewport — scroll by the actual rendered row height
    requestAnimationFrame(() => window.scrollBy({ top: row.offsetHeight, behavior: 'instant' }));
}

function addDropSet(exerciseId) {
    const block  = document.querySelector(`.exercise-block[data-exercise-id="${exerciseId}"]`);
    const exType = block.dataset.exType || 'strength';
    const tbody  = block.querySelector('.set-tbody');
    const setNum = tbody.querySelectorAll('tr').length + 1;

    // Reuse the matching drop set from the previous session when there is one.
    // Otherwise retain the 75%-of-last-working-set fallback for first-time drops.
    const dropIndex = block.querySelectorAll('.set-row[data-set-type="dropset"]').length;
    const previousDrop = (prevDropSetsMap[exerciseId] || [])[dropIndex] || null;
    const tickedWorking = [...block.querySelectorAll('.set-row.ticked[data-set-type="working"]')];
    const lastWorking   = tickedWorking[tickedWorking.length - 1];
    let suggestedWeight = previousDrop
        ? (exType === 'bodyweight' ? previousDrop.added_weight_kg : previousDrop.weight_kg)
        : '';
    if (!previousDrop && lastWorking) {
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
    const prCell  = `<td class="pr-cell"></td>`;
    const tick    = `<td><button class="btn-tick btn-icon" onclick="tickSet(this)" type="button" aria-label="Log set"><i class="bi bi-circle" style="font-size:1.4rem;color:#444;"></i></button></td>`;
    const previousLabel = previousDrop
        ? `DROP · ${suggestedWeight || 0} × ${previousDrop.reps || '—'}`
        : 'DROP';
    const previousReps = previousDrop?.reps ?? '';
    const previousRpe = previousDrop?.rpe ?? '';

    if (exType === 'bodyweight') {
        row.innerHTML = numCell
            + `<td style="font-size:.78rem;color:#888;white-space:nowrap;">${previousLabel}</td>`
            + `<td><input type="number" class="input-set weight-input" inputmode="decimal" placeholder="0" value="${suggestedWeight}" step="0.5" min="0"></td>`
            + `<td><input type="number" class="input-set reps-input"   inputmode="numeric" placeholder="—" min="1" max="999" value="${previousReps}"></td>`
            + `<td><input type="number" class="input-set rpe-input"    inputmode="decimal" placeholder="—" step="0.5" min="6" max="10" value="${previousRpe}"></td>`
            + prCell + tick;
    } else {
        row.innerHTML = numCell
            + `<td style="font-size:.78rem;color:#888;white-space:nowrap;">${previousLabel}</td>`
            + `<td><input type="number" class="input-set weight-input" inputmode="decimal" placeholder="—" value="${suggestedWeight}" step="0.5" min="0"></td>`
            + `<td><input type="number" class="input-set reps-input"   inputmode="numeric" placeholder="—" min="1" max="999" value="${previousReps}"></td>`
            + `<td><input type="number" class="input-set rpe-input"    inputmode="decimal" placeholder="—" step="0.5" min="6" max="10" value="${previousRpe}"></td>`
            + prCell + tick;
    }

    setupLongPress(row);
    tbody.appendChild(row);
    row.querySelector('.weight-input').focus();
    hideWorkoutDoneBar();
    syncExerciseTargetSets(block);
}

async function deleteLastSet(exerciseId) {
    const block = document.querySelector(`.exercise-block[data-exercise-id="${exerciseId}"]`);
    const tbody = block.querySelector('.set-tbody');
    const rows  = tbody.querySelectorAll('tr');
    if (!rows.length) return;
    // Single set = delete the whole exercise (removes the exercise and shows confirm dialog)
    if (rows.length === 1) {
        removeExercise(exerciseId);
        return;
    }
    const last = rows[rows.length - 1];
    await deleteSetRow(last);
}

async function deleteSetRow(row) {
    const setId = row.dataset.setId;
    const block = row.closest('.exercise-block');
    const rowH  = row.offsetHeight;
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
    syncExerciseTargetSets(block);
    // Keep the button bar in viewport — scroll up by the actual deleted row height
    requestAnimationFrame(() => window.scrollBy({ top: -rowH, behavior: 'instant' }));
}

function setupLongPress(row) {
    let timer = null;
    const LONG_MS = 600;
    const start = (e) => {
        if (e.target.closest('input, select, textarea')) return;
        timer = setTimeout(() => confirmDeleteRow(row), LONG_MS);
    };
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

let replaceExerciseId = null;
function openReplaceExercise(exerciseId) {
    const block = document.querySelector(`.exercise-block[data-exercise-id="${exerciseId}"]`);
    if (!block) return;
    if (block.querySelector('.set-row.ticked')) {
        alert('Only an unstarted exercise can be replaced. Add the alternative separately if you have already logged sets.');
        return;
    }
    replaceExerciseId = exerciseId;
    document.getElementById('exercise-search').placeholder = 'Search replacement…';
    loadExerciseList().then(() => renderExerciseList(allExercises));
    new bootstrap.Modal(document.getElementById('addExerciseModal')).show();
}

// -------------------------------------------------------
// Add Exercise modal
// -------------------------------------------------------
let allExercises = [];
let exerciseListLoaded = false;

async function loadExerciseList() {
    if (exerciseListLoaded) return;
    const r = await api('list_exercises');
    allExercises = (r.data || []).filter(e => e.category !== 'Cardio');
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
    if (replaceExerciseId !== null) {
        const fromId = replaceExerciseId;
        replaceExerciseId = null;
        const existing = document.querySelector(`.exercise-block[data-exercise-id="${ex.id}"]`);
        if (existing) { alert('That exercise is already in this workout.'); return; }
        const oldBlock = document.querySelector(`.exercise-block[data-exercise-id="${fromId}"]`);
        bootstrap.Modal.getInstance(document.getElementById('addExerciseModal')).hide();
        api('replace_workout_exercise', { workout_id: WORKOUT_ID, from_exercise_id: fromId, to_exercise_id: ex.id }).then(result => {
            if (result.status !== 'success') { alert('Error: ' + result.message); return; }
            if (oldBlock) oldBlock.remove();
            addExerciseBlock(ex);
        });
        return;
    }
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
        colgroup = '<col style="width:1fr;"><col style="width:1fr;"><col style="width:1fr;"><col style="width:1fr;"><col style="width:1fr;"><col style="width:44px;">';
        thead    = '<th>TIME</th><th>KM</th><th><i class="bi bi-heart-pulse"></i></th><th><i class="bi bi-arrow-up-circle"></i></th><th>INT</th><th></th>';
    } else if (exType === 'bodyweight') {
        colgroup = '<col style="width:28px;"><col style="width:60px;"><col><col><col style="width:44px;"><col style="width:36px;"><col style="width:44px;">';
        thead    = '<th>#</th><th>PREV</th><th>+KG</th><th>REPS</th><th>RPE</th><th></th><th></th>';
    } else {
        colgroup = '<col style="width:28px;"><col style="width:60px;"><col><col><col style="width:44px;"><col style="width:28px;"><col style="width:44px;">';
        thead    = `<th>#</th><th>PREV</th><th>${UNIT.toUpperCase()}</th><th>REPS</th><th>RPE</th><th></th><th></th>`;
    }

    const block = document.createElement('div');
    block.className = 'exercise-block mb-3';
    block.dataset.exerciseId   = ex.id;
    block.dataset.exType       = exType;
    block.dataset.category     = ex.category || '';
    block.dataset.restCategory = ex.rest_category || '';
    block.dataset.targetSets   = 3;
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
                        ${exType !== 'cardio' && ex.rest_category !== 'bodyweight' && window.forgeWarmupPresets?.[window.forgeWarmupKeys?.[ex.rest_category || '']] ? `<button class="btn-icon" onclick="openWarmupCalc(${ex.id})" type="button" title="Warmup calculator">
                            <i class="bi bi-thermometer-half" style="font-size:1rem;color:#555;"></i>
                        </button>` : ''}
                        <button class="btn-icon" onclick="moveExercise(${ex.id}, 'up')" type="button" aria-label="Move up">
                            <i class="bi bi-chevron-up" style="font-size:0.9rem;color:#555;"></i>
                        </button>
                        <button class="btn-icon" onclick="moveExercise(${ex.id}, 'down')" type="button" aria-label="Move down">
                            <i class="bi bi-chevron-down" style="font-size:0.9rem;color:#555;"></i>
                        </button>
                        <button class="btn-icon" onclick="openReplaceExercise(${ex.id})" type="button" aria-label="Replace exercise" title="Replace exercise">
                            <i class="bi bi-arrow-left-right" style="font-size:0.85rem;color:#888;"></i>
                        </button>
                        <button class="btn-icon" onclick="removeExercise(${ex.id})" type="button">
                            <i class="bi bi-x-lg" style="font-size:1rem;color:#555;"></i>
                        </button>
                    </div>
                </div>
                <div class="exercise-complete-panel d-none mb-2" aria-live="polite"></div>
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
    smartInsertExercise(block);
    hideWorkoutDoneBar();
    // Scroll to the inserted block after the modal dismiss animation completes (~300ms)
    setTimeout(() => requestAnimationFrame(() => scrollToBlock(block, 'start')), 300);

    const orderNum = [...document.querySelectorAll('.exercise-block')].indexOf(block);
    api('add_workout_exercise', { workout_id: WORKOUT_ID, exercise_id: ex.id, order_num: orderNum, target_sets: 3 });

    api('get_last_exercise_sets', { exercise_id: ex.id, workout_id: WORKOUT_ID })
        .then(r => {
            if (r.status === 'success') {
                prevSetsMap[ex.id] = r.data || [];
                prevDropSetsMap[ex.id] = r.drop_sets || [];
            }
            addSet(ex.id);
        });
}

// Clear search when modal opens
document.getElementById('addExerciseModal').addEventListener('show.bs.modal', () => {
    document.getElementById('exercise-search').value = '';
    if (exerciseListLoaded) renderExerciseList(allExercises);
});
document.getElementById('addExerciseModal').addEventListener('hidden.bs.modal', () => {
    replaceExerciseId = null;
    document.getElementById('exercise-search').placeholder = 'Search exercises…';
});

// -------------------------------------------------------
// Finish workout
// -------------------------------------------------------
function openFinishModal() {
    skipRest();
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
    skipRest();
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
<script src="/forge/workout/session-clock.js?v=1"></script>
<?php endif; // end strength-only JS ?>

<!-- ── Heart Rate Monitor module (all active workouts) ───────────────────── -->
<?php if ($active): ?>
 <script>
window.forgeHrConfig = { workoutId: <?= (int)$active['id'] ?> };
</script>
<script src="/forge/workout/hr-monitor.js?v=1"></script>

<?php if ($active['type'] === 'cardio'): ?>
 <script>
window.forgeCardioConfig = { workoutId: <?= (int)$active['id'] ?>, exerciseId: <?= (int)(array_key_first($exercises) ?? 0) ?>, restingHr: <?= $profile_resting_hr ?>, maxHr: <?= $profile_max_hr ?> };
</script>
<script src="/forge/workout/cardio-session.js?v=1"></script>
<?php endif; // end cardio JS ?>

<?php else: ?>
<script>
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

function toggleCardioPicker() {
    const picker = document.getElementById('cardio-picker');
    const btn    = document.getElementById('cardio-toggle-btn');
    const open   = picker.style.display === 'none' || picker.style.display === '';
    picker.style.display = open ? 'block' : 'none';
    btn.style.background = open ? '#2a1410' : '#1e1e1e';
    if (open) picker.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
</script>
<?php endif; ?>
</body>
</html>
