<?php
/**
 * Site-wide active workout bar.
 * Included from page_head.php. Requires $db and $page_context to be set.
 * Shows on ALL pages when an active workout exists (including the workout page).
 * On the workout page, the CONTINUE button is hidden since the user is already there.
 * Rest timer persists across pages via localStorage (key: forge_rest_end).
 * Exposes global JS: startRestTimer(seconds), skipRest(), addRestTime(seconds)
 */
$_ab_stmt = $db->query("SELECT id, name, started_at, template_id FROM workouts WHERE finished_at IS NULL ORDER BY started_at DESC LIMIT 1");
$_ab_wkt  = $_ab_stmt->fetch(PDO::FETCH_ASSOC);
if (!$_ab_wkt) return;

$_ab_started = strtotime($_ab_wkt['started_at']);
$_ab_wkt_id  = (int)$_ab_wkt['id'];
$_ab_on_workout_page = (($page_context ?? '') === 'workout');

// Compute "next up": exercise with remaining sets, following the most recently logged
$_ab_next = null;
$_ab_all_done = false;

$_ab_prog = $db->prepare("
    SELECT we.exercise_id, e.name as exercise_name,
           COALESCE(te.target_sets, 3) as target_sets,
           te.target_reps_min, te.target_reps_max,
           COUNT(ws.id)  as logged_sets,
           MAX(ws.id)    as last_set_id,
           -- weight from most recent set in this workout, or fall back to last completed session
           COALESCE(
               (SELECT ws2.weight_kg FROM workout_sets ws2
                WHERE ws2.exercise_id = we.exercise_id AND ws2.workout_id = we.workout_id
                  AND ws2.set_type = 'working' AND ws2.weight_kg > 0
                ORDER BY ws2.id DESC LIMIT 1),
               (SELECT ws3.weight_kg FROM workout_sets ws3
                JOIN workouts w3 ON w3.id = ws3.workout_id
                WHERE ws3.exercise_id = we.exercise_id AND ws3.set_type = 'working'
                  AND ws3.weight_kg > 0 AND w3.finished_at IS NOT NULL
                ORDER BY ws3.id DESC LIMIT 1)
           ) as current_weight
    FROM workout_exercises we
    JOIN exercises e ON e.id = we.exercise_id
    LEFT JOIN template_exercises te
        ON te.exercise_id = we.exercise_id
       AND te.template_id = ?
    LEFT JOIN workout_sets ws
        ON ws.exercise_id = we.exercise_id
       AND ws.workout_id  = we.workout_id
       AND ws.set_type    = 'working'
    WHERE we.workout_id = ?
    GROUP BY we.exercise_id, we.order_num
    ORDER BY we.order_num ASC
");
$_ab_prog->execute([$_ab_wkt['template_id'], $_ab_wkt_id]);
$_ab_rows = $_ab_prog->fetchAll(PDO::FETCH_ASSOC);

if (!empty($_ab_rows)) {
    // Find most-recently active exercise (highest last_set_id among logged)
    $_ab_last = null;
    foreach ($_ab_rows as $_r) {
        if ($_r['logged_sets'] > 0 && ($_ab_last === null || (int)$_r['last_set_id'] > (int)$_ab_last['last_set_id'])) {
            $_ab_last = $_r;
        }
    }

    if ($_ab_last && (int)$_ab_last['logged_sets'] < (int)$_ab_last['target_sets']) {
        $_ab_next = $_ab_last;
        $_ab_next['set_num'] = (int)$_ab_last['logged_sets'] + 1;
    } else {
        foreach ($_ab_rows as $_r) {
            if ((int)$_r['logged_sets'] < (int)$_r['target_sets']) {
                $_ab_next = $_r;
                $_ab_next['set_num'] = (int)$_r['logged_sets'] + 1;
                break;
            }
        }
        if (!$_ab_next) $_ab_all_done = true;
    }
}
?>

<div id="active-bar" class="active-bar" style="position:fixed;bottom:0;left:0;right:0;z-index:1040;background:#0a0a0a;border-top:2px solid #FF5722;padding:8px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:58px;">

  <!-- Left: next-up exercise info -->
  <div style="min-width:0; flex-shrink:1; overflow:hidden;">
    <?php if ($_ab_all_done): ?>
      <div style="font-size:0.52rem; font-weight:900; color:#4caf50; text-transform:uppercase; letter-spacing:0.5px; line-height:1;">ALL SETS DONE</div>
      <div style="font-size:0.8rem; font-weight:900; color:#e0e0e0; line-height:1.2;">Finish workout?</div>
    <?php elseif ($_ab_next):
        $_ab_reps_min = (int)($_ab_next['target_reps_min'] ?? 0);
        $_ab_reps_max = (int)($_ab_next['target_reps_max'] ?? 0);
        $_ab_reps_str = $_ab_reps_min > 0
            ? ($_ab_reps_min === $_ab_reps_max || $_ab_reps_max === 0
                ? '× '.$_ab_reps_min
                : '× '.$_ab_reps_min.'–'.$_ab_reps_max)
            : '';
        $_ab_weight_str = $_ab_next['current_weight'] ? $_ab_next['current_weight'].' kg' : '';
        $_ab_detail = trim(implode('  ', array_filter([$_ab_weight_str, $_ab_reps_str])));
    ?>
      <div style="font-size:0.52rem; font-weight:900; color:#FF5722; text-transform:uppercase; letter-spacing:0.5px; line-height:1;">NEXT UP · SET <?= $_ab_next['set_num'] ?> OF <?= (int)$_ab_next['target_sets'] ?></div>
      <div style="font-size:0.8rem; font-weight:900; color:#e0e0e0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; line-height:1.2;">
        <?= htmlspecialchars($_ab_next['exercise_name']) ?><?php if ($_ab_detail): ?><span style="color:#888; font-weight:400; font-size:0.72rem; margin-left:6px;"><?= htmlspecialchars($_ab_detail) ?></span><?php endif; ?>
      </div>
    <?php else: ?>
      <div style="font-size:0.52rem; font-weight:900; color:#FF5722; text-transform:uppercase; letter-spacing:0.5px; line-height:1;">ACTIVE</div>
      <div style="font-size:0.8rem; font-weight:900; color:#e0e0e0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; line-height:1.2;"><?= htmlspecialchars($_ab_wkt['name'] ?: 'Workout') ?></div>
    <?php endif; ?>
    <div id="ab-elapsed" style="font-size:0.62rem; color:#666; font-weight:700; line-height:1.2;"></div>
  </div>

  <!-- Centre: rest timer (shown when active) -->
  <div id="ab-timer" style="display:none; flex-shrink:0; text-align:center;">
    <div style="font-size:0.52rem; font-weight:900; color:#666; text-transform:uppercase; letter-spacing:0.5px; line-height:1;">REST</div>
    <div id="ab-rest-display" style="font-size:1.6rem; font-weight:900; font-family:monospace; color:#FF5722; letter-spacing:-1px; line-height:1;">0:00</div>
  </div>

  <!-- Right: timer controls + continue -->
  <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
    <div id="ab-timer-btns" style="display:none; align-items:center; gap:6px;">
      <button onclick="addRestTime(-30)" style="background:#1e1e1e; border:1px solid #333; color:#888; min-height:40px; padding:0 10px; font-weight:700; font-size:0.75rem; border-radius:4px; cursor:pointer;">−30s</button>
      <button onclick="addRestTime(30)"  style="background:#1e1e1e; border:1px solid #333; color:#e0e0e0; min-height:40px; padding:0 10px; font-weight:700; font-size:0.75rem; border-radius:4px; cursor:pointer;">+30s</button>
      <button onclick="skipRest()"       style="background:#1e1e1e; border:1px solid #555; color:#888; min-height:40px; padding:0 10px; font-weight:700; font-size:0.75rem; border-radius:4px; cursor:pointer;">SKIP</button>
    </div>
    <?php if (!$_ab_on_workout_page): ?>
    <a href="/forge/workout/" style="background:#FF5722; color:#fff; border:none; border-radius:6px; font-weight:900; font-size:0.75rem; padding:10px 14px; text-decoration:none; white-space:nowrap; min-height:40px; display:flex; align-items:center; gap:4px; letter-spacing:0.3px;">
      <?= $_ab_all_done ? 'FINISH <i class="bi bi-check-circle-fill"></i>' : 'CONTINUE <i class="bi bi-arrow-right-circle-fill"></i>' ?>
    </a>
    <?php endif; ?>
  </div>
</div>

<script>
(function() {
    const TIMER_KEY = 'forge_rest_end';
    const timerEl   = document.getElementById('ab-timer');
    const btnsEl    = document.getElementById('ab-timer-btns');
    const display   = document.getElementById('ab-rest-display');
    const elapsed   = document.getElementById('ab-elapsed');
    const startedMs = <?= (int)$_ab_started ?> * 1000;
    let _iv = null;

    function updateElapsed() {
        const secs = Math.floor((Date.now() - startedMs) / 1000);
        const m = Math.floor(secs / 60), h = Math.floor(m / 60);
        if (elapsed) elapsed.textContent = h ? h + 'h ' + (m % 60) + 'm' : m + 'm';
    }
    updateElapsed();
    setInterval(updateElapsed, 15000);

    function fmtTime(ms) {
        const rem = Math.ceil(ms / 1000);
        const m = Math.floor(Math.abs(rem) / 60), s = Math.abs(rem) % 60;
        return m + ':' + String(s).padStart(2, '0');
    }

    function startTimerUI(endMs) {
        clearInterval(_iv);
        timerEl.style.display = '';
        btnsEl.style.display = 'flex';
        document.body.classList.add('workout-bar-timer');
        function tick() {
            const rem = endMs - Date.now();
            display.textContent = fmtTime(rem);
            if (rem <= 0) {
                clearInterval(_iv);
                timerEl.style.display = 'none';
                btnsEl.style.display = 'none';
                document.body.classList.remove('workout-bar-timer');
                localStorage.removeItem(TIMER_KEY);
                navigator.vibrate && navigator.vibrate([200, 100, 200]);
            }
        }
        tick();
        _iv = setInterval(tick, 250);
    }

    // Global functions — called by workout page and bar buttons
    window.startRestTimer = function(seconds) {
        const endMs = Date.now() + seconds * 1000;
        localStorage.setItem(TIMER_KEY, endMs);
        startTimerUI(endMs);
    };
    window.skipRest = function() {
        clearInterval(_iv);
        localStorage.removeItem(TIMER_KEY);
        timerEl.style.display = 'none';
        btnsEl.style.display = 'none';
        document.body.classList.remove('workout-bar-timer');
    };
    window.addRestTime = function(sec) {
        const cur = parseInt(localStorage.getItem(TIMER_KEY) || '0');
        const newEnd = Math.max(Date.now() + 1000, cur + sec * 1000);
        localStorage.setItem(TIMER_KEY, newEnd);
        clearInterval(_iv);
        startTimerUI(newEnd);
    };

    // Resume timer if page loaded mid-rest
    const saved = parseInt(localStorage.getItem(TIMER_KEY) || '0');
    if (saved > Date.now()) startTimerUI(saved);
})();
</script>
