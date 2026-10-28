<?php
/**
 * FORGE Core API — CRUD for exercises, workouts, sets, templates
 * POST-only, JSON responses, action-dispatched via $_POST['action']
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_helper.php';
require_once __DIR__ . '/cycle_analysis.php';
require_once __DIR__ . '/../scripts/analyze_training_cycles.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST only']);
    exit;
}

$db     = get_db_connection();
$stale_workouts = cleanup_stale_active_workouts($db);
$active_era_id = active_training_era_id($db);
$action = $_POST['action'] ?? '';

function nullable_positive_int(string $key): ?int {
    $raw = trim((string)($_POST[$key] ?? ''));
    if ($raw === '') return null;
    if (filter_var($raw, FILTER_VALIDATE_INT) === false || (int)$raw < 1) {
        throw new Exception("{$key} must be a positive integer");
    }
    return (int)$raw;
}

function nullable_rpe_target(): ?float {
    $raw = trim((string)($_POST['target_rpe'] ?? ''));
    if ($raw === '') return null;
    if (!is_numeric($raw) || (float)$raw < 1 || (float)$raw > 10) {
        throw new Exception('target_rpe must be between 1 and 10');
    }
    return (float)$raw;
}

function validated_template_progression(): array {
    $sets = nullable_positive_int('target_sets');
    $reps_min = nullable_positive_int('target_reps_min');
    $reps_max = nullable_positive_int('target_reps_max');
    if ($reps_min !== null && $reps_max !== null && $reps_max < $reps_min) {
        throw new Exception('target_reps_max must be at least target_reps_min');
    }
    $scheme = $_POST['progression_scheme'] ?? 'double';
    if (!in_array($scheme, ['linear', 'double', 'rpe'], true)) {
        throw new Exception('Invalid progression scheme');
    }
    $increment_raw = trim((string)($_POST['progression_increment_kg'] ?? ''));
    $increment = $increment_raw === '' ? 2.5 : (float)$increment_raw;
    if (!is_numeric($increment_raw === '' ? '2.5' : $increment_raw) || $increment <= 0) {
        throw new Exception('progression_increment_kg must be positive');
    }
    return [$sets, $reps_min, $reps_max, nullable_rpe_target(), $scheme, $increment];
}

try {

    // =========================================================
    // EXERCISES
    // =========================================================

    if ($action === 'list_exercises') {
        $stmt = $db->query("SELECT * FROM exercises ORDER BY muscle_primary, name");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } elseif ($action === 'preview_cycle_detection') {
        $proposal = forge_detect_training_cycles($db);
        $current = $db->query('SELECT era_id, cycle_type, starts_at, ends_at FROM training_cycles ORDER BY era_id, starts_at')->fetchAll(PDO::FETCH_ASSOC);
        $proposed = array_map(static fn($s) => ['era_id' => $s[0], 'cycle_type' => $s[1], 'starts_at' => $s[2], 'ends_at' => $s[3]], $proposal);
        $signature = static fn(array $cycles): array => array_map(static fn($c) => implode('|', [$c['era_id'], $c['cycle_type'], $c['starts_at'], $c['ends_at']]), $cycles);
        $current_signatures = $signature($current); $proposed_signatures = $signature($proposed);
        $removed = array_values(array_filter($current, static fn($c) => !in_array(implode('|', [$c['era_id'], $c['cycle_type'], $c['starts_at'], $c['ends_at']]), $proposed_signatures, true)));
        $added = array_values(array_filter($proposed, static fn($c) => !in_array(implode('|', [$c['era_id'], $c['cycle_type'], $c['starts_at'], $c['ends_at']]), $current_signatures, true)));
        $proposal_signature = hash('sha256', json_encode($proposed));
        echo json_encode(['status' => 'success', 'data' => [
            'current_count' => count($current), 'proposed_count' => count($proposed),
            'changed' => $current_signatures !== $proposed_signatures, 'added' => $added, 'removed' => $removed,
            'proposal_signature' => $proposal_signature,
        ]]);

    } elseif ($action === 'apply_cycle_detection') {
        $proposal = forge_detect_training_cycles($db);
        $expected_signature = (string)($_POST['proposal_signature'] ?? '');
        $actual_signature = hash('sha256', json_encode(array_map(static fn($s) => ['era_id' => $s[0], 'cycle_type' => $s[1], 'starts_at' => $s[2], 'ends_at' => $s[3]], $proposal)));
        if ($expected_signature === '' || !hash_equals($actual_signature, $expected_signature)) {
            throw new Exception('Cycle data changed after this preview. Please reanalyse before applying.');
        }
        $saved = forge_apply_detected_cycles($db, $proposal);
        echo json_encode(['status' => 'success', 'saved' => $saved]);

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
        $rest_cat  = $_POST['rest_category'] ?? null;
        $valid_rest = ['compound_big4', 'compound', 'bodyweight', 'isolation'];
        if ($rest_cat && !in_array($rest_cat, $valid_rest)) $rest_cat = null;

        $db->prepare("UPDATE exercises SET name=?, category=?, muscle_primary=?, muscle_secondary=?, rest_category=?, notes=? WHERE id=?")
           ->execute([$name, $category, $primary, $secondary, $rest_cat ?: null, $notes, $id]);
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

    } elseif ($action === 'finish_cardio') {
        // Atomically saves the cardio session set + finishes the workout.
        // hr_avg and hr_max are auto-computed from workout_hr_samples.
        $workout_id  = (int)($_POST['workout_id']  ?? 0);
        $exercise_id = (int)($_POST['exercise_id'] ?? 0);
        if (!$workout_id || !$exercise_id) throw new Exception('workout_id and exercise_id required');

        $duration  = (isset($_POST['duration_sec'])  && $_POST['duration_sec']  !== '') ? (int)$_POST['duration_sec']   : null;
        $distance  = (isset($_POST['distance_km'])   && $_POST['distance_km']   !== '') ? (float)$_POST['distance_km']  : null;
        $elevation = (isset($_POST['elevation_m'])   && $_POST['elevation_m']   !== '') ? (float)$_POST['elevation_m']  : null;
        $intensity = (isset($_POST['intensity'])     && $_POST['intensity']     !== '') ? (int)$_POST['intensity']      : null;
        $notes       = $_POST['notes'] ?? null;
        $bodyweight  = (isset($_POST['bodyweight_kg']) && $_POST['bodyweight_kg'] !== '') ? (float)$_POST['bodyweight_kg'] : null;
        if ($duration === null || $duration <= 0) throw new Exception('Duration must be positive');
        if ($distance !== null && $distance < 0) throw new Exception('Distance cannot be negative');
        if ($elevation !== null && $elevation < 0) throw new Exception('Elevation cannot be negative');
        if ($intensity !== null && ($intensity < 1 || $intensity > 10)) throw new Exception('Intensity must be between 1 and 10');

        $db->beginTransaction();
        try {
            require_active_workout($db, $workout_id);
            $exercise = $db->prepare('SELECT category FROM exercises WHERE id = ?');
            $exercise->execute([$exercise_id]);
            if ($exercise->fetchColumn() !== 'Cardio') throw new Exception('Exercise must be cardio');

            // Auto-compute avg/max HR from samples collected during this session.
            $bpm_stmt = $db->prepare("SELECT bpm FROM workout_hr_samples WHERE workout_id = ?");
            $bpm_stmt->execute([$workout_id]);
            $bpms  = array_column($bpm_stmt->fetchAll(PDO::FETCH_ASSOC), 'bpm');
            $hr_avg = !empty($bpms) ? (int)round(array_sum($bpms) / count($bpms)) : null;
            $hr_max = !empty($bpms) ? (int)max($bpms) : null;
            $now = date('Y-m-d H:i:s');

            $db->prepare("
                INSERT INTO workout_sets
                  (workout_id, exercise_id, set_number, set_type, duration_sec, distance_km,
                   elevation_m, hr_avg, hr_max, intensity, notes, logged_at)
                VALUES (?, ?, 1, 'working', ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$workout_id, $exercise_id, $duration, $distance, $elevation, $hr_avg, $hr_max, $intensity, $notes, $now]);
            $db->prepare("UPDATE workouts SET finished_at = ?, bodyweight_kg = ? WHERE id = ?")
               ->execute([$now, $bodyweight, $workout_id]);
            if ($bodyweight !== null) {
                set_user_setting($db, 'bodyweight_kg', (string)$bodyweight);
                $db->prepare("INSERT INTO bodyweight_measurements (measured_at, weight_kg, era_id, workout_id, source)
                    SELECT started_at, ?, era_id, id, 'workout' FROM workouts WHERE id = ?
                    ON CONFLICT DO NOTHING")
                   ->execute([$bodyweight, $workout_id]);
            }
            maybe_update_cardio_pr($db, $exercise_id, $duration, $distance, $hr_avg, $intensity);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        forge_refresh_active_cycle($db, $workout_id);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'finish_workout') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('ID required');
        $notes      = $_POST['notes'] ?? null;
        $bodyweight = $_POST['bodyweight_kg'] ? (float)$_POST['bodyweight_kg'] : null;

        $db->beginTransaction();
        try {
        require_active_workout($db, $id);
        $db->prepare("UPDATE workouts SET finished_at=?, notes=?, bodyweight_kg=? WHERE id=?")
           ->execute([date('Y-m-d H:i:s'), $notes, $bodyweight, $id]);

        // Cache bodyweight for future effective-weight calculations
        if ($bodyweight) {
            set_user_setting($db, 'bodyweight_kg', (string)$bodyweight);
            $db->prepare("INSERT INTO bodyweight_measurements (measured_at, weight_kg, era_id, workout_id, source)
                SELECT started_at, ?, era_id, id, 'workout' FROM workouts WHERE id = ?
                ON CONFLICT DO NOTHING")
               ->execute([$bodyweight, $id]);
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

        // Advance the A/B template counter now that the workout is actually finished.
        // We stored which variant was used on the workout row at exercise-seed time so
        // this is a no-op if the workout was never seeded from an A/B template.
        $wk = $db->prepare("SELECT template_id, ab_variant FROM workouts WHERE id = ?");
        $wk->execute([$id]);
        $wk_row = $wk->fetch(PDO::FETCH_ASSOC);
        if ($wk_row && $wk_row['template_id'] && $wk_row['ab_variant']) {
            $db->prepare("UPDATE templates SET last_ab_variant = ? WHERE id = ?")
               ->execute([$wk_row['ab_variant'], $wk_row['template_id']]);
        }
        $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        forge_refresh_active_cycle($db, $id);
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
        $db->beginTransaction();
        try {
            $deleted = $db->prepare("DELETE FROM workouts WHERE id=?");
            $deleted->execute([$id]);
            if ($deleted->rowCount() === 0) throw new Exception('Workout not found');
            rebuild_derived_records($db);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
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

        // Fetch previous sets for every current exercise in one query. The old
        // implementation made one history query per exercise (N+1).
        if ($exercises) {
            $exercise_ids = array_keys($exercises);
            $placeholders = implode(',', array_fill(0, count($exercise_ids), '?'));
            $prev = $db->prepare("
                SELECT ws.exercise_id, ws.set_number, ws.weight_kg, ws.added_weight_kg, ws.reps, ws.rpe,
                       ws.set_type, ws.duration_sec, ws.distance_km, ws.hr_avg, ws.hr_max, ws.intensity
                FROM workout_sets ws
                JOIN workouts w ON w.id = ws.workout_id
                WHERE ws.exercise_id IN ($placeholders)
                  AND w.finished_at IS NOT NULL
                  AND w.id != ?
                ORDER BY ws.exercise_id ASC, w.finished_at DESC, ws.set_number ASC
            ");
            $prev->execute([...$exercise_ids, $workout_id]);
            foreach ($prev->fetchAll(PDO::FETCH_ASSOC) as $set) {
                $eid = (int)$set['exercise_id'];
                if (count($exercises[$eid]['previous_sets']) < 20) {
                    unset($set['exercise_id']);
                    $exercises[$eid]['previous_sets'][] = $set;
                }
            }
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
        require_active_workout_exercise($db, $workout_id, $exercise_id);
        if (!in_array($set_type, ['warmup', 'working', 'dropset', 'failure'], true)) throw new Exception('Invalid set type');
        if ($set_number < 1) throw new Exception('Set number must be positive');
        if ($weight_kg !== null && $weight_kg < 0) throw new Exception('Weight cannot be negative');
        if ($rpe !== null && ($rpe < 1 || $rpe > 10)) throw new Exception('RPE must be between 1 and 10');
        if ($duration !== null && $duration < 0) throw new Exception('Duration cannot be negative');
        if ($distance !== null && $distance < 0) throw new Exception('Distance cannot be negative');
        if ($intensity !== null && ($intensity < 1 || $intensity > 10)) throw new Exception('Intensity must be between 1 and 10');

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
                    SELECT MAX(ROUND(weight_kg * (1.0 + reps / 30.0), 1))
                    FROM workout_sets
                    WHERE exercise_id = ? AND workout_id = ? AND id != ?
                      AND set_type = 'working'
                      AND weight_kg IS NOT NULL AND reps IS NOT NULL
                      AND reps > 1 AND reps <= 30
                ");
                $chk2->execute([$exercise_id, $workout_id, $set_id ?: 0]);
                $session_best = $chk2->fetchColumn();

                $all_time  = $all_time_pr !== false ? (float)$all_time_pr : 0.0;
                $in_session = ($session_best !== false && $session_best !== null) ? (float)$session_best : 0.0;
                $best = max($all_time, $in_session);
                $is_pr = ($best == 0.0 || $new_1rm > $best);
            }
        }

        $elevation   = (isset($_POST['elevation_m']) && $_POST['elevation_m'] !== '') ? (float)$_POST['elevation_m'] : null;
        $pr_int = $is_pr ? 1 : 0;
        $logged_at = date('Y-m-d H:i:s');
        if ($set_id) {
            $db->prepare("UPDATE workout_sets SET set_number=?, set_type=?, weight_kg=?, reps=?, rpe=?, duration_sec=?, distance_km=?, added_weight_kg=?, hr_avg=?, hr_max=?, elevation_m=?, intensity=?, notes=?, is_pr=?, logged_at=? WHERE id=? AND workout_id=?")
               ->execute([$set_number, $set_type, $weight_kg, $reps, $rpe, $duration, $distance, $added_weight_kg, $hr_avg, $hr_max, $elevation, $intensity, $notes, $pr_int, $logged_at, $set_id, $workout_id]);
        } else {
            $db->prepare("INSERT INTO workout_sets (workout_id, exercise_id, set_number, set_type, weight_kg, reps, rpe, duration_sec, distance_km, added_weight_kg, hr_avg, hr_max, elevation_m, intensity, notes, is_pr, logged_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$workout_id, $exercise_id, $set_number, $set_type, $weight_kg, $reps, $rpe, $duration, $distance, $added_weight_kg, $hr_avg, $hr_max, $elevation, $intensity, $notes, $pr_int, $logged_at]);
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
        require_active_workout($db, $workout_id);
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
        require_active_workout_exercise($db, $workout_id, $exercise_id);
        $db->prepare("UPDATE workout_exercises SET target_sets=? WHERE workout_id=? AND exercise_id=?")
           ->execute([$target_sets, $workout_id, $exercise_id]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'remove_workout_exercise') {
        $workout_id  = (int)($_POST['workout_id']  ?? 0);
        $exercise_id = (int)($_POST['exercise_id'] ?? 0);
        if (!$workout_id || !$exercise_id) throw new Exception('workout_id and exercise_id required');
        require_active_workout_exercise($db, $workout_id, $exercise_id);
        $db->prepare("DELETE FROM workout_exercises WHERE workout_id=? AND exercise_id=?")
           ->execute([$workout_id, $exercise_id]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'delete_set') {
        $set_id    = (int)($_POST['set_id'] ?? 0);
        $workout_id = (int)($_POST['workout_id'] ?? 0);
        if (!$set_id) throw new Exception('set_id required');
        require_active_workout($db, $workout_id);
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
        [$sets, $reps_min, $reps_max, $rpe, $scheme, $increment] = validated_template_progression();
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
        [$sets, $reps_min, $reps_max, $rpe, $scheme, $increment] = validated_template_progression();
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

    } elseif ($action === 'apply_progression') {
        // Persist that the user accepted a progression suggestion for this exercise
        // in this workout. Stored on workout_exercises so it survives page reload.
        // Pass weight='' to clear (currently unused).
        $workout_id  = (int)($_POST['workout_id'] ?? 0);
        $exercise_id = (int)($_POST['exercise_id'] ?? 0);
        $weight      = $_POST['weight'] ?? '';
        if (!$workout_id || !$exercise_id) throw new Exception('workout_id and exercise_id required');
        require_active_workout_exercise($db, $workout_id, $exercise_id);
        $w = $weight === '' ? null : (float)$weight;
        $db->prepare("UPDATE workout_exercises SET progression_applied_weight=? WHERE workout_id=? AND exercise_id=?")
           ->execute([$w, $workout_id, $exercise_id]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'get_exercise_completion') {
        $workout_id = (int)($_POST['workout_id'] ?? 0);
        $exercise_id = (int)($_POST['exercise_id'] ?? 0);
        if (!$workout_id || !$exercise_id) throw new Exception('workout_id and exercise_id required');
        require_active_workout_exercise($db, $workout_id, $exercise_id);
        $config = $db->prepare("SELECT we.target_sets, te.target_reps_min, te.target_reps_max, te.progression_scheme, te.progression_increment_kg
            FROM workout_exercises we JOIN workouts w ON w.id=we.workout_id
            LEFT JOIN template_exercises te ON te.template_id=w.template_id AND te.exercise_id=we.exercise_id
            WHERE we.workout_id=? AND we.exercise_id=?");
        $config->execute([$workout_id, $exercise_id]); $config = $config->fetch(PDO::FETCH_ASSOC) ?: [];
        $sets = $db->prepare("SELECT MIN(reps) min_reps, MAX(weight_kg) max_weight, MAX(added_weight_kg) max_added_weight, COUNT(*) set_count
            FROM workout_sets WHERE workout_id=? AND exercise_id=? AND set_type='working'");
        $sets->execute([$workout_id, $exercise_id]); $sets = $sets->fetch(PDO::FETCH_ASSOC) ?: [];
        $scheme = $config['progression_scheme'] ?? null;
        $target_sets = (int)($config['target_sets'] ?? 0);
        $reps_min = (int)($config['target_reps_min'] ?? 0);
        $reps_max = (int)($config['target_reps_max'] ?? $reps_min);
        $increment = (float)($config['progression_increment_kg'] ?? 2.5);
        $completed = (int)($sets['set_count'] ?? 0) >= $target_sets;
        $top_hit = $completed && $reps_max > 0 && (int)($sets['min_reps'] ?? 0) >= $reps_max;
        $base_weight = $sets['max_added_weight'] !== null ? (float)$sets['max_added_weight'] : (float)($sets['max_weight'] ?? 0);
        $next_weight = $base_weight;
        $message = 'Exercise complete — next target will be based on this session.';
        $tone = 'complete';
        if ($scheme === 'double') {
            if ($top_hit) { $next_weight += $increment; $message = "All {$target_sets} sets hit {$reps_max} — add {$increment}kg next time."; $tone = 'progress'; }
            else { $message = "Exercise complete — push for {$reps_max} reps on all {$target_sets} sets next time."; }
        } elseif ($scheme === 'linear') {
            if ($completed && $reps_min > 0 && (int)($sets['min_reps'] ?? 0) >= $reps_min) { $next_weight += $increment; $message = "Target hit — add {$increment}kg next time."; $tone = 'progress'; }
            else { $message = "Exercise complete — repeat this weight and reach {$reps_min} reps."; }
        }
        echo json_encode(['status' => 'success', 'data' => ['message' => $message, 'tone' => $tone, 'next_weight' => round($next_weight, 1), 'is_bodyweight' => $sets['max_added_weight'] !== null]]);

    } elseif ($action === 'replace_workout_exercise') {
        $workout_id = (int)($_POST['workout_id'] ?? 0);
        $from_id = (int)($_POST['from_exercise_id'] ?? 0);
        $to_id = (int)($_POST['to_exercise_id'] ?? 0);
        if (!$workout_id || !$from_id || !$to_id || $from_id === $to_id) throw new Exception('Valid replacement exercises required');
        require_active_workout_exercise($db, $workout_id, $from_id);
        $logged = $db->prepare('SELECT COUNT(*) FROM workout_sets WHERE workout_id=? AND exercise_id=?');
        $logged->execute([$workout_id, $from_id]);
        if ((int)$logged->fetchColumn() > 0) throw new Exception('Only an unstarted exercise can be replaced');
        $exists = $db->prepare('SELECT 1 FROM workout_exercises WHERE workout_id=? AND exercise_id=?');
        $exists->execute([$workout_id, $to_id]);
        if ($exists->fetchColumn()) throw new Exception('That exercise is already in this workout');
        $db->beginTransaction();
        try {
            $old = $db->prepare('SELECT order_num, target_sets FROM workout_exercises WHERE workout_id=? AND exercise_id=?');
            $old->execute([$workout_id, $from_id]); $old = $old->fetch(PDO::FETCH_ASSOC);
            $db->prepare('DELETE FROM workout_exercises WHERE workout_id=? AND exercise_id=?')->execute([$workout_id, $from_id]);
            $db->prepare('INSERT INTO workout_exercises (workout_id, exercise_id, order_num, target_sets) VALUES (?,?,?,?)')
                ->execute([$workout_id, $to_id, (int)$old['order_num'], (int)$old['target_sets']]);
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'reorder_workout_exercises') {
        $workout_id = (int)($_POST['workout_id'] ?? 0);
        $order = json_decode($_POST['order'] ?? '[]', true);
        if (!$workout_id || !is_array($order)) throw new Exception('workout_id and order required');
        require_active_workout($db, $workout_id);
        $stmt = $db->prepare("UPDATE workout_exercises SET order_num=? WHERE workout_id=? AND exercise_id=?");
        foreach ($order as $i => $eid) {
            $stmt->execute([$i + 1, $workout_id, (int)$eid]);
        }
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
                    'profile_resting_hr', 'profile_max_hr',
                    'warmup_presets_compound', 'warmup_presets_bodyweight', 'warmup_presets_accessory'];
        if (!in_array($key, $allowed)) throw new Exception('Unknown setting key');
        set_user_setting($db, $key, $value);
        if ($key === 'profile_sex' || $key === 'profile_height_cm') forge_refresh_smoothed_bodyfat($db);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'save_body_measurement') {
        $waist     = $_POST['waist_cm']       ? (float)$_POST['waist_cm']       : null;
        $neck      = $_POST['neck_cm']        ? (float)$_POST['neck_cm']        : null;
        $hip       = $_POST['hip_cm']         ? (float)$_POST['hip_cm']         : null;
        $caliper   = !empty($_POST['caliper_bf_pct']) ? (float)$_POST['caliper_bf_pct'] : null;
        $caliper_mm = !empty($_POST['caliper_mm']) ? (float)$_POST['caliper_mm'] : null;
        $measured_at = date('Y-m-d H:i:s');
        if ($caliper_mm !== null) {
            $age = forge_age_years(get_user_setting($db, 'profile_dob', ''), $measured_at);
            if ($age === null) throw new Exception('Set your date of birth in Profile to convert skinfold mm to BF%');
            $caliper = forge_skinfold_bodyfat_pct(get_user_setting($db, 'profile_sex', 'M'), $caliper_mm, $age);
            if ($caliper === null) throw new Exception('Skinfold must be 2–36 mm (men only); enter BF% instead');
        }
        if (!$waist && !$neck && !$hip && !$caliper) throw new Exception('At least one measurement required');
        $db->prepare("INSERT INTO body_measurements (measured_at, waist_cm, neck_cm, hip_cm, caliper_bf_pct, caliper_mm, era_id) VALUES (?,?,?,?,?,?,?)")
           ->execute([$measured_at, $waist, $neck, $hip, $caliper, $caliper_mm, $active_era_id]);
        $id = (int)$db->lastInsertId();
        forge_refresh_smoothed_bodyfat($db, $active_era_id);
        echo json_encode(['status' => 'success', 'data' => ['id' => $id]]);

    } elseif ($action === 'log_hr_sample') {
        $workout_id  = (int)($_POST['workout_id'] ?? 0);
        $samples_raw = $_POST['samples'] ?? '';
        if (!$workout_id) throw new Exception('workout_id required');
        require_active_workout($db, $workout_id);
        $samples = json_decode($samples_raw, true);
        if (!is_array($samples) || empty($samples)) throw new Exception('samples must be a non-empty JSON array');
        $stmt = $db->prepare("INSERT INTO workout_hr_samples (workout_id, sampled_at, bpm) VALUES (?, ?, ?)");
        $saved = 0;
        foreach ($samples as $s) {
            $bpm = (int)($s['bpm'] ?? 0);
            $ts  = $s['sampled_at'] ?? date('Y-m-d H:i:s');
            if ($bpm < 20 || $bpm > 250) continue;
            $stmt->execute([$workout_id, $ts, $bpm]);
            $saved++;
        }
        echo json_encode(['status' => 'success', 'saved' => $saved]);

    } elseif ($action === 'log_hr_measurement') {
        $type = $_POST['type'] ?? '';
        $bpm  = (int)($_POST['bpm'] ?? 0);
        if (!in_array($type, ['resting', 'max'])) throw new Exception('type must be resting or max');
        if ($bpm < 20 || $bpm > 250) throw new Exception('bpm out of range');
        $db->prepare("INSERT INTO hr_measurements (type, bpm, measured_at) VALUES (?, ?, ?)")
           ->execute([$type, $bpm, date('Y-m-d H:i:s')]);
        $setting_key = $type === 'resting' ? 'profile_resting_hr' : 'profile_max_hr';
        set_user_setting($db, $setting_key, (string)$bpm);
        echo json_encode(['status' => 'success', 'bpm' => $bpm]);

    } elseif ($action === 'delete_body_measurement') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('id required');
        $era = $db->prepare("SELECT era_id FROM body_measurements WHERE id = ?");
        $era->execute([$id]);
        $era_id = $era->fetchColumn();
        $db->prepare("DELETE FROM body_measurements WHERE id = ?")->execute([$id]);
        if ($era_id) forge_refresh_smoothed_bodyfat($db, (int)$era_id);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'get_last_exercise_sets') {
        // Previous working and drop sets from the most recent finished workout.
        $exercise_id = (int)($_POST['exercise_id'] ?? 0);
        $workout_id  = (int)($_POST['workout_id'] ?? 0); // current workout to exclude
        if (!$exercise_id) throw new Exception('exercise_id required');

        $stmt = $db->prepare("
            SELECT ws.set_number, ws.weight_kg, ws.added_weight_kg, ws.reps, ws.rpe,
                   ws.set_type, ws.duration_sec, ws.distance_km, ws.hr_avg, ws.hr_max, ws.intensity
            FROM workout_sets ws
            WHERE ws.exercise_id = ?
              AND ws.set_type IN ('working', 'dropset')
              AND ws.workout_id = (
                  SELECT w2.id FROM workouts w2
                  JOIN workout_sets ws2 ON ws2.workout_id = w2.id
                  WHERE ws2.exercise_id = ? AND w2.finished_at IS NOT NULL
                    AND (? = 0 OR w2.id != ?)
                    AND w2.era_id = ?
                  ORDER BY w2.finished_at DESC LIMIT 1
              )
            ORDER BY ws.set_number ASC
        ");
        $stmt->execute([$exercise_id, $exercise_id, $workout_id, $workout_id, $active_era_id]);
        $previous_sets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'status' => 'success',
            'data' => array_values(array_filter($previous_sets, fn($set) => $set['set_type'] === 'working')),
            'drop_sets' => array_values(array_filter($previous_sets, fn($set) => $set['set_type'] === 'dropset')),
        ]);

    } else {
        throw new Exception('Unknown action: ' . htmlspecialchars($action));
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
