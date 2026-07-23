<?php
/** Site-wide active workout bar. Behaviour lives in rest-alerts.js. */
$active_stmt = $db->query('SELECT id, name, started_at, template_id FROM workouts WHERE finished_at IS NULL ORDER BY started_at DESC LIMIT 1');
$active_workout = $active_stmt->fetch(PDO::FETCH_ASSOC);
if (!$active_workout) return;

$on_workout_page = ($page_context ?? '') === 'workout';
$progress = $db->prepare("
    SELECT we.exercise_id, we.order_num, e.name AS exercise_name,
           COALESCE(we.target_sets, te.target_sets, 3) AS target_sets,
           te.target_reps_min, te.target_reps_max, COUNT(ws.id) AS logged_sets, MAX(ws.id) AS last_set_id,
           COALESCE((SELECT weight_kg FROM workout_sets WHERE exercise_id=we.exercise_id AND workout_id=we.workout_id AND set_type='working' AND weight_kg>0 ORDER BY id DESC LIMIT 1),
                    (SELECT ws3.weight_kg FROM workout_sets ws3 JOIN workouts w3 ON w3.id=ws3.workout_id WHERE ws3.exercise_id=we.exercise_id AND ws3.set_type='working' AND ws3.weight_kg>0 AND w3.finished_at IS NOT NULL ORDER BY ws3.id DESC LIMIT 1)) AS current_weight
    FROM workout_exercises we JOIN exercises e ON e.id=we.exercise_id
    LEFT JOIN template_exercises te ON te.exercise_id=we.exercise_id AND te.template_id=?
    LEFT JOIN workout_sets ws ON ws.exercise_id=we.exercise_id AND ws.workout_id=we.workout_id AND ws.set_type='working'
    WHERE we.workout_id=? GROUP BY we.exercise_id, we.order_num ORDER BY we.order_num
");
$progress->execute([$active_workout['template_id'], $active_workout['id']]);
$rows = $progress->fetchAll(PDO::FETCH_ASSOC);
$next = null; $last = null;
foreach ($rows as $row) if ($row['logged_sets'] > 0 && (!$last || $row['last_set_id'] > $last['last_set_id'])) $last = $row;
if ($last && $last['logged_sets'] < $last['target_sets']) $next = $last;
if (!$next) foreach ($rows as $row) if ($row['logged_sets'] < $row['target_sets']) { $next = $row; break; }
$all_done = !empty($rows) && !$next;
if ($next) {
    $next['set_num'] = (int)$next['logged_sets'] + 1;
    $reps = (int)($next['target_reps_min'] ?? 0);
    $max_reps = (int)($next['target_reps_max'] ?? 0);
    $detail = array_filter([$next['current_weight'] ? $next['current_weight'].' kg' : '', $reps ? '× '.($max_reps && $max_reps !== $reps ? "$reps–$max_reps" : $reps) : '']);
}
?>
<div id="active-bar" class="active-bar" style="position:fixed;bottom:0;left:0;right:0;z-index:1040;background:#0a0a0a;border-top:2px solid #FF5722;padding:8px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:58px;">
  <div style="min-width:0;flex-shrink:1;overflow:hidden;">
    <?php if ($all_done): ?>
      <div id="ab-next-label" style="font-size:.52rem;font-weight:900;color:#4caf50;">ALL SETS DONE</div><div id="ab-next-name" style="font-size:.8rem;font-weight:900;">Finish workout?</div>
    <?php elseif ($next): ?>
      <div id="ab-next-label" style="font-size:.52rem;font-weight:900;color:#FF5722;">NEXT UP · SET <?= $next['set_num'] ?> OF <?= (int)$next['target_sets'] ?></div>
      <div id="ab-next-name" style="font-size:.8rem;font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($next['exercise_name']) ?><?php if ($detail): ?><span style="color:#888;font-weight:400;font-size:.72rem;margin-left:6px;"><?= htmlspecialchars(implode('  ', $detail)) ?></span><?php endif; ?></div>
    <?php else: ?>
      <div id="ab-next-label" style="font-size:.52rem;font-weight:900;color:#FF5722;">ACTIVE</div><div id="ab-next-name" style="font-size:.8rem;font-weight:900;"><?= htmlspecialchars($active_workout['name'] ?: 'Workout') ?></div>
    <?php endif; ?><div id="ab-elapsed" style="font-size:.62rem;color:#666;font-weight:700;"></div>
  </div>
  <div id="ab-timer" style="display:none;flex-shrink:0;text-align:center;"><div style="font-size:.52rem;font-weight:900;color:#666;">REST</div><div id="ab-rest-display" style="font-size:1.6rem;font-weight:900;font-family:monospace;color:#FF5722;">0:00</div></div>
  <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
    <div id="ab-timer-btns" style="display:none;align-items:center;gap:6px;"><button onclick="addRestTime(-30)" style="background:#1e1e1e;border:1px solid #333;color:#888;min-height:40px;padding:0 10px;font-weight:700;font-size:.75rem;border-radius:4px;">−30s</button><button onclick="addRestTime(30)" style="background:#1e1e1e;border:1px solid #333;color:#e0e0e0;min-height:40px;padding:0 10px;font-weight:700;font-size:.75rem;border-radius:4px;">+30s</button><button onclick="skipRest()" style="background:#1e1e1e;border:1px solid #555;color:#888;min-height:40px;padding:0 10px;font-weight:700;font-size:.75rem;border-radius:4px;">SKIP</button></div>
    <?php if (!$on_workout_page): ?><a id="ab-continue-btn" href="/forge/workout/" style="display:<?= $all_done ? 'none' : 'flex' ?>;background:#FF5722;color:#fff;border-radius:6px;font-weight:900;font-size:.75rem;padding:10px 14px;text-decoration:none;min-height:40px;align-items:center;">CONTINUE</a><a id="ab-done-btn" href="/forge/workout/" style="display:<?= $all_done ? 'flex' : 'none' ?>;background:#4caf50;color:#fff;border-radius:6px;font-weight:900;font-size:.75rem;padding:10px 14px;text-decoration:none;min-height:40px;align-items:center;">FINISH</a><?php else: ?><button id="ab-done-btn" onclick="openFinishModal()" style="display:<?= $all_done ? 'flex' : 'none' ?>;background:#4caf50;color:#fff;border:0;border-radius:6px;font-weight:900;font-size:.75rem;padding:10px 14px;min-height:40px;align-items:center;">FINISH</button><?php endif; ?>
  </div>
</div>
<script>window.forgeRestAlertConfig={startedMs:<?= (int)strtotime($active_workout['started_at']) ?>*1000};</script>
<script src="/forge/core/rest-alerts.js?v=2"></script>
