<?php
/**
 * FORGE Progress API — 1RM history, PR summary, volume queries, progression suggestions
 * POST-only, JSON responses, action-dispatched via $_POST['action']
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_helper.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST only']);
    exit;
}

$db     = get_db_connection();
$active_era_id = active_training_era_id($db);
$action = $_POST['action'] ?? '';

function progress_scope(PDO $db, int $active_era_id): array {
    $requested = (string)($_POST['cycle_context'] ?? 'current');
    if ($requested === 'all') return ['', []];
    if ($requested === 'era') return [' AND w.era_id = ?', [$active_era_id]];
    if ($requested === 'current') {
        $stmt = $db->prepare('SELECT era_id, starts_at, ends_at FROM training_cycles WHERE era_id = ? ORDER BY starts_at DESC LIMIT 1');
        $stmt->execute([$active_era_id]); $cycle = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif (ctype_digit($requested)) {
        $stmt = $db->prepare('SELECT era_id, starts_at, ends_at FROM training_cycles WHERE id = ?');
        $stmt->execute([(int)$requested]); $cycle = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $cycle = null;
    }
    if (!$cycle) return [' AND w.era_id = ?', [$active_era_id]];
    $end = $cycle['ends_at'];
    if (!empty($_POST['as_of'])) {
        $requested_end = strtotime((string)$_POST['as_of']);
        if ($requested_end !== false) $end = date('Y-m-d H:i:s', min(strtotime($end), $requested_end));
    }
    return [' AND w.era_id = ? AND w.started_at >= ? AND w.started_at <= ?', [(int)$cycle['era_id'], $cycle['starts_at'], $end]];
}

try {

    // =========================================================
    // PER-EXERCISE PROGRESS
    // =========================================================

    if ($action === 'get_exercise_progress') {
        // Returns estimated 1RM over time, volume per session, weight at rep count
        $exercise_id = (int)($_POST['exercise_id'] ?? 0);
        if (!$exercise_id) throw new Exception('exercise_id required');

        $formula = 'epley';

        // 1RM over time — best e1RM per session
        // reps=1 → actual 1RM (weight_kg); reps 2-12 → Epley estimate
        $stmt = $db->prepare("
            SELECT DATE(w.started_at) as date,
                   MAX(CASE
                       WHEN ws.reps = 1 AND ws.weight_kg > 0
                       THEN ws.weight_kg
                       WHEN ws.reps > 1 AND ws.reps <= 30 AND ws.weight_kg > 0
                       THEN ws.weight_kg * (1 + ws.reps / 30.0)
                       ELSE NULL
                   END) as estimated_1rm
            FROM workout_sets ws
            JOIN workouts w ON w.id = ws.workout_id
            WHERE ws.exercise_id = ?
              AND w.finished_at IS NOT NULL
              AND ws.set_type = 'working'
            GROUP BY DATE(w.started_at)
            ORDER BY date ASC
        ");
        $stmt->execute([$exercise_id]);
        $one_rm_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Volume per session (working sets only)
        $stmt = $db->prepare("
            SELECT DATE(w.started_at) as date,
                   SUM(ws.weight_kg * ws.reps) as volume_kg
            FROM workout_sets ws
            JOIN workouts w ON w.id = ws.workout_id
            WHERE ws.exercise_id = ?
              AND w.finished_at IS NOT NULL
              AND ws.set_type = 'working'
              AND ws.weight_kg IS NOT NULL
              AND ws.reps IS NOT NULL
            GROUP BY DATE(w.started_at)
            ORDER BY date ASC
        ");
        $stmt->execute([$exercise_id]);
        $volume_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Current PR
        $pr = $db->prepare("SELECT * FROM personal_records WHERE exercise_id = ? ORDER BY estimated_1rm DESC LIMIT 1");
        $pr->execute([$exercise_id]);
        $current_pr = $pr->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'data'   => [
                'one_rm_history'  => $one_rm_history,
                'volume_history'  => $volume_history,
                'current_pr'      => $current_pr,
            ]
        ]);

    // =========================================================
    // DASHBOARD / OVERVIEW
    // =========================================================

    } elseif ($action === 'get_pr_summary') {
        // Best PR per exercise (top 10 by estimated 1RM)
        $stmt = $db->query("
            SELECT pr.*, e.name as exercise_name, e.muscle_primary
            FROM personal_records pr
            JOIN exercises e ON e.id = pr.exercise_id
            WHERE pr.id IN (
                SELECT id FROM personal_records p2
                WHERE p2.exercise_id = pr.exercise_id
                ORDER BY p2.estimated_1rm DESC
                LIMIT 1
            )
            ORDER BY pr.estimated_1rm DESC
            LIMIT 10
        ");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } elseif ($action === 'get_weekly_volume') {
        [$scope, $params] = progress_scope($db, $active_era_id);
        $stmt = $db->prepare("
            SELECT strftime('%Y-W%W', w.started_at) as week,
                   SUM(ws.weight_kg * ws.reps) as volume_kg,
                   COUNT(DISTINCT w.id) as workout_count
            FROM workout_sets ws
            JOIN workouts w ON w.id = ws.workout_id
            WHERE w.finished_at IS NOT NULL
              AND ws.set_type = 'working'
              AND ws.weight_kg IS NOT NULL
              AND ws.reps IS NOT NULL
              {$scope}
            GROUP BY week
            ORDER BY week ASC
        ");
        $stmt->execute($params);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } elseif ($action === 'get_muscle_distribution') {
        // Volume per muscle group from the last 30 days
        $stmt = $db->query("
            SELECT e.muscle_primary,
                   SUM(ws.weight_kg * ws.reps) as volume_kg,
                   COUNT(ws.id) as set_count
            FROM workout_sets ws
            JOIN exercises e ON e.id = ws.exercise_id
            JOIN workouts w ON w.id = ws.workout_id
            WHERE w.finished_at IS NOT NULL
              AND ws.set_type = 'working'
              AND ws.weight_kg IS NOT NULL
              AND ws.reps IS NOT NULL
              AND w.started_at >= date('now', '-30 days')
            GROUP BY e.muscle_primary
            ORDER BY volume_kg DESC
        ");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } elseif ($action === 'get_workout_frequency') {
        [$scope, $params] = progress_scope($db, $active_era_id);
        $stmt = $db->prepare("
            SELECT DATE(started_at) as date, COUNT(*) as count
            FROM workouts w
            WHERE finished_at IS NOT NULL
              {$scope}
            GROUP BY DATE(started_at)
            ORDER BY date ASC
        ");
        $stmt->execute($params);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } elseif ($action === 'get_recent_workouts') {
        $limit = min((int)($_POST['limit'] ?? 5), 20);
        $stmt  = $db->prepare("
            SELECT w.*,
                   COUNT(DISTINCT ws.exercise_id) as exercise_count,
                   SUM(CASE WHEN ws.set_type='working' THEN ws.weight_kg * ws.reps ELSE 0 END) as total_volume_kg
            FROM workouts w
            LEFT JOIN workout_sets ws ON ws.workout_id = w.id
            WHERE w.finished_at IS NOT NULL
            GROUP BY w.id
            ORDER BY w.started_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    // =========================================================
    // PROGRESSION SUGGESTIONS
    // =========================================================

    } elseif ($action === 'get_progression_suggestion') {
        // Returns a weight suggestion for a template exercise based on last session
        $template_exercise_id = (int)($_POST['template_exercise_id'] ?? 0);
        if (!$template_exercise_id) throw new Exception('template_exercise_id required');

        $te = $db->prepare("SELECT * FROM template_exercises WHERE id = ?");
        $te->execute([$template_exercise_id]);
        $te = $te->fetch(PDO::FETCH_ASSOC);
        if (!$te) throw new Exception('Template exercise not found');

        // Keep this endpoint aligned with the homepage and workout UI.
        // Progression rules have one source of truth in db_helper.php.
        $suggestion = compute_progression_suggestion($db, $te);
        echo json_encode(['status' => 'success', 'data' => $suggestion]);

    // =========================================================
    // CARDIO HISTORY (time-series per exercise)
    // =========================================================

    // =========================================================
    // HR METRICS (per-exercise %HRR trend + HRRec)
    // =========================================================

    } elseif ($action === 'get_exercise_hrr') {
        $exercise_id = (int)($_POST['exercise_id'] ?? 0);
        if (!$exercise_id) throw new Exception('exercise_id required');

        $resting_hr = (int)get_user_setting($db, 'profile_resting_hr', '0');
        $max_hr     = (int)get_user_setting($db, 'profile_max_hr',     '0');

        if ($resting_hr <= 0 || $max_hr <= $resting_hr) {
            echo json_encode(['status' => 'success', 'data' => [], 'reason' => 'no_profile_hr']);
            exit;
        }

        // Per-session avg %HRR across working sets that have hr_max
        $stmt = $db->prepare("
            SELECT w.id as workout_id,
                   DATE(w.started_at) as date,
                   ROUND(AVG((ws.hr_max - :resting) * 100.0 / (:max_hr - :resting)), 1) as avg_hrr_pct,
                   COUNT(ws.id) as set_count
            FROM workout_sets ws
            JOIN workouts w ON w.id = ws.workout_id
            WHERE ws.exercise_id   = :exercise_id
              AND ws.set_type      = 'working'
              AND ws.hr_max        IS NOT NULL
              AND w.finished_at    IS NOT NULL
            GROUP BY w.id
            ORDER BY w.started_at ASC
        ");
        $stmt->execute([':exercise_id' => $exercise_id, ':resting' => $resting_hr, ':max_hr' => $max_hr]);
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($sessions)) {
            echo json_encode(['status' => 'success', 'data' => [], 'resting_hr' => $resting_hr, 'max_hr' => $max_hr]);
            exit;
        }

        // HRRec: for each session, find HR at ~60s after each set completion and compute drop
        // logged_at is AEST (PHP), sampled_at is UTC (JS toISOString) — handle both
        $workout_ids = array_column($sessions, 'workout_id');
        $phs = implode(',', array_fill(0, count($workout_ids), '?'));

        $s2 = $db->prepare("
            SELECT workout_id, hr_max, logged_at
            FROM workout_sets
            WHERE workout_id IN ($phs) AND exercise_id = ?
              AND set_type = 'working' AND hr_max IS NOT NULL AND logged_at IS NOT NULL
            ORDER BY workout_id, logged_at
        ");
        $s2->execute([...$workout_ids, $exercise_id]);
        $sets_timed = $s2->fetchAll(PDO::FETCH_ASSOC);

        $s3 = $db->prepare("
            SELECT workout_id, sampled_at, bpm
            FROM workout_hr_samples WHERE workout_id IN ($phs)
            ORDER BY workout_id, sampled_at
        ");
        $s3->execute($workout_ids);

        // Group HR samples by workout_id, pre-converting to unix timestamps (UTC)
        $hr_by_wid = [];
        foreach ($s3->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $hr_by_wid[(int)$s['workout_id']][] = [
                'ts'  => strtotime($s['sampled_at'] . ' UTC'),
                'bpm' => (int)$s['bpm'],
            ];
        }

        // Group sets by workout_id
        $sets_by_wid = [];
        foreach ($sets_timed as $s) {
            $sets_by_wid[(int)$s['workout_id']][] = $s;
        }

        // Compute avg HRRec (bpm drop at 60s post-set) per session
        $hrrec_by_wid = [];
        foreach ($sets_by_wid as $wid => $wsets) {
            $samples = $hr_by_wid[$wid] ?? [];
            if (empty($samples)) continue;
            $recoveries = [];
            foreach ($wsets as $ws) {
                $target_ts = strtotime($ws['logged_at']) + 60; // AEST + 60s
                $best_bpm = null; $best_diff = PHP_INT_MAX;
                foreach ($samples as $sp) {
                    $diff = abs($sp['ts'] - $target_ts);
                    if ($diff < $best_diff) { $best_diff = $diff; $best_bpm = $sp['bpm']; }
                }
                if ($best_diff <= 20 && $best_bpm !== null) {
                    $rec = (int)$ws['hr_max'] - $best_bpm;
                    if ($rec > 0) $recoveries[] = $rec;
                }
            }
            if (!empty($recoveries)) {
                $hrrec_by_wid[$wid] = round(array_sum($recoveries) / count($recoveries), 1);
            }
        }

        foreach ($sessions as &$s) {
            $s['hrrec'] = $hrrec_by_wid[(int)$s['workout_id']] ?? null;
        }
        unset($s);

        echo json_encode(['status' => 'success', 'data' => $sessions, 'resting_hr' => $resting_hr, 'max_hr' => $max_hr]);

    } elseif ($action === 'get_cardio_history') {
        $exercise_id = (int)($_POST['exercise_id'] ?? 0);
        if (!$exercise_id) throw new Exception('exercise_id required');

        $stmt = $db->prepare("
            SELECT DATE(w.started_at) as date,
                   ws.duration_sec, ws.distance_km, ws.hr_avg, ws.hr_max, ws.intensity,
                   CASE WHEN ws.duration_sec > 0 AND ws.distance_km > 0
                        THEN ROUND(ws.distance_km / (ws.duration_sec / 3600.0), 3)
                        ELSE NULL END as avg_speed_kmh,
                   CASE WHEN ws.duration_sec > 0 AND ws.distance_km > 0 AND ws.hr_avg > 0
                        THEN ROUND(ws.distance_km / (ws.duration_sec / 3600.0) / ws.hr_avg * 1000, 3)
                        ELSE NULL END as cardiac_efficiency
            FROM workout_sets ws
            JOIN workouts w ON w.id = ws.workout_id
            WHERE ws.exercise_id = ?
              AND w.finished_at IS NOT NULL
            ORDER BY w.started_at ASC
        ");
        $stmt->execute([$exercise_id]);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    // =========================================================
    // CARDIO PRs
    // =========================================================

    } elseif ($action === 'get_cardio_prs') {
        // Best record per type, optionally filtered to one exercise
        $exercise_id = !empty($_POST['exercise_id']) ? (int)$_POST['exercise_id'] : null;

        if ($exercise_id) {
            $stmt = $db->prepare("
                SELECT cr.*, e.name as exercise_name
                FROM cardio_records cr
                JOIN exercises e ON e.id = cr.exercise_id
                WHERE cr.exercise_id = ?
                  AND cr.id = (
                      SELECT id FROM cardio_records
                      WHERE exercise_id = cr.exercise_id AND record_type = cr.record_type
                      ORDER BY value DESC LIMIT 1
                  )
                ORDER BY cr.record_type
            ");
            $stmt->execute([$exercise_id]);
        } else {
            $stmt = $db->query("
                SELECT cr.*, e.name as exercise_name
                FROM cardio_records cr
                JOIN exercises e ON e.id = cr.exercise_id
                WHERE cr.id = (
                    SELECT id FROM cardio_records c2
                    WHERE c2.exercise_id = cr.exercise_id AND c2.record_type = cr.record_type
                    ORDER BY c2.value DESC LIMIT 1
                )
                ORDER BY e.name, cr.record_type
            ");
        }
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    // =========================================================
    // PROGRESS PAGE — STRENGTH
    // =========================================================

    } elseif ($action === 'get_big4_trend') {
        // Estimated 1RM over time for each Big 4 lift
        $names = ['Barbell Back Squat', 'Barbell Bench Press', 'Deadlift', 'Overhead Press'];
        $ph    = implode(',', array_fill(0, count($names), '?'));
        $id_stmt = $db->prepare("SELECT id, name FROM exercises WHERE name IN ($ph)");
        $id_stmt->execute($names);
        $id_map = [];
        foreach ($id_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $id_map[$row['id']] = $row['name'];

        $result = [];
        [$scope_sql, $scope_params] = progress_scope($db, $active_era_id);
        foreach ($id_map as $eid => $name) {
            $stmt = $db->prepare("
                SELECT DATE(w.started_at) as date,
                       ROUND(MAX(ws.weight_kg * (1 + ws.reps / 30.0)), 1) as estimated_1rm
                FROM workout_sets ws
                JOIN workouts w ON w.id = ws.workout_id
                WHERE ws.exercise_id = ?
                  AND ws.reps > 1 AND ws.reps <= 30 AND ws.weight_kg > 0
                  AND ws.set_type = 'working' AND w.finished_at IS NOT NULL
                  {$scope_sql}
                GROUP BY DATE(w.started_at)
                ORDER BY date ASC
            ");
            $stmt->execute([$eid, ...$scope_params]);
            $result[$name] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode(['status' => 'success', 'data' => $result]);

    } elseif ($action === 'get_pr_board') {
        // All exercises with PRs, ranked by set count then 1RM
        $stmt = $db->query("
            SELECT pr.exercise_id, pr.weight_kg, pr.reps, ROUND(pr.estimated_1rm, 1) as estimated_1rm,
                   e.name as exercise_name, e.muscle_primary, e.category,
                   (SELECT COUNT(*) FROM workout_sets ws2
                    WHERE ws2.exercise_id = e.id AND ws2.set_type = 'working') as set_count
            FROM personal_records pr
            JOIN exercises e ON e.id = pr.exercise_id
            WHERE pr.id = (
                SELECT id FROM personal_records p2
                WHERE p2.exercise_id = pr.exercise_id
                ORDER BY p2.estimated_1rm DESC LIMIT 1
            )
            AND pr.estimated_1rm > 0
            ORDER BY set_count DESC, pr.estimated_1rm DESC
        ");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    // =========================================================
    // PROGRESS PAGE — CARDIO
    // =========================================================

    } elseif ($action === 'get_cardio_efficiency_trend') {
        // Cardiac efficiency over time, grouped by exercise
        [$scope_sql, $scope_params] = progress_scope($db, $active_era_id);
        $stmt = $db->prepare("
            SELECT DATE(w.started_at) as date, e.name as exercise_name,
                   ROUND(ws.distance_km / (ws.duration_sec / 3600.0) / ws.hr_avg * 1000, 3) as efficiency
            FROM workout_sets ws
            JOIN workouts w ON w.id = ws.workout_id
            JOIN exercises e ON e.id = ws.exercise_id
            WHERE w.finished_at IS NOT NULL AND e.category = 'Cardio'
              AND ws.duration_sec > 0 AND ws.distance_km > 0 AND ws.hr_avg > 0{$scope_sql}
            ORDER BY w.started_at ASC
        "); $stmt->execute($scope_params);
        $by_ex = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $by_ex[$r['exercise_name']][] = ['date' => $r['date'], 'value' => (float)$r['efficiency']];
        }
        echo json_encode(['status' => 'success', 'data' => $by_ex]);

    } elseif ($action === 'get_cardio_volume_trend') {
        // Weekly cardio km + duration for last 12 weeks
        [$scope_sql, $scope_params] = progress_scope($db, $active_era_id);
        $date_clause = $scope_sql === '' || str_contains($scope_sql, 'era_id = ?') && count($scope_params) === 1 ? " AND w.started_at >= date('now', '-84 days')" : '';
        $stmt = $db->prepare("
            SELECT strftime('%Y-W%W', w.started_at) as week,
                   ROUND(SUM(COALESCE(ws.distance_km, 0)), 2) as total_km,
                   SUM(COALESCE(ws.duration_sec, 0)) as total_sec
            FROM workout_sets ws
            JOIN workouts w ON w.id = ws.workout_id
            JOIN exercises e ON e.id = ws.exercise_id
            WHERE w.finished_at IS NOT NULL AND e.category = 'Cardio'
              {$scope_sql}{$date_clause}
            GROUP BY week
            ORDER BY week ASC
        "); $stmt->execute($scope_params);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } else {
        throw new Exception('Unknown action: ' . htmlspecialchars($action));
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
