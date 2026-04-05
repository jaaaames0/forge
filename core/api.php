<?php
/**
 * FORGE Core API — CRUD for exercises, workouts, sets, templates
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
$action = $_POST['action'] ?? '';

try {

    // =========================================================
    // EXERCISES
    // =========================================================

    if ($action === 'list_exercises') {
        $stmt = $db->query("SELECT * FROM exercises ORDER BY muscle_primary, name");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } elseif ($action === 'add_exercise') {
        $name     = trim($_POST['name'] ?? '');
        $category = $_POST['category'] ?? 'Other';
        $primary  = $_POST['muscle_primary'] ?? null;
        $secondary = $_POST['muscle_secondary'] ?? null;
        $notes    = $_POST['notes'] ?? null;
        if (!$name) throw new Exception('Name is required');

        $stmt = $db->prepare("INSERT INTO exercises (name, category, muscle_primary, muscle_secondary, is_custom, notes) VALUES (?, ?, ?, ?, 1, ?)");
        $stmt->execute([$name, $category, $primary, $secondary, $notes]);
        $id = $db->lastInsertId();
        $ex = $db->prepare("SELECT * FROM exercises WHERE id = ?");
        $ex->execute([$id]);
        echo json_encode(['status' => 'success', 'data' => $ex->fetch(PDO::FETCH_ASSOC)]);

    } elseif ($action === 'update_exercise') {
        $id        = (int)($_POST['id'] ?? 0);
        $name      = trim($_POST['name'] ?? '');
        $category  = $_POST['category'] ?? 'Other';
        $primary   = $_POST['muscle_primary'] ?? null;
        $secondary = $_POST['muscle_secondary'] ?? null;
        $notes     = $_POST['notes'] ?? null;
        if (!$id || !$name) throw new Exception('ID and name required');

        $db->prepare("UPDATE exercises SET name=?, category=?, muscle_primary=?, muscle_secondary=?, notes=? WHERE id=? AND is_custom=1")
           ->execute([$name, $category, $primary, $secondary, $notes, $id]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'delete_exercise') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('ID required');
        $db->prepare("DELETE FROM exercises WHERE id=? AND is_custom=1")->execute([$id]);
        echo json_encode(['status' => 'success']);

    // =========================================================
    // WORKOUTS
    // =========================================================

    } elseif ($action === 'start_workout') {
        // Return existing in-progress workout if one exists, else create
        $existing = $db->query("SELECT * FROM workouts WHERE finished_at IS NULL ORDER BY started_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            echo json_encode(['status' => 'success', 'data' => $existing, 'resumed' => true]);
        } else {
            $template_id = $_POST['template_id'] ? (int)$_POST['template_id'] : null;
            $name        = trim($_POST['name'] ?? '') ?: null;

            // If starting from a template, auto-name from template
            if ($template_id && !$name) {
                $t = $db->prepare("SELECT name FROM templates WHERE id = ?");
                $t->execute([$template_id]);
                $tname = $t->fetchColumn();
                if ($tname) $name = $tname;
            }

            $db->prepare("INSERT INTO workouts (name, template_id, started_at) VALUES (?, ?, ?)")
               ->execute([$name, $template_id, date('Y-m-d H:i:s')]);
            $id = $db->lastInsertId();
            $wo = $db->prepare("SELECT * FROM workouts WHERE id = ?");
            $wo->execute([$id]);
            echo json_encode(['status' => 'success', 'data' => $wo->fetch(PDO::FETCH_ASSOC), 'resumed' => false]);
        }

    } elseif ($action === 'finish_workout') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('ID required');
        $notes      = $_POST['notes'] ?? null;
        $bodyweight = $_POST['bodyweight_kg'] ? (float)$_POST['bodyweight_kg'] : null;

        $db->prepare("UPDATE workouts SET finished_at=?, notes=?, bodyweight_kg=? WHERE id=? AND finished_at IS NULL")
           ->execute([date('Y-m-d H:i:s'), $notes, $bodyweight, $id]);

        // Cache bodyweight for future effective-weight calculations
        if ($bodyweight) {
            set_user_setting($db, 'bodyweight_kg', (string)$bodyweight);
        }

        // Commit PRs for all sets in this workout (strength + cardio)
        $formula = 'epley';
        $sets = $db->prepare("
            SELECT ws.exercise_id, ws.weight_kg, ws.reps, ws.duration_sec,
                   ws.distance_km, ws.hr_avg, ws.intensity, e.category
            FROM workout_sets ws
            JOIN exercises e ON e.id = ws.exercise_id
            WHERE ws.workout_id = ? AND ws.set_type = 'working'
        ");
        $sets->execute([$id]);
        foreach ($sets->fetchAll(PDO::FETCH_ASSOC) as $s) {
            if ($s['category'] === 'Cardio') {
                maybe_update_cardio_pr($db, (int)$s['exercise_id'],
                    $s['duration_sec'] ? (int)$s['duration_sec'] : null,
                    $s['distance_km']  ? (float)$s['distance_km'] : null,
                    $s['hr_avg']       ? (int)$s['hr_avg'] : null,
                    $s['intensity']    ? (int)$s['intensity'] : null);
            } elseif ($s['weight_kg'] !== null && $s['reps'] !== null) {
                maybe_update_pr($db, (int)$s['exercise_id'], (float)$s['weight_kg'], (int)$s['reps'], $formula);
            }
        }

        echo json_encode(['status' => 'success']);

    } elseif ($action === 'update_workout') {
        $id         = (int)($_POST['id'] ?? 0);
        $name       = trim($_POST['name'] ?? '') ?: null;
        $notes      = $_POST['notes'] ?? null;
        $bodyweight = $_POST['bodyweight_kg'] ? (float)$_POST['bodyweight_kg'] : null;
        if (!$id) throw new Exception('ID required');

        $db->prepare("UPDATE workouts SET name=?, notes=?, bodyweight_kg=? WHERE id=?")
           ->execute([$name, $notes, $bodyweight, $id]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'delete_workout') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('ID required');
        $db->prepare("DELETE FROM workouts WHERE id=?")->execute([$id]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'list_workouts') {
        $limit  = min((int)($_POST['limit'] ?? 20), 100);
        $offset = (int)($_POST['offset'] ?? 0);
        $stmt = $db->prepare("
            SELECT w.*,
                   COUNT(DISTINCT ws.exercise_id) as exercise_count,
                   COUNT(ws.id) as set_count,
                   SUM(CASE WHEN ws.set_type='working' THEN ws.weight_kg * ws.reps ELSE 0 END) as total_volume_kg
            FROM workouts w
            LEFT JOIN workout_sets ws ON ws.workout_id = w.id
            WHERE w.finished_at IS NOT NULL
            GROUP BY w.id
            ORDER BY w.started_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } elseif ($action === 'get_workout') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('ID required');
        $wo = $db->prepare("SELECT * FROM workouts WHERE id = ?");
        $wo->execute([$id]);
        $workout = $wo->fetch(PDO::FETCH_ASSOC);
        if (!$workout) throw new Exception('Workout not found');
        echo json_encode(['status' => 'success', 'data' => $workout]);

    // =========================================================
    // SETS
    // =========================================================

    } elseif ($action === 'get_workout_view') {
        // Returns sets for a workout grouped by exercise, with previous-session data
        $workout_id = (int)($_POST['workout_id'] ?? 0);
        if (!$workout_id) throw new Exception('workout_id required');

        // Current sets
        $stmt = $db->prepare("
            SELECT ws.*, e.name as exercise_name, e.category, e.muscle_primary, e.bw_factor
            FROM workout_sets ws
            JOIN exercises e ON e.id = ws.exercise_id
            WHERE ws.workout_id = ?
            ORDER BY ws.exercise_id, ws.set_number
        ");
        $stmt->execute([$workout_id]);
        $sets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by exercise
        $exercises = [];
        foreach ($sets as $s) {
            $eid = $s['exercise_id'];
            if (!isset($exercises[$eid])) {
                $exercises[$eid] = [
                    'exercise_id'    => $eid,
                    'exercise_name'  => $s['exercise_name'],
                    'category'       => $s['category'],
                    'muscle_primary' => $s['muscle_primary'],
                    'sets'           => [],
                    'previous_sets'  => [],
                ];
            }
            $exercises[$eid]['sets'][] = $s;
        }

        // For each exercise, fetch previous session sets
        foreach (array_keys($exercises) as $eid) {
            $prev = $db->prepare("
                SELECT ws.set_number, ws.weight_kg, ws.added_weight_kg, ws.reps, ws.rpe,
                       ws.set_type, ws.duration_sec, ws.distance_km, ws.hr_avg, ws.hr_max, ws.intensity
                FROM workout_sets ws
                JOIN workouts w ON w.id = ws.workout_id
                WHERE ws.exercise_id = ?
                  AND w.finished_at IS NOT NULL
                  AND w.id != ?
                ORDER BY w.finished_at DESC, ws.set_number ASC
                LIMIT 20
            ");
            $prev->execute([$eid, $workout_id]);
            $exercises[$eid]['previous_sets'] = $prev->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode(['status' => 'success', 'data' => array_values($exercises)]);

    } elseif ($action === 'save_set') {
        $workout_id  = (int)($_POST['workout_id'] ?? 0);
        $exercise_id = (int)($_POST['exercise_id'] ?? 0);
        $set_id      = (int)($_POST['set_id'] ?? 0);
        $set_number  = (int)($_POST['set_number'] ?? 1);
        $set_type    = $_POST['set_type'] ?? 'working';
        $weight_kg   = (isset($_POST['weight_kg']) && $_POST['weight_kg'] !== '') ? (float)$_POST['weight_kg'] : null;
        $reps        = (isset($_POST['reps'])      && $_POST['reps']      !== '') ? (int)$_POST['reps']        : null;
        $rpe         = (isset($_POST['rpe'])       && $_POST['rpe']       !== '') ? (float)$_POST['rpe']       : null;
        $duration    = (isset($_POST['duration_sec']) && $_POST['duration_sec'] !== '') ? (int)$_POST['duration_sec']    : null;
        $distance    = (isset($_POST['distance_km'])  && $_POST['distance_km']  !== '') ? (float)$_POST['distance_km']   : null;
        $hr_avg      = (isset($_POST['hr_avg'])    && $_POST['hr_avg']    !== '') ? (int)$_POST['hr_avg']      : null;
        $hr_max      = (isset($_POST['hr_max'])    && $_POST['hr_max']    !== '') ? (int)$_POST['hr_max']      : null;
        $intensity   = (isset($_POST['intensity']) && $_POST['intensity'] !== '') ? (int)$_POST['intensity']   : null;
        $notes       = $_POST['notes'] ?? null;

        if (!$workout_id || !$exercise_id) throw new Exception('workout_id and exercise_id required');

        // Look up exercise type to handle BW effective-weight calculation
        $ex_info = $db->prepare("SELECT category, bw_factor FROM exercises WHERE id = ?");
        $ex_info->execute([$exercise_id]);
        $ex      = $ex_info->fetch(PDO::FETCH_ASSOC);
        $is_cardio    = $ex && $ex['category'] === 'Cardio';
        $bw_factor    = ($ex && $ex['bw_factor'] !== null) ? (float)$ex['bw_factor'] : null;
        $is_bodyweight = $bw_factor !== null;
        $added_weight_kg = null;

        if ($is_cardio) {
            // Cardio: no weight or reps
            $weight_kg = null;
            $reps      = null;
        } elseif ($is_bodyweight) {
            // BW: user sent added weight; compute effective = BW*factor + added
            $added = $weight_kg ?? 0.0;
            $bw    = (float)get_user_setting($db, 'bodyweight_kg', '70');
            $effective = $bw_factor * $bw + $added;
            $added_weight_kg = $added;
            $weight_kg       = $effective > 0 ? $effective : null;
        }

        // Strength/bodyweight sets must include reps.
        if (!$is_cardio && ($reps === null || $reps <= 0)) {
            throw new Exception('Reps are required');
        }

        // Compute is_pr BEFORE insert/update so it can be stored on the row.
        // For strength: compare new estimated 1RM against both the all-time record AND
        // the best 1RM already logged in this session. This prevents re-flagging the same
        // weight in the same session and stops inferior sets from inheriting the PR badge.
        $is_pr   = false;
        $new_prs = [];
        if ($is_cardio) {
            $avg_speed  = ($duration > 0 && $distance > 0) ? round($distance / ($duration / 3600.0), 4) : null;
            $efficiency = ($avg_speed !== null && $hr_avg > 0) ? round($avg_speed / $hr_avg * 1000, 4) : null;
            $metrics = array_filter([
                'avg_speed'          => $avg_speed,
                'cardiac_efficiency' => $efficiency,
                'best_distance'      => $distance > 0 ? $distance : null,
            ]);
            $chk = $db->prepare("SELECT value FROM cardio_records WHERE exercise_id=? AND record_type=? ORDER BY value DESC LIMIT 1");
            foreach ($metrics as $type => $value) {
                $chk->execute([$exercise_id, $type]);
                $curr = $chk->fetchColumn();
                if ($curr === false || $value > (float)$curr) { $new_prs[] = $type; }
            }
            $is_pr = !empty($new_prs);
        } elseif ($set_type === 'working' && $weight_kg !== null && $reps !== null) {
            $new_1rm = estimate_1rm($weight_kg, $reps, 'epley');
            if ($new_1rm !== null) {
                // All-time record
                $chk = $db->prepare("SELECT estimated_1rm FROM personal_records WHERE exercise_id=? ORDER BY estimated_1rm DESC LIMIT 1");
                $chk->execute([$exercise_id]);
                $all_time_pr = $chk->fetchColumn();

                // Best estimated 1RM already saved in the current session (excluding this set).
                // $set_id is 0 for new inserts (so id != 0 excludes nothing — the new row
                // isn't in the DB yet). For updates, $set_id excludes the current row.
                $chk2 = $db->prepare("
                    SELECT MAX(weight_kg * (1.0 + reps / 30.0))
                    FROM workout_sets
                    WHERE exercise_id = ? AND workout_id = ? AND id != ?
                      AND set_type = 'working'
                      AND weight_kg IS NOT NULL AND reps IS NOT NULL
                      AND reps > 1 AND reps <= 12
                ");
                $chk2->execute([$exercise_id, $workout_id, $set_id ?: 0]);
                $session_best = $chk2->fetchColumn();

                $all_time  = $all_time_pr !== false ? (float)$all_time_pr : 0.0;
                $in_session = ($session_best !== false && $session_best !== null) ? (float)$session_best : 0.0;
                $best = max($all_time, $in_session);
                $is_pr = ($best == 0.0 || $new_1rm > $best);
            }
        }

        $pr_int = $is_pr ? 1 : 0;
        if ($set_id) {
            $db->prepare("UPDATE workout_sets SET set_number=?, set_type=?, weight_kg=?, reps=?, rpe=?, duration_sec=?, distance_km=?, added_weight_kg=?, hr_avg=?, hr_max=?, intensity=?, notes=?, is_pr=? WHERE id=? AND workout_id=?")
               ->execute([$set_number, $set_type, $weight_kg, $reps, $rpe, $duration, $distance, $added_weight_kg, $hr_avg, $hr_max, $intensity, $notes, $pr_int, $set_id, $workout_id]);
        } else {
            $db->prepare("INSERT INTO workout_sets (workout_id, exercise_id, set_number, set_type, weight_kg, reps, rpe, duration_sec, distance_km, added_weight_kg, hr_avg, hr_max, intensity, notes, is_pr) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$workout_id, $exercise_id, $set_number, $set_type, $weight_kg, $reps, $rpe, $duration, $distance, $added_weight_kg, $hr_avg, $hr_max, $intensity, $notes, $pr_int]);
            $set_id = (int)$db->lastInsertId();
        }

        $row = $db->prepare("SELECT * FROM workout_sets WHERE id = ?");
        $row->execute([$set_id]);
        echo json_encode(['status' => 'success', 'data' => $row->fetch(PDO::FETCH_ASSOC), 'is_pr' => $is_pr, 'new_prs' => $new_prs]);

    } elseif ($action === 'add_workout_exercise') {
        $workout_id  = (int)($_POST['workout_id']  ?? 0);
        $exercise_id = (int)($_POST['exercise_id'] ?? 0);
        $order_num   = (int)($_POST['order_num']   ?? 0);
        $target_sets = max(1, (int)($_POST['target_sets'] ?? 3));
        if (!$workout_id || !$exercise_id) throw new Exception('workout_id and exercise_id required');
        $db->prepare("
            INSERT INTO workout_exercises (workout_id, exercise_id, order_num, target_sets)
            VALUES (?, ?, ?, ?)
            ON CONFLICT(workout_id, exercise_id)
            DO UPDATE SET order_num=excluded.order_num, target_sets=COALESCE(workout_exercises.target_sets, excluded.target_sets)
        ")->execute([$workout_id, $exercise_id, $order_num, $target_sets]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'update_workout_exercise_target_sets') {
        $workout_id  = (int)($_POST['workout_id']  ?? 0);
        $exercise_id = (int)($_POST['exercise_id'] ?? 0);
        $target_sets = max(1, (int)($_POST['target_sets'] ?? 3));
        if (!$workout_id || !$exercise_id) throw new Exception('workout_id and exercise_id required');
        $db->prepare("UPDATE workout_exercises SET target_sets=? WHERE workout_id=? AND exercise_id=?")
           ->execute([$target_sets, $workout_id, $exercise_id]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'remove_workout_exercise') {
        $workout_id  = (int)($_POST['workout_id']  ?? 0);
        $exercise_id = (int)($_POST['exercise_id'] ?? 0);
        if (!$workout_id || !$exercise_id) throw new Exception('workout_id and exercise_id required');
        $db->prepare("DELETE FROM workout_exercises WHERE workout_id=? AND exercise_id=?")
           ->execute([$workout_id, $exercise_id]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'delete_set') {
        $set_id    = (int)($_POST['set_id'] ?? 0);
        $workout_id = (int)($_POST['workout_id'] ?? 0);
        if (!$set_id) throw new Exception('set_id required');
        $db->prepare("DELETE FROM workout_sets WHERE id=? AND workout_id=?")->execute([$set_id, $workout_id]);
        echo json_encode(['status' => 'success']);

    // =========================================================
    // TEMPLATES
    // =========================================================

    } elseif ($action === 'list_templates') {
        $stmt = $db->query("
            SELECT t.*, COUNT(te.id) as exercise_count
            FROM templates t
            LEFT JOIN template_exercises te ON te.template_id = t.id
            GROUP BY t.id
            ORDER BY t.name
        ");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } elseif ($action === 'get_template') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('ID required');
        $t = $db->prepare("SELECT * FROM templates WHERE id = ?");
        $t->execute([$id]);
        $template = $t->fetch(PDO::FETCH_ASSOC);
        if (!$template) throw new Exception('Template not found');

        $te = $db->prepare("
            SELECT te.*, e.name as exercise_name, e.category, e.muscle_primary
            FROM template_exercises te
            JOIN exercises e ON e.id = te.exercise_id
            WHERE te.template_id = ?
            ORDER BY te.order_num
        ");
        $te->execute([$id]);
        $template['exercises'] = $te->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $template]);

    } elseif ($action === 'add_template') {
        $name  = trim($_POST['name'] ?? '');
        $notes = $_POST['notes'] ?? null;
        if (!$name) throw new Exception('Name required');
        $db->prepare("INSERT INTO templates (name, notes) VALUES (?, ?)")->execute([$name, $notes]);
        $id = $db->lastInsertId();
        $t = $db->prepare("SELECT * FROM templates WHERE id = ?");
        $t->execute([$id]);
        echo json_encode(['status' => 'success', 'data' => $t->fetch(PDO::FETCH_ASSOC)]);

    } elseif ($action === 'update_template') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $notes = $_POST['notes'] ?? null;
        if (!$id || !$name) throw new Exception('ID and name required');
        $db->prepare("UPDATE templates SET name=?, notes=? WHERE id=?")->execute([$name, $notes, $id]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'delete_template') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('ID required');
        $active_use = $db->prepare("SELECT COUNT(*) FROM workouts WHERE template_id = ? AND finished_at IS NULL");
        $active_use->execute([$id]);
        if ((int)$active_use->fetchColumn() > 0) {
            throw new Exception('Finish/cancel the active workout using this template first');
        }
        // Keep historical workouts intact: detach template references first.
        $db->prepare("UPDATE workouts SET template_id = NULL WHERE template_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM templates WHERE id=?")->execute([$id]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'add_template_exercise') {
        $template_id  = (int)($_POST['template_id'] ?? 0);
        $exercise_id  = (int)($_POST['exercise_id'] ?? 0);
        $sets         = $_POST['target_sets'] ? (int)$_POST['target_sets'] : null;
        $reps_min     = $_POST['target_reps_min'] ? (int)$_POST['target_reps_min'] : null;
        $reps_max     = $_POST['target_reps_max'] ? (int)$_POST['target_reps_max'] : null;
        $rpe          = $_POST['target_rpe'] ? (float)$_POST['target_rpe'] : null;
        $scheme       = $_POST['progression_scheme'] ?? 'double';
        $increment    = $_POST['progression_increment_kg'] ? (float)$_POST['progression_increment_kg'] : 2.5;
        if (!$template_id || !$exercise_id) throw new Exception('template_id and exercise_id required');

        // Append at end
        $order = $db->prepare("SELECT COALESCE(MAX(order_num), 0) + 1 FROM template_exercises WHERE template_id = ?");
        $order->execute([$template_id]);
        $order_num = (int)$order->fetchColumn();

        $db->prepare("INSERT INTO template_exercises (template_id, exercise_id, order_num, target_sets, target_reps_min, target_reps_max, target_rpe, progression_scheme, progression_increment_kg) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$template_id, $exercise_id, $order_num, $sets, $reps_min, $reps_max, $rpe, $scheme, $increment]);
        echo json_encode(['status' => 'success', 'data' => ['id' => (int)$db->lastInsertId()]]);

    } elseif ($action === 'update_template_exercise') {
        $id       = (int)($_POST['id'] ?? 0);
        $sets     = $_POST['target_sets'] ? (int)$_POST['target_sets'] : null;
        $reps_min = $_POST['target_reps_min'] ? (int)$_POST['target_reps_min'] : null;
        $reps_max = $_POST['target_reps_max'] ? (int)$_POST['target_reps_max'] : null;
        $rpe      = $_POST['target_rpe'] ? (float)$_POST['target_rpe'] : null;
        $scheme   = $_POST['progression_scheme'] ?? 'double';
        $increment = $_POST['progression_increment_kg'] ? (float)$_POST['progression_increment_kg'] : 2.5;
        $ab_group = isset($_POST['ab_group']) && in_array($_POST['ab_group'], ['A', 'B']) ? $_POST['ab_group'] : null;
        if (!$id) throw new Exception('ID required');

        $db->prepare("UPDATE template_exercises SET target_sets=?, target_reps_min=?, target_reps_max=?, target_rpe=?, progression_scheme=?, progression_increment_kg=?, ab_group=? WHERE id=?")
           ->execute([$sets, $reps_min, $reps_max, $rpe, $scheme, $increment, $ab_group, $id]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'remove_template_exercise') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('ID required');
        $db->prepare("DELETE FROM template_exercises WHERE id=?")->execute([$id]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'reorder_template_exercises') {
        // Expects $_POST['order'] as JSON array of ids in new order
        $order = json_decode($_POST['order'] ?? '[]', true);
        if (!is_array($order)) throw new Exception('order must be a JSON array of ids');
        $stmt = $db->prepare("UPDATE template_exercises SET order_num=? WHERE id=?");
        foreach ($order as $i => $id) {
            $stmt->execute([$i + 1, (int)$id]);
        }
        echo json_encode(['status' => 'success']);

    // =========================================================
    // SETTINGS
    // =========================================================

    } elseif ($action === 'get_settings') {
        $stmt = $db->query("SELECT key, value FROM user_settings");
        $settings = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[$row['key']] = $row['value'];
        }
        echo json_encode(['status' => 'success', 'data' => $settings]);

    } elseif ($action === 'update_setting') {
        $key   = $_POST['key'] ?? '';
        $value = $_POST['value'] ?? '';
        $allowed = ['rest_compound_big4_sec', 'rest_compound_sec', 'rest_bodyweight_sec', 'rest_isolation_sec',
                    'profile_name', 'profile_sex', 'profile_dob', 'profile_height_cm',
                    'profile_resting_hr', 'profile_max_hr'];
        if (!in_array($key, $allowed)) throw new Exception('Unknown setting key');
        set_user_setting($db, $key, $value);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'save_body_measurement') {
        $waist     = $_POST['waist_cm']       ? (float)$_POST['waist_cm']       : null;
        $neck      = $_POST['neck_cm']        ? (float)$_POST['neck_cm']        : null;
        $hip       = $_POST['hip_cm']         ? (float)$_POST['hip_cm']         : null;
        $caliper   = $_POST['caliper_bf_pct'] ? (float)$_POST['caliper_bf_pct'] : null;
        $measured_at = date('Y-m-d H:i:s');
        if (!$waist && !$neck && !$hip && !$caliper) throw new Exception('At least one measurement required');
        $db->prepare("INSERT INTO body_measurements (measured_at, waist_cm, neck_cm, hip_cm, caliper_bf_pct) VALUES (?,?,?,?,?)")
           ->execute([$measured_at, $waist, $neck, $hip, $caliper]);
        echo json_encode(['status' => 'success', 'data' => ['id' => (int)$db->lastInsertId()]]);

    } elseif ($action === 'delete_body_measurement') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('id required');
        $db->prepare("DELETE FROM body_measurements WHERE id = ?")->execute([$id]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'get_last_exercise_sets') {
        // Previous working sets for one exercise from the most recent finished workout
        $exercise_id = (int)($_POST['exercise_id'] ?? 0);
        $workout_id  = (int)($_POST['workout_id'] ?? 0); // current workout to exclude
        if (!$exercise_id) throw new Exception('exercise_id required');

        $stmt = $db->prepare("
            SELECT ws.set_number, ws.weight_kg, ws.added_weight_kg, ws.reps, ws.rpe,
                   ws.set_type, ws.duration_sec, ws.distance_km, ws.hr_avg, ws.hr_max, ws.intensity
            FROM workout_sets ws
            JOIN workouts w ON w.id = ws.workout_id
            WHERE ws.exercise_id = ?
              AND ws.set_type = 'working'
              AND w.finished_at IS NOT NULL
              AND (? = 0 OR w.id != ?)
            ORDER BY w.finished_at DESC, ws.set_number ASC
            LIMIT 10
        ");
        $stmt->execute([$exercise_id, $workout_id, $workout_id]);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } else {
        throw new Exception('Unknown action: ' . htmlspecialchars($action));
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
