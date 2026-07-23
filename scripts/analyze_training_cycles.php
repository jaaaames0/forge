<?php
/** Infer bulk, cut, recomposition, and detraining cycles from training data. */
declare(strict_types=1);
require_once __DIR__ . '/../core/db_helper.php';
require_once __DIR__ . '/../core/cycle_analysis.php';

function day_diff(string $from, string $to): float {
    return (strtotime($to) - strtotime($from)) / 86400;
}

function big_four_trend(PDO $db, int $era_id, string $at): ?float {
    static $cache = [];
    $key = $era_id . ':' . substr($at, 0, 10);
    if (array_key_exists($key, $cache)) return $cache[$key];
    $window = $db->prepare("SELECT e.name, MAX(ws.weight_kg * (1 + ws.reps / 30.0)) AS e1rm
        FROM workout_sets ws JOIN workouts w ON w.id = ws.workout_id JOIN exercises e ON e.id = ws.exercise_id
        WHERE w.era_id = ? AND w.finished_at IS NOT NULL AND ws.set_type = 'working'
          AND e.name IN ('Barbell Back Squat','Barbell Bench Press','Deadlift','Overhead Press')
          AND w.started_at >= ? AND w.started_at < ? GROUP BY e.name");
    $end = date('Y-m-d H:i:s', strtotime($at . ' +1 day'));
    $mid = date('Y-m-d H:i:s', strtotime($at . ' -21 days'));
    $start = date('Y-m-d H:i:s', strtotime($at . ' -42 days'));
    $window->execute([$era_id, $start, $mid]); $before = array_column($window->fetchAll(PDO::FETCH_ASSOC), 'e1rm', 'name');
    $window->execute([$era_id, $mid, $end]);   $after  = array_column($window->fetchAll(PDO::FETCH_ASSOC), 'e1rm', 'name');
    $changes = [];
    foreach ($before as $name => $value) if (isset($after[$name]) && (float)$value > 0) {
        $changes[] = ((float)$after[$name] / (float)$value - 1) * 100;
    }
    return $cache[$key] = $changes ? array_sum($changes) / count($changes) : null;
}

function phase_type(PDO $db, int $era_id, array $anchor, array $point): string {
    $days = max(1, day_diff($anchor['measured_at'], $point['measured_at']));
    $rate = ((float)$point['weight_kg'] - (float)$anchor['weight_kg']) / $days * 7;
    $strength = big_four_trend($db, $era_id, $point['measured_at']);

    // A meaningful strength rise while weight is flat or falling is a recomp.
    if (($strength === null || $strength >= 2.0) && $rate < 0.15) return 'recomposition';
    if ($rate >= 0.20 && ($strength === null || $strength >= 2.0)) return 'bulk';
    if ($rate <= -0.15) return 'cut';
    return 'recomposition';
}

/** Return the full proposed auto-cycle set without changing the database. */
function forge_detect_training_cycles(PDO $db): array {
    $eras = $db->query('SELECT id, name FROM training_eras ORDER BY starts_at')->fetchAll(PDO::FETCH_ASSOC);
    $suggestions = [];

foreach ($eras as $era) {
    $weights = $db->prepare('SELECT measured_at, weight_kg FROM bodyweight_measurements WHERE era_id=? ORDER BY measured_at');
    $weights->execute([$era['id']]); $points = $weights->fetchAll(PDO::FETCH_ASSOC);
    $workouts = $db->prepare("SELECT started_at FROM workouts WHERE era_id=? AND finished_at IS NOT NULL AND name != 'Strong bodyweight' ORDER BY started_at");
    $workouts->execute([$era['id']]); $dates = $workouts->fetchAll(PDO::FETCH_COLUMN);

    // A session isolated by four weeks on either side is not a real return to
    // training. Ignore it when finding gaps, so one tentative gym visit cannot
    // split a genuine detraining period.
    $active_dates = [];
    $isolated_dates = [];
    foreach ($dates as $i => $date) {
        $before = $i > 0 ? day_diff($dates[$i - 1], $date) : INF;
        $after = $i < count($dates) - 1 ? day_diff($date, $dates[$i + 1]) : INF;
        if ($before >= 28 && $after >= 28) { $isolated_dates[] = substr($date, 0, 10); continue; }
        $active_dates[] = $date;
    }

    $breaks = [];
    for ($i = 1; $i < count($active_dates); $i++) {
        $gap = day_diff($active_dates[$i - 1], $active_dates[$i]);
        if ($gap < 42) continue;
        $resume = $active_dates[$i];
        foreach ($points as $point) {
            if (in_array(substr($point['measured_at'], 0, 10), $isolated_dates, true)) continue;
            if (strtotime($point['measured_at']) > strtotime($active_dates[$i - 1] . ' +7 days')
                && strtotime($point['measured_at']) <= strtotime($resume)) {
                $resume = $point['measured_at'];
                break;
            }
        }
        $breaks[] = ['start' => $active_dates[$i - 1], 'end' => $resume, 'gap_days' => round($gap)];
        $suggestions[] = [$era['id'], 'detraining', $active_dates[$i - 1], $resume, 'Training break', .95, ['gap_days' => round($gap)]];
    }

    $chunks = [[]];
    foreach ($points as $point) {
        $in_break = false;
        foreach ($breaks as $break) if (strtotime($point['measured_at']) > strtotime($break['start']) && strtotime($point['measured_at']) < strtotime($break['end'])) { $in_break = true; break; }
        if ($in_break) { if (end($chunks)) $chunks[] = []; continue; }
        foreach ($breaks as $break) {
            $last_chunk = end($chunks);
            $last = $last_chunk ? end($last_chunk) : null;
            if ($last && strtotime($last['measured_at']) <= strtotime($break['start'])
                && strtotime($point['measured_at']) >= strtotime($break['end'])) {
                $chunks[] = [];
                break;
            }
        }
        $chunks[count($chunks) - 1][] = $point;
    }

    foreach ($chunks as $chunk) {
        if (count($chunk) < 2) continue;
        $start = $chunk[0];
        $first_anchor = 0;
        while ($first_anchor > 0 && day_diff($chunk[$first_anchor]['measured_at'], $chunk[1]['measured_at']) < 21) $first_anchor--;
        $type = phase_type($db, (int)$era['id'], $chunk[$first_anchor], $chunk[1]);
        $pending_type = null;
        $pending_index = null;
        for ($i = 2; $i < count($chunk); $i++) {
            $anchor = $i - 1;
            while ($anchor > 0 && day_diff($chunk[$anchor]['measured_at'], $chunk[$i]['measured_at']) < 21) $anchor--;
            $candidate = phase_type($db, (int)$era['id'], $chunk[$anchor], $chunk[$i]);
            if ($candidate === $type) { $pending_type = null; $pending_index = null; continue; }
            if ($pending_type !== $candidate) { $pending_type = $candidate; $pending_index = $i; continue; }
            if (day_diff($chunk[$pending_index]['measured_at'], $chunk[$i]['measured_at']) < 21) continue;
            $end = $chunk[$pending_index - 1];
            $boundary = $end;
            if ($candidate === 'cut') {
                foreach ($chunk as $point) {
                    if (strtotime($point['measured_at']) < strtotime($start['measured_at']) || strtotime($point['measured_at']) > strtotime($end['measured_at'])) continue;
                    if ((float)$point['weight_kg'] > (float)$boundary['weight_kg']) $boundary = $point;
                }
            } elseif ($candidate === 'bulk' || ($candidate === 'recomposition' && $type === 'cut')) {
                foreach ($chunk as $point) {
                    if (strtotime($point['measured_at']) < strtotime($start['measured_at']) || strtotime($point['measured_at']) > strtotime($end['measured_at'])) continue;
                    if ((float)$point['weight_kg'] < (float)$boundary['weight_kg']) $boundary = $point;
                }
            }
            if (day_diff($start['measured_at'], $boundary['measured_at']) >= 14) {
                $days = max(1, day_diff($start['measured_at'], $boundary['measured_at']));
                $change = round((float)$boundary['weight_kg'] - (float)$start['weight_kg'], 1);
                $suggestions[] = [$era['id'], $type, $start['measured_at'], $boundary['measured_at'], ucfirst($type) . ' suggestion', .8, ['start_weight'=>(float)$start['weight_kg'], 'end_weight'=>(float)$boundary['weight_kg'], 'change_kg'=>$change, 'weekly_rate_kg'=>round($change/$days*7,2)]];
            }
            $start = $boundary;
            $type = $candidate;
            $pending_type = null;
            $pending_index = null;
        }
        if ($pending_type !== null) {
            $end = $chunk[$pending_index - 1];
            $boundary = $end;
            if ($pending_type === 'cut') {
                foreach ($chunk as $point) if (strtotime($point['measured_at']) >= strtotime($start['measured_at']) && strtotime($point['measured_at']) <= strtotime($end['measured_at']) && (float)$point['weight_kg'] > (float)$boundary['weight_kg']) $boundary = $point;
            } elseif ($pending_type === 'bulk' || ($pending_type === 'recomposition' && $type === 'cut')) {
                foreach ($chunk as $point) if (strtotime($point['measured_at']) >= strtotime($start['measured_at']) && strtotime($point['measured_at']) <= strtotime($end['measured_at']) && (float)$point['weight_kg'] < (float)$boundary['weight_kg']) $boundary = $point;
            }
            if (day_diff($start['measured_at'], $boundary['measured_at']) >= 14) {
                $days = max(1, day_diff($start['measured_at'], $boundary['measured_at']));
                $change = round((float)$boundary['weight_kg'] - (float)$start['weight_kg'], 1);
                $suggestions[] = [$era['id'], $type, $start['measured_at'], $boundary['measured_at'], ucfirst($type) . ' suggestion', .8, ['start_weight'=>(float)$start['weight_kg'], 'end_weight'=>(float)$boundary['weight_kg'], 'change_kg'=>$change, 'weekly_rate_kg'=>round($change/$days*7,2)]];
                $start = $boundary;
                $type = $pending_type;
            }
        }
        $end = $chunk[count($chunk) - 1];
        if (day_diff($start['measured_at'], $end['measured_at']) >= 14) {
            $days = max(1, day_diff($start['measured_at'], $end['measured_at']));
            $change = round((float)$end['weight_kg'] - (float)$start['weight_kg'], 1);
            $suggestions[] = [$era['id'], $type, $start['measured_at'], $end['measured_at'], ucfirst($type) . ' suggestion', .8, ['start_weight'=>(float)$start['weight_kg'], 'end_weight'=>(float)$end['weight_kg'], 'change_kg'=>$change, 'weekly_rate_kg'=>round($change/$days*7,2)]];
        }
    }
}
usort($suggestions, fn($a, $b) => [$a[0], $a[2]] <=> [$b[0], $b[2]]);

// A short flat spell at the very end of an otherwise sustained bulk does not
// deserve its own phase when training then stops altogether. Conversely, a
// long, slow gain without meaningful strength confirmation is recomposition.
foreach ($suggestions as &$suggestion) {
    if ($suggestion[1] !== 'bulk') continue;
    $days = max(1, day_diff($suggestion[2], $suggestion[3]));
    $rate = isset($suggestion[6]['weekly_rate_kg']) ? abs((float)$suggestion[6]['weekly_rate_kg']) : 0;
    if ($days >= 84 && $rate < 0.15) {
        $suggestion[1] = 'recomposition';
        $suggestion[4] = 'Maintenance suggestion';
    }
}
unset($suggestion);
for ($i = 1; $i < count($suggestions) - 1;) {
    $previous = &$suggestions[$i - 1];
    $current = &$suggestions[$i];
    $next = &$suggestions[$i + 1];
    if ($previous[0] === $current[0] && $current[0] === $next[0]
        && $previous[1] === 'bulk' && $current[1] === 'recomposition' && $next[1] === 'detraining'
        && day_diff($current[2], $current[3]) <= 42) {
        $previous[3] = $current[3];
        unset($previous, $current, $next);
        array_splice($suggestions, $i, 1);
        continue;
    }
    unset($previous, $current, $next);
    $i++;
}
for ($i = 0; $i < count($suggestions) - 1;) {
    $previous = &$suggestions[$i];
    $current = &$suggestions[$i + 1];
    $start_weight = $previous[6]['start_weight'] ?? null;
    $end_weight = $current[6]['end_weight'] ?? null;
    $days = day_diff($previous[2], $current[3]);
    $combined_rate = ($start_weight !== null && $end_weight !== null && $days > 0)
        ? abs(((float)$end_weight - (float)$start_weight) / $days * 7) : INF;
    if ($previous[0] === $current[0] && $previous[1] === 'recomposition' && $current[1] === 'bulk'
        && $days >= 84 && $combined_rate < 0.15) {
        $previous[3] = $current[3];
        $previous[4] = 'Maintenance suggestion';
        $previous[6]['end_weight'] = $end_weight;
        $previous[6]['change_kg'] = round((float)$end_weight - (float)$start_weight, 1);
        $previous[6]['weekly_rate_kg'] = round(((float)$end_weight - (float)$start_weight) / $days * 7, 2);
        unset($previous, $current);
        array_splice($suggestions, $i + 1, 1);
        continue;
    }
    unset($previous, $current);
    $i++;
}

    // The final cycle in the active era is live rather than historical. The
    // detector naturally ends it at the latest bodyweight point, but Forge
    // extends it through completed workouts. When type and start still agree,
    // retain that live endpoint so a dry run cannot shrink an ongoing bulk/cut.
    $active_era = $db->query('SELECT id FROM training_eras WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
    if ($active_era) {
        $saved = $db->prepare('SELECT cycle_type, starts_at, ends_at FROM training_cycles WHERE era_id = ? ORDER BY starts_at DESC LIMIT 1');
        $saved->execute([(int)$active_era]); $saved = $saved->fetch(PDO::FETCH_ASSOC);
        if ($saved) {
            for ($i = count($suggestions) - 1; $i >= 0; $i--) {
                if ((int)$suggestions[$i][0] !== (int)$active_era) continue;
                if ($suggestions[$i][1] === $saved['cycle_type'] && $suggestions[$i][2] === $saved['starts_at']) {
                    $suggestions[$i][3] = max($suggestions[$i][3], $saved['ends_at']);
                }
                break;
            }
        }
    }
    return $suggestions;
}

/** Apply an already-previewed proposal atomically. */
function forge_apply_detected_cycles(PDO $db, array $suggestions): int {
$db->beginTransaction(); try {
    $db->exec("DELETE FROM training_cycles");
    $add=$db->prepare('INSERT INTO training_cycles (era_id,cycle_type,starts_at,ends_at,label,source,confidence,metrics_json) VALUES (?,?,?,?,?,\'auto\',?,?)');
    foreach ($suggestions as $s) {
        // Shared analysis owns displayed metrics so SQLite float artefacts from
        // the detector cannot leak into cards or comparison tables.
        $metrics = forge_analyse_cycle($db, ['era_id' => $s[0], 'cycle_type' => $s[1], 'starts_at' => $s[2], 'ends_at' => $s[3]]) + $s[6];
        $add->execute([$s[0],$s[1],$s[2],$s[3],$s[4],$s[5],json_encode($metrics)]);
    }
    $db->commit(); return count($suggestions);
} catch(Throwable $e){$db->rollBack();throw $e;}
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
$apply = in_array('--apply', $argv, true);
$db = get_db_connection();
$suggestions = forge_detect_training_cycles($db);
$eras = $db->query('SELECT id, name FROM training_eras ORDER BY starts_at')->fetchAll(PDO::FETCH_ASSOC);
foreach ($suggestions as $s) printf("%s · %s → %s · %s\n", $eras[array_search($s[0],array_column($eras,'id'))]['name'],$s[2],$s[3],$s[1]);
if (!$apply) { echo "Dry run only. Use --apply to save auto suggestions.\n"; exit; }
echo forge_apply_detected_cycles($db, $suggestions)." suggestions saved.\n";
