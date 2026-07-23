<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/db_helper.php';

$db = get_db_connection();

// ── PROFILE FIELDS ──────────────────────────────────────────────────────────
$profile = [
    'name'       => get_user_setting($db, 'profile_name',       ''),
    'sex'        => get_user_setting($db, 'profile_sex',        ''),
    'dob'        => get_user_setting($db, 'profile_dob',        ''),
    'height_cm'  => get_user_setting($db, 'profile_height_cm',  ''),
    'resting_hr' => get_user_setting($db, 'profile_resting_hr', ''),
    'max_hr'     => get_user_setting($db, 'profile_max_hr',     ''),
];

// Compute age from DOB
$age = null;
if ($profile['dob']) {
    try { $age = (int)(new DateTime())->diff(new DateTime($profile['dob']))->y; } catch (Exception $e) {}
}

// Derived biometrics
$height_m  = $profile['height_cm'] ? (float)$profile['height_cm'] / 100 : null;
$bw        = (float)($db->query("SELECT bodyweight_kg FROM workouts WHERE bodyweight_kg IS NOT NULL AND bodyweight_kg > 0 ORDER BY started_at DESC LIMIT 1")->fetchColumn() ?: 0);
$bmi       = ($height_m && $bw) ? round($bw / ($height_m ** 2), 1) : null;

$est_max_hr = $profile['max_hr'] ? (int)$profile['max_hr'] : ($age ? 220 - $age : null);
$vo2max     = ($profile['resting_hr'] && $est_max_hr && (int)$profile['resting_hr'] > 0)
    ? round(15 * ($est_max_hr / (float)$profile['resting_hr']), 1) : null;

// ── RECENT BODY MEASUREMENTS ────────────────────────────────────────────────
$measurements = $db->query("
    SELECT * FROM body_measurements ORDER BY measured_at DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Most recent valid measurement for Navy BF% calculation
$navy_bf = null;
$latest_m = null;
foreach ($measurements as $m) {
    if ($m['waist_cm'] && $m['neck_cm'] && $height_m && $profile['sex']) {
        $latest_m = $m;
        if ($profile['sex'] === 'M') {
            $navy_bf = round(86.01 * log10($m['waist_cm'] - $m['neck_cm']) - 70.041 * log10($profile['height_cm']) + 36.76, 1);
        } elseif ($profile['sex'] === 'F' && $m['hip_cm']) {
            $navy_bf = round(163.205 * log10($m['waist_cm'] + $m['hip_cm'] - $m['neck_cm']) - 97.684 * log10($profile['height_cm']) - 78.387, 1);
        }
        break;
    }
}

$caliper_bf  = $latest_m ? $latest_m['caliper_bf_pct'] : null;
$smoothed_bf = null;
if ($navy_bf !== null && $caliper_bf !== null) {
    $smoothed_bf = round(($navy_bf + $caliper_bf) / 2, 1);
} elseif ($navy_bf !== null) {
    $smoothed_bf = $navy_bf;
} elseif ($caliper_bf !== null) {
    $smoothed_bf = round($caliper_bf, 1);
}

$lean_kg = ($smoothed_bf !== null && $bw) ? round($bw * (1 - $smoothed_bf / 100), 1) : null;
$fat_kg  = ($smoothed_bf !== null && $bw) ? round($bw * ($smoothed_bf / 100), 1)       : null;

// ── TREND DATA ─────────────────────────────────────────────────────────────
$bw_history = $db->query("
    SELECT weight_kg AS bodyweight_kg, measured_at AS date
    FROM bodyweight_measurements
    ORDER BY measured_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

$hr_rows = $db->query("
    SELECT type, bpm, DATE(measured_at) AS date
    FROM hr_measurements
    ORDER BY measured_at ASC
")->fetchAll(PDO::FETCH_ASSOC);
$resting_hr_history = array_values(array_filter($hr_rows, fn($r) => $r['type'] === 'resting'));
$max_hr_history     = array_values(array_filter($hr_rows, fn($r) => $r['type'] === 'max'));

// ── WARMUP PRESETS ─────────────────────────────────────────────────────────
// Load saved presets; fall back to defaults so editors always have content
$default_warmup = [
    'compound'   => json_encode([['pct'=>null,'reps'=>8],['pct'=>50,'reps'=>5],['pct'=>70,'reps'=>3]]),
    'bodyweight' => json_encode([['pct'=>30,'reps'=>8],['pct'=>50,'reps'=>5]]),
    'accessory'  => json_encode([['pct'=>50,'reps'=>5],['pct'=>70,'reps'=>3]]),
];
$warmup_presets_raw = [
    'compound'   => get_user_setting($db, 'warmup_presets_compound',   ''),
    'bodyweight' => get_user_setting($db, 'warmup_presets_bodyweight', ''),
    'accessory'  => get_user_setting($db, 'warmup_presets_accessory',  ''),
];
$warmup_presets = [
    'compound'   => $warmup_presets_raw['compound']   ?: $default_warmup['compound'],
    'bodyweight' => $warmup_presets_raw['bodyweight'] ?: $default_warmup['bodyweight'],
    'accessory'  => $warmup_presets_raw['accessory']  ?: $default_warmup['accessory'],
];
// JS-safe null sentinel
$warmup_presets_php = [
    'compound'   => $warmup_presets_raw['compound']   ? $warmup_presets['compound']   : 'null',
    'bodyweight' => $warmup_presets_raw['bodyweight'] ? $warmup_presets['bodyweight'] : 'null',
    'accessory'  => $warmup_presets_raw['accessory']  ? $warmup_presets['accessory']  : 'null',
];

$page_title   = 'Profile';
$page_context = 'profile';
$extra_head   = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';
$extra_styles = '<style>
@keyframes hrMeasBeat {
    0%   { transform: scale(1); }
    14%  { transform: scale(1.3); }
    28%  { transform: scale(1); }
    42%  { transform: scale(1.15); }
    70%  { transform: scale(1); }
    100% { transform: scale(1); }
}
</style>';
include __DIR__ . '/../core/page_head.php';
?>

<script>
window.forgeWarmupPresets = {
    compound:   <?= $warmup_presets_php['compound'] ?>,
    bodyweight: <?= $warmup_presets_php['bodyweight'] ?>,
    accessory:  <?= $warmup_presets_php['accessory'] ?>,
};
// Normalise PHP null sentinel string to actual JS null
['compound', 'bodyweight', 'accessory'].forEach(k => {
    if (window.forgeWarmupPresets[k] === 'null') window.forgeWarmupPresets[k] = null;
});
</script>

<div class="container-fluid py-3 px-3" style="max-width:600px;margin:0 auto;">

    <!-- Header with tabs -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="fw-black mb-0" style="font-size:1.4rem;letter-spacing:-1.5px;">
            PROFILE<span style="color:#FF5722;">_</span>
        </h1>
        <div class="d-flex gap-2">
            <button class="btn btn-tab active" id="tab-btn-user" onclick="showProfileTab('user')" type="button" title="User">
                <i class="bi bi-person-circle" style="font-size:1.2rem;"></i>
            </button>
            <button class="btn btn-tab" id="tab-btn-settings" onclick="showProfileTab('settings')" type="button" title="Settings">
                <i class="bi bi-gear-fill" style="font-size:1.1rem;"></i>
            </button>
        </div>
    </div>

    <style>
    .btn-tab {
        background: transparent;
        border: 1px solid #333;
        color: #555;
        border-radius: 6px;
        min-width: 40px;
        min-height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .btn-tab.active {
        background: #FF5722;
        border-color: #FF5722;
        color: #fff;
    }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    </style>

    <!-- USER TAB -->
    <div id="tab-user" class="tab-content active">

        <!-- ── IDENTITY ─────────────────────────────────────────────────── -->
        <div class="stat-label mb-2">IDENTITY</div>
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6">
                        <label class="stat-label d-block mb-1" style="font-size:0.55rem;">NAME</label>
                        <input type="text" id="p-name" class="form-control"
                               value="<?= htmlspecialchars($profile['name']) ?>"
                               placeholder="Your name" autocomplete="off"
                               onchange="saveSetting('profile_name', this.value)">
                    </div>
                    <div class="col-6">
                        <label class="stat-label d-block mb-1" style="font-size:0.55rem;">DATE OF BIRTH</label>
                        <input type="date" id="p-dob" class="form-control"
                               value="<?= htmlspecialchars($profile['dob']) ?>"
                               onchange="saveSetting('profile_dob', this.value)">
                        <?php if ($age !== null): ?>
                        <div class="text-muted mt-1" style="font-size:0.6rem;"><?= $age ?> yrs</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-6">
                        <label class="stat-label d-block mb-1" style="font-size:0.55rem;">SEX</label>
                        <div class="d-flex gap-2">
                            <?php foreach (['M' => 'M', 'F' => 'F'] as $val => $label): ?>
                            <button class="btn flex-grow-1 sex-btn <?= $profile['sex'] === $val ? 'btn-forge' : '' ?>"
                                    data-val="<?= $val ?>"
                                    style="<?= $profile['sex'] !== $val ? 'background:#1a1a1a;border:1px solid #333;color:#888;' : '' ?>font-weight:900;font-size:0.8rem;letter-spacing:0.5px;min-height:36px;"
                                    onclick="setSex('<?= $val ?>')">
                                <?= $label ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="stat-label d-block mb-1" style="font-size:0.55rem;">HEIGHT</label>
                        <div class="d-flex align-items-center gap-2">
                            <input type="number" id="p-height" class="form-control" inputmode="numeric"
                                   step="1" min="100" max="250"
                                   value="<?= htmlspecialchars($profile['height_cm']) ?>"
                                   placeholder="178"
                                   onchange="saveSetting('profile_height_cm', this.value)">
                            <span class="text-muted" style="font-size:0.8rem;flex-shrink:0;">cm</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── BIOMETRICS ───────────────────────────────────────────────── -->
        <div class="stat-label mb-2">BIOMETRICS</div>
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="stat-label d-block mb-1" style="font-size:0.6rem;">RESTING HR</label>
                        <div class="d-flex align-items-center gap-2">
                            <input type="number" class="form-control" id="restingHrInput" inputmode="numeric"
                                   min="30" max="120" value="<?= htmlspecialchars($profile['resting_hr']) ?>"
                                   placeholder="e.g. 58"
                                   onchange="saveSetting('profile_resting_hr', this.value)">
                            <span class="text-muted" style="font-size:0.75rem;flex-shrink:0;">bpm</span>
                        </div>
                        <?php if (isset($_SERVER['HTTPS']) || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')): ?>
                        <button class="btn btn-sm mt-2 w-100" id="hrMeasureBtn"
                                style="background:#1a1a1a;border:1px solid #333;color:#888;font-size:0.7rem;font-weight:700;letter-spacing:0.05em;min-height:36px;"
                                onclick="toggleHrMeasure()">
                            <i class="bi bi-bluetooth me-1"></i>MEASURE
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Inline HR measurement panel (spans full width, hidden by default) -->
                    <div class="col-12 d-none" id="hrMeasurePanel">
                        <div style="background:#111;border:1px solid #2a2a2a;border-radius:10px;padding:0.85rem 1rem;">
                            <!-- Status + live BPM -->
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div id="hrMeasStatus" style="font-size:0.7rem;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:0.05em;">
                                    NOT CONNECTED
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-heart-fill" id="hrMeasHeart" style="color:#FF5722;font-size:1.1rem;opacity:0.2;transition:opacity 0.3s;"></i>
                                    <span id="hrMeasBpm" style="font-size:1.6rem;font-weight:900;color:#FF5722;font-variant-numeric:tabular-nums;min-width:3ch;text-align:right;">--</span>
                                    <span style="font-size:0.7rem;color:#555;font-weight:700;">BPM</span>
                                </div>
                            </div>
                            <!-- Progress bar: counts down to average -->
                            <div style="background:#1a1a1a;border-radius:4px;height:4px;overflow:hidden;margin-bottom:0.6rem;">
                                <div id="hrMeasProgress" style="height:100%;width:0%;background:#FF5722;transition:width 0.4s;border-radius:4px;"></div>
                            </div>
                            <div id="hrMeasHint" style="font-size:0.65rem;color:#444;">Sit still and breathe normally — 30 readings needed</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="stat-label d-block mb-1" style="font-size:0.6rem;">MAX HR</label>
                        <div class="d-flex align-items-center gap-2">
                            <input type="number" class="form-control" id="maxHrInput" inputmode="numeric"
                                   min="100" max="230" value="<?= htmlspecialchars($profile['max_hr']) ?>"
                                   placeholder="<?= $est_max_hr ? "~{$est_max_hr}" : 'e.g. 185' ?>"
                                   onchange="saveSetting('profile_max_hr', this.value)">
                            <span class="text-muted" style="font-size:0.75rem;flex-shrink:0;">bpm</span>
                        </div>
                        <?php if (!$profile['max_hr'] && $est_max_hr): ?>
                        <div class="text-muted mt-1" style="font-size:0.65rem;">Est. from age: <?= $est_max_hr ?> bpm</div>
                        <?php endif; ?>
                        <?php if (isset($_SERVER['HTTPS']) || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')): ?>
                        <button class="btn btn-sm mt-2 w-100" id="maxHrMeasureBtn"
                                style="background:#1a1a1a;border:1px solid #333;color:#888;font-size:0.7rem;font-weight:700;letter-spacing:0.05em;min-height:36px;"
                                onclick="toggleMaxHrMeasure()">
                            <i class="bi bi-bluetooth me-1"></i>MEASURE
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Max HR measurement panel (spans full width) -->
                    <div class="col-12 d-none" id="maxHrMeasurePanel">
                        <div style="background:#111;border:1px solid #2a2a2a;border-radius:10px;padding:0.85rem 1rem;">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div id="maxHrMeasStatus" style="font-size:0.7rem;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:0.05em;">
                                    NOT CONNECTED
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="text-center">
                                        <div id="maxHrMeasBpm" style="font-size:1.6rem;font-weight:900;color:#FF5722;font-variant-numeric:tabular-nums;min-width:3ch;line-height:1;">--</div>
                                        <div style="font-size:0.58rem;color:#555;font-weight:700;">LIVE</div>
                                    </div>
                                    <div class="text-center">
                                        <div id="maxHrMeasPeak" style="font-size:1.6rem;font-weight:900;color:#f44336;font-variant-numeric:tabular-nums;min-width:3ch;line-height:1;">--</div>
                                        <div style="font-size:0.58rem;color:#555;font-weight:700;">PEAK</div>
                                    </div>
                                    <div class="text-center">
                                        <div id="maxHrTimer" style="font-size:1.3rem;font-weight:900;color:#555;font-variant-numeric:tabular-nums;min-width:4ch;line-height:1;">0:00</div>
                                        <div style="font-size:0.58rem;color:#555;font-weight:700;">TIME</div>
                                    </div>
                                </div>
                            </div>
                            <div id="maxHrMeasHint" style="font-size:0.65rem;color:#444;margin-bottom:0.6rem;">Connect your monitor, then go all-out — sprint, cycle, row hard. Aim for 2 min. Your peak will be saved.</div>
                            <button id="maxHrStopBtn" class="btn w-100 d-none"
                                    style="background:#f44336;color:#fff;font-weight:700;font-size:0.75rem;letter-spacing:0.05em;min-height:40px;border:none;border-radius:8px;"
                                    onclick="stopMaxHrMeasure()">
                                <i class="bi bi-stop-fill me-1"></i>STOP &amp; SAVE PEAK
                            </button>
                        </div>
                    </div>
                </div>
                <?php if ($vo2max !== null): ?>
                <div class="mt-3 pt-3" style="border-top:1px solid #1a1a1a;">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label" style="font-size:0.6rem;">ESTIMATED VO2 MAX</div>
                            <div class="text-muted" style="font-size:0.65rem;">15 × (HR<sub>max</sub> / HR<sub>rest</sub>)</div>
                        </div>
                        <div style="font-size:1.6rem;font-weight:900;color:#FF5722;"><?= $vo2max ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── TRENDS ──────────────────────────────────────────────────── -->
        <?php if (count($bw_history) >= 2 || count($resting_hr_history) >= 2 || count($max_hr_history) >= 2): ?>
        <div class="stat-label mb-2">TRENDS</div>
        <div class="card mb-3">
            <div class="card-body">
                <?php if (count($bw_history) >= 2): ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-baseline mb-1">
                        <div class="stat-label" style="font-size:0.6rem;">BODYWEIGHT</div>
                        <div style="font-size:0.65rem;color:#555;font-variant-numeric:tabular-nums;">
                            <?= $bw_history[0]['bodyweight_kg'] ?> → <?= end($bw_history)['bodyweight_kg'] ?> kg
                        </div>
                    </div>
                    <div style="position:relative;height:180px;"><canvas id="bwChart"></canvas></div>
                </div>
                <?php endif; ?>
                <?php if (count($resting_hr_history) >= 2): ?>
                <div class="<?= count($bw_history) >= 2 ? 'mt-3 pt-3' : '' ?> mb-3" <?= count($bw_history) >= 2 ? 'style="border-top:1px solid #1a1a1a;"' : '' ?>>
                    <div class="d-flex justify-content-between align-items-baseline mb-1">
                        <div class="stat-label" style="font-size:0.6rem;">RESTING HR</div>
                        <div style="font-size:0.65rem;color:#555;font-variant-numeric:tabular-nums;">
                            <?= $resting_hr_history[0]['bpm'] ?> → <?= end($resting_hr_history)['bpm'] ?> bpm
                        </div>
                    </div>
                    <div style="position:relative;height:80px;"><canvas id="restingHrChart"></canvas></div>
                </div>
                <?php endif; ?>
                <?php if (count($max_hr_history) >= 2): ?>
                <div class="<?= (count($bw_history) >= 2 || count($resting_hr_history) >= 2) ? 'mt-3 pt-3' : '' ?>" <?= (count($bw_history) >= 2 || count($resting_hr_history) >= 2) ? 'style="border-top:1px solid #1a1a1a;"' : '' ?>>
                    <div class="d-flex justify-content-between align-items-baseline mb-1">
                        <div class="stat-label" style="font-size:0.6rem;">MAX HR</div>
                        <div style="font-size:0.65rem;color:#555;font-variant-numeric:tabular-nums;">
                            <?= $max_hr_history[0]['bpm'] ?> → <?= end($max_hr_history)['bpm'] ?> bpm
                        </div>
                    </div>
                    <div style="position:relative;height:80px;"><canvas id="maxHrChart"></canvas></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── LOG MEASUREMENT ──────────────────────────────────────────── -->
        <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="stat-label">BODY MEASUREMENTS</div>
            <button class="btn btn-forge btn-sm px-3" data-bs-toggle="modal" data-bs-target="#logMeasModal">
                <i class="bi bi-plus-lg me-1"></i>LOG
            </button>
        </div>

        <?php if ($bmi !== null || $smoothed_bf !== null): ?>
        <div class="row g-2 mb-3">
            <?php if ($bmi !== null):
                $bmi_label = $bmi < 18.5 ? 'Underweight' : ($bmi < 25 ? 'Normal' : ($bmi < 30 ? 'Overweight' : 'Obese'));
                $bmi_color = $bmi < 18.5 ? '#42a5f5' : ($bmi < 25 ? '#66bb6a' : ($bmi < 30 ? '#ffa726' : '#ef5350'));
            ?>
            <div class="col-6">
                <div class="card text-center py-3">
                    <div style="font-size:1.4rem;font-weight:900;color:<?= $bmi_color ?>;"><?= $bmi ?></div>
                    <div class="stat-label" style="font-size:0.6rem;">BMI</div>
                    <div style="font-size:0.65rem;color:<?= $bmi_color ?>;"><?= $bmi_label ?></div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($smoothed_bf !== null): ?>
            <div class="col-6">
                <div class="card text-center py-3">
                    <div style="font-size:1.4rem;font-weight:900;color:#FF5722;"><?= $smoothed_bf ?>%</div>
                    <div class="stat-label" style="font-size:0.6rem;">BODY FAT</div>
                    <?php if ($navy_bf !== null && $caliper_bf !== null): ?>
                    <div style="font-size:0.6rem;color:#555;">avg of navy + caliper</div>
                    <?php elseif ($navy_bf !== null): ?>
                    <div style="font-size:0.6rem;color:#555;">navy formula</div>
                    <?php else: ?>
                    <div style="font-size:0.6rem;color:#555;">caliper</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($lean_kg !== null && $fat_kg !== null): ?>
            <div class="col-6">
                <div class="card text-center py-3">
                    <div style="font-size:1.4rem;font-weight:900;color:#66bb6a;"><?= $lean_kg ?> kg</div>
                    <div class="stat-label" style="font-size:0.6rem;">LEAN MASS</div>
                </div>
            </div>
            <div class="col-6">
                <div class="card text-center py-3">
                    <div style="font-size:1.4rem;font-weight:900;color:#888;"><?= $fat_kg ?> kg</div>
                    <div class="stat-label" style="font-size:0.6rem;">FAT MASS</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($measurements)): ?>
        <div class="card mb-3">
            <div class="card-body p-0">
            <?php foreach ($measurements as $i => $m):
                $entry_bf = null;
                if ($m['waist_cm'] && $m['neck_cm'] && $height_m && $profile['sex']) {
                    if ($profile['sex'] === 'M') {
                        $entry_bf = round(86.01 * log10($m['waist_cm'] - $m['neck_cm']) - 70.041 * log10($profile['height_cm']) + 36.76, 1);
                    } elseif ($profile['sex'] === 'F' && $m['hip_cm']) {
                        $entry_bf = round(163.205 * log10($m['waist_cm'] + $m['hip_cm'] - $m['neck_cm']) - 97.684 * log10($profile['height_cm']) - 78.387, 1);
                    }
                }
                $entry_smoothed = null;
                if ($entry_bf !== null && $m['caliper_bf_pct']) $entry_smoothed = round(($entry_bf + $m['caliper_bf_pct']) / 2, 1);
                elseif ($entry_bf !== null) $entry_smoothed = $entry_bf;
                elseif ($m['caliper_bf_pct']) $entry_smoothed = round($m['caliper_bf_pct'], 1);
            ?>
            <div class="d-flex align-items-center px-3 py-2 gap-3"
                 style="border-bottom:1px solid #1a1a1a;<?= $i === count($measurements)-1 ? 'border-bottom:none;' : '' ?>">
                <div style="font-size:0.7rem;color:#555;min-width:56px;"><?= date('M j', strtotime($m['measured_at'])) ?></div>
                <div class="flex-grow-1 d-flex flex-wrap gap-2" style="font-size:0.75rem;">
                    <?php if ($m['waist_cm']): ?><span><span class="text-muted">W</span> <?= $m['waist_cm'] ?></span><?php endif; ?>
                    <?php if ($m['neck_cm']): ?><span><span class="text-muted">N</span> <?= $m['neck_cm'] ?></span><?php endif; ?>
                    <?php if ($m['hip_cm']): ?><span><span class="text-muted">H</span> <?= $m['hip_cm'] ?></span><?php endif; ?>
                    <?php if ($m['caliper_bf_pct']): ?><span><span class="text-muted">Cal</span> <?= $m['caliper_bf_pct'] ?>%</span><?php endif; ?>
                </div>
                <?php if ($entry_smoothed !== null): ?>
                <div style="font-weight:900;font-size:0.85rem;color:#FF5722;flex-shrink:0;"><?= $entry_smoothed ?>%</div>
                <?php endif; ?>
                <button class="btn-icon" onclick="deleteMeasurement(<?= $m['id'] ?>, this)" type="button">
                    <i class="bi bi-x-lg" style="font-size:0.8rem;color:#444;"></i>
                </button>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="card mb-3">
            <div class="card-body text-muted text-center py-3" style="font-size:0.8rem;">No measurements logged yet</div>
        </div>
        <?php endif; ?>

        <div class="text-center text-muted mb-4" style="font-size:0.65rem;letter-spacing:1px;">
            FORGE · SELF-HOSTED · <a href="/spence/" target="_blank" rel="noopener" style="color:#666;">SPENCE ↗</a>
        </div>

    </div><!-- /#tab-user -->

    <!-- SETTINGS TAB -->
    <div id="tab-settings" class="tab-content">

        <!-- ── REST ALERTS ──────────────────────────────────────────────── -->
        <div class="stat-label mb-2">REST ALERTS</div>
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div>
                        <div class="stat-label" style="font-size:0.62rem;">BELL SOUND</div>
                        <div class="text-muted" style="font-size:0.68rem;">Play tone when rest timer hits zero</div>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="settings-bell-toggle"
                               style="width:2.5em;height:1.4em;cursor:pointer;accent-color:#FF5722;"
                               onchange="setBellToggle(this.checked)">
                    </div>
                </div>
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="stat-label" style="font-size:0.62rem;">SYSTEM NOTIFICATION</div>
                        <div class="text-muted" style="font-size:0.68rem;">Tap notification to return to workout</div>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="settings-notif-toggle"
                               style="width:2.5em;height:1.4em;cursor:pointer;accent-color:#FF5722;"
                               onchange="setProfileNotificationToggle(this.checked)">
                    </div>
                </div>
                <div id="settings-notif-status" style="font-size:0.65rem;color:#666;margin-top:10px;"></div>
            </div>
        </div>

        <!-- ── REST TIMERS ──────────────────────────────────────────────── -->
        <?php
        $rest_timers = [
            ['key' => 'rest_compound_big4_sec', 'id' => 'rest-big4', 'label' => 'BIG 4 COMPOUND',  'hint' => 'squat · deadlift · bench · ohp',  'max' => 600, 'default' => 300],
            ['key' => 'rest_compound_sec',      'id' => 'rest-comp', 'label' => 'OTHER COMPOUND',   'hint' => 'rows · lunges · pull-ups etc.',    'max' => 480, 'default' => 240],
            ['key' => 'rest_bodyweight_sec',    'id' => 'rest-bw',   'label' => 'BODYWEIGHT',       'hint' => 'push-ups · dips · planks etc.',    'max' => 360, 'default' => 180],
            ['key' => 'rest_isolation_sec',     'id' => 'rest-iso',  'label' => 'ISOLATION',        'hint' => 'curls · flyes · extensions etc.',  'max' => 240, 'default' => 120],
        ];
        foreach ($rest_timers as &$rt) {
            $rt['value'] = (int)get_user_setting($db, $rt['key'], (string)$rt['default']);
        }
        unset($rt);
        ?>
        <div class="stat-label mb-2" style="margin-top:1.5rem;">REST TIMERS</div>
        <div class="card mb-3">
            <div class="card-body">
                <style>.form-range { accent-color: #FF5722; }</style>
                <?php foreach ($rest_timers as $rt): ?>
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <div>
                        <span class="stat-label" style="font-size:0.62rem;"><?= $rt['label'] ?></span>
                        <span class="text-muted" style="font-size:0.6rem;margin-left:4px;"><?= $rt['hint'] ?></span>
                    </div>
                    <div class="fw-black" style="font-size:1rem;font-family:monospace;color:#FF5722;" id="<?= $rt['id'] ?>-disp">
                        <?= gmdate('i:s', $rt['value']) ?>
                    </div>
                </div>
                <input type="range" class="form-range mb-3"
                       min="30" max="<?= $rt['max'] ?>" step="30" value="<?= $rt['value'] ?>"
                       oninput="updateRestDisplay('<?= $rt['id'] ?>-disp', this.value)"
                       onchange="saveRestTimer('<?= $rt['key'] ?>', this.value)">
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── WARMUP CALCULATOR ─────────────────────────────────────────── -->
        <div class="stat-label mb-2">WARMUP CALCULATOR</div>
        <div class="card mb-3">
            <div class="card-body">

                <!-- Compound -->
                <div class="mb-3">
                    <div class="stat-label" style="font-size:0.65rem;margin-bottom:.5rem;">COMPOUND</div>
                    <div id="warmup-editor-compound"></div>
                </div>

                <!-- Bodyweight -->
                <div class="mb-3">
                    <div class="stat-label" style="font-size:0.65rem;margin-bottom:.5rem;">BODYWEIGHT</div>
                    <div id="warmup-editor-bodyweight"></div>
                </div>

                <!-- Accessory -->
                <div>
                    <div class="stat-label" style="font-size:0.65rem;margin-bottom:.5rem;">ACCESSORY</div>
                    <div id="warmup-editor-accessory"></div>
                </div>

            </div>
        </div>

    </div><!-- /#tab-settings -->

</div><!-- /.container -->

<!-- Log Measurement Modal -->
<div class="modal fade" id="logMeasModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom:1px solid #333;">
                <h5 class="modal-title fw-black uppercase">LOG MEASUREMENTS</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="stat-label d-block mb-1" style="font-size:0.6rem;">WAIST (cm)</label>
                        <input type="number" id="m-waist" class="form-control" inputmode="decimal" step="0.5" placeholder="e.g. 82">
                    </div>
                    <div class="col-6">
                        <label class="stat-label d-block mb-1" style="font-size:0.6rem;">NECK (cm)</label>
                        <input type="number" id="m-neck" class="form-control" inputmode="decimal" step="0.5" placeholder="e.g. 38">
                    </div>
                    <div class="col-6">
                        <label class="stat-label d-block mb-1" style="font-size:0.6rem;">HIP (cm) <span class="text-muted">female</span></label>
                        <input type="number" id="m-hip" class="form-control" inputmode="decimal" step="0.5" placeholder="optional">
                    </div>
                    <div class="col-6">
                        <label class="stat-label d-block mb-1" style="font-size:0.6rem;">CALIPER BF%</label>
                        <input type="number" id="m-caliper" class="form-control" inputmode="decimal" step="0.1" min="1" max="60" placeholder="e.g. 14.5">
                    </div>
                </div>
                <div class="text-muted mt-3" style="font-size:0.7rem;">
                    Navy BF% is calculated from waist + neck + height + sex.<br>
                    Enter caliper BF% directly from your skinfold reading.
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid #333;">
                <button type="button" class="btn" data-bs-dismiss="modal"
                        style="background:#1e1e1e;border:1px solid #333;color:#888;">CANCEL</button>
                <button type="button" class="btn btn-forge flex-grow-1" onclick="logMeasurement()">SAVE</button>
            </div>
        </div>
    </div>
</div>

<script>
async function api(action, data = {}) {
    return window.forgeApi(action, data);
}

async function saveSetting(key, value) {
    await api('update_setting', { key, value });
}

// ── Tab switching ────────────────────────────────────────────────────────────
function showProfileTab(tab) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.btn-tab').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    document.getElementById('tab-btn-' + tab).classList.add('active');
    history.replaceState(null, '', tab === 'user' ? location.pathname : '#' + tab);
}
if (location.hash === '#settings') showProfileTab('settings');

function setSex(val) {
    document.querySelectorAll('.sex-btn').forEach(b => {
        const active = b.dataset.val === val;
        b.className = active ? 'btn flex-grow-1 btn-forge sex-btn' : 'btn flex-grow-1 sex-btn';
        b.style.cssText = active ? 'font-weight:900;font-size:0.8rem;letter-spacing:0.5px;'
                                 : 'background:#1a1a1a;border:1px solid #333;color:#888;font-weight:900;font-size:0.8rem;letter-spacing:0.5px;';
    });
    saveSetting('profile_sex', val);
}

async function logMeasurement() {
    const waist   = document.getElementById('m-waist').value;
    const neck    = document.getElementById('m-neck').value;
    const hip     = document.getElementById('m-hip').value;
    const caliper = document.getElementById('m-caliper').value;
    if (!waist && !neck && !hip && !caliper) {
        alert('Enter at least one measurement.');
        return;
    }
    const r = await api('save_body_measurement', {
        waist_cm: waist, neck_cm: neck, hip_cm: hip, caliper_bf_pct: caliper
    });
    if (r.status === 'success') location.reload();
    else alert(r.message);
}

async function deleteMeasurement(id, btn) {
    if (!confirm('Delete this measurement?')) return;
    const r = await api('delete_body_measurement', { id });
    if (r.status === 'success') btn.closest('.d-flex').remove();
    else alert(r.message);
}

// ── Rest alert preferences ───────────────────────────────────────────────────
function getRestPrefs() {
    if (typeof window.forgeGetRestPrefs === 'function') return window.forgeGetRestPrefs();
    const perm = ('Notification' in window) ? Notification.permission : 'unsupported';
    return {
        sound: localStorage.getItem('forge_sound_rest') !== '0',
        notifications: localStorage.getItem('forge_notify_rest') === '1',
        permission: perm,
    };
}

function refreshRestAlertUI() {
    const prefs = getRestPrefs();
    const enabled = !!prefs.notifications && prefs.permission === 'granted';
    const bell = document.getElementById('settings-bell-toggle');
    const notif = document.getElementById('settings-notif-toggle');
    const status = document.getElementById('settings-notif-status');
    if (bell) bell.checked = !!prefs.sound;
    if (notif) {
        notif.checked = enabled;
        if (prefs.permission === 'denied') {
            notif.disabled = true;
            if (status) { status.textContent = 'Notifications blocked in browser settings.'; status.style.color = '#ef5350'; }
        } else if (prefs.permission === 'unsupported') {
            notif.disabled = true;
            if (status) { status.textContent = 'Notifications not supported in this browser.'; status.style.color = '#888'; }
        } else {
            notif.disabled = false;
            if (status) { status.textContent = enabled ? 'Enabled.' : ''; status.style.color = '#666'; }
        }
    }
}

function setBellToggle(enable) {
    if (typeof window.forgeSetRestSound === 'function') {
        window.forgeSetRestSound(enable);
    } else {
        localStorage.setItem('forge_sound_rest', enable ? '1' : '0');
    }
    refreshRestAlertUI();
}

async function setProfileNotificationToggle(enable) {
    let ok = false;
    if (typeof window.forgeToggleNotifications === 'function') {
        ok = await window.forgeToggleNotifications(enable);
    } else {
        if (!('Notification' in window)) {
            ok = false;
        } else if (!enable) {
            localStorage.removeItem('forge_notify_rest');
            ok = true;
        } else {
            if (Notification.permission === 'default') {
                const result = await Notification.requestPermission();
                if (result !== 'granted') ok = false;
            }
            if (Notification.permission === 'granted') {
                localStorage.setItem('forge_notify_rest', '1');
                ok = true;
            }
        }
    }
    if (!ok && enable) {
        const toggle = document.getElementById('settings-notif-toggle');
        if (toggle) toggle.checked = false;
    }
    refreshRestAlertUI();
}

// ── Rest timers ─────────────────────────────────────────────────────────────
async function saveRestTimer(key, value) {
    const fd = new FormData();
    fd.append('action', 'update_setting');
    fd.append('key', key);
    fd.append('value', value);
    await fetch('/forge/core/api.php', { method: 'POST', body: fd });
}
function updateRestDisplay(id, seconds) {
    const m = String(Math.floor(seconds / 60)).padStart(2, '0');
    const s = String(seconds % 60).padStart(2, '0');
    document.getElementById(id).textContent = m + ':' + s;
}

// ── Warmup editor ────────────────────────────────────────────────────────────
function buildWarmupEditorHTML(cat, presets) {
    const rows = Array.isArray(presets) ? presets : [];
    const pctOpts = ['null','50','55','60','65','70','75','80','85','90','95'];
    const pctLabel = { 'null':'Bar', '50':'50%', '55':'55%', '60':'60%', '65':'65%', '70':'70%', '75':'75%', '80':'80%', '85':'85%', '90':'90%', '95':'95%' };
    let html = '<div class="d-flex flex-column gap-2">';
    rows.forEach((s, i) => {
        const pctVal = (s && s.pct != null) ? String(s.pct) : 'null';
        html += `<div class="d-flex align-items-center gap-2">
            <span class="text-muted" style="font-size:.65rem;width:10px;text-align:center;flex-shrink:0;">${i + 1}</span>
            <select class="form-select" data-idx="${i}"
                    onchange="onWarmupRowChange('${cat}', ${i}, 'pct', this.value)"
                    style="font-size:.78rem;min-height:32px;flex:1;">
                ${pctOpts.map(o => `<option value="${o}"${pctVal === o ? ' selected' : ''}>${pctLabel[o]}</option>`).join('')}
            </select>
            <span class="text-muted" style="font-size:.8rem;flex-shrink:0;">×</span>
            <input type="number" class="form-control" data-idx="${i}"
                   value="${s && s.reps != null ? s.reps : 5}" inputmode="numeric" min="1" max="99"
                   onchange="onWarmupRowChange('${cat}', ${i}, 'reps', this.value)"
                   style="width:52px;font-size:.8rem;min-height:32px;text-align:center;">
            <span class="text-muted" style="font-size:.65rem;flex-shrink:0;">reps</span>
            <button class="btn-icon" onclick="removeWarmupRow('${cat}', ${i})" type="button"
                    style="flex-shrink:0;" aria-label="Remove">
                <i class="bi bi-x-lg" style="font-size:1rem;color:#555;"></i>
            </button>
        </div>`;
    });
    html += `<button class="btn btn-sm mt-1" onclick="addWarmupRow('${cat}')" type="button"
                  style="background:#1e1e1e;border:1px solid #333;color:#888;font-size:.75rem;min-height:32px;">
               <i class="bi bi-plus me-1"></i>ADD SET
             </button>`;
    html += '</div>';
    return html;
}

function addWarmupRow(cat) {
    const presets = Array.isArray(window.forgeWarmupPresets[cat]) ? window.forgeWarmupPresets[cat] : [];
    if (presets.length >= 5) return;
    presets.push({ pct: 50, reps: 5 });
    window.forgeWarmupPresets[cat] = presets;
    document.getElementById('warmup-editor-' + cat).innerHTML = buildWarmupEditorHTML(cat, presets);
    saveWarmupPresets(cat);
}

function removeWarmupRow(cat, idx) {
    const presets = (Array.isArray(window.forgeWarmupPresets[cat]) ? window.forgeWarmupPresets[cat] : [])
        .filter((_, i) => i !== idx);
    window.forgeWarmupPresets[cat] = presets;
    document.getElementById('warmup-editor-' + cat).innerHTML = buildWarmupEditorHTML(cat, presets);
    saveWarmupPresets(cat);
}

function onWarmupRowChange(cat, idx, field, value) {
    const presets = Array.isArray(window.forgeWarmupPresets[cat]) ? window.forgeWarmupPresets[cat] : [];
    if (!presets[idx]) presets[idx] = { pct: 50, reps: 5 };
    if (field === 'pct') {
        presets[idx].pct = value === 'null' ? null : (value !== '' ? parseInt(value) : null);
    } else if (field === 'reps') {
        presets[idx].reps = value !== '' ? parseInt(value) : 5;
    }
    window.forgeWarmupPresets[cat] = presets;
    saveWarmupPresets(cat);
}

async function saveWarmupPresets(cat) {
    const presets = window.forgeWarmupPresets[cat];
    const key = 'warmup_presets_' + cat;
    const val = Array.isArray(presets) && presets.length ? JSON.stringify(presets) : '';
    await saveSetting(key, val);
}

// Render all warmup editors on page load (always visible, no collapse)
['compound', 'bodyweight', 'accessory'].forEach(cat => {
    const el = document.getElementById('warmup-editor-' + cat);
    if (el) {
        const presets = window.forgeWarmupPresets && window.forgeWarmupPresets[cat];
        el.innerHTML = buildWarmupEditorHTML(cat, Array.isArray(presets) ? presets : null);
    }
});

// ── Max HR measurement via BLE ────────────────────────────────────────────────
(function () {
    const HR_SERVICE        = 0x180D;
    const HR_CHARACTERISTIC = 0x2A37;

    let bleDevice = null;
    let bleChar   = null;
    let peakBpm   = 0;
    let timerInterval = null;
    let elapsedSec = 0;
    let panelOpen  = false;

    const panel     = document.getElementById('maxHrMeasurePanel');
    const btn       = document.getElementById('maxHrMeasureBtn');
    const statusEl  = document.getElementById('maxHrMeasStatus');
    const liveBpmEl = document.getElementById('maxHrMeasBpm');
    const peakEl    = document.getElementById('maxHrMeasPeak');
    const timerEl   = document.getElementById('maxHrTimer');
    const hintEl    = document.getElementById('maxHrMeasHint');
    const stopBtn   = document.getElementById('maxHrStopBtn');
    const inputEl   = document.getElementById('maxHrInput');

    if (!panel) return;

    function parseHR(value) {
        const flags = value.getUint8(0);
        return (flags & 0x01) ? value.getUint16(1, true) : value.getUint8(1);
    }

    function fmtTime(s) {
        return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    }

    function onReading(event) {
        const bpm = parseHR(event.target.value);
        if (bpm < 20 || bpm > 250) return;
        liveBpmEl.textContent = bpm;
        if (bpm > peakBpm) {
            peakBpm = bpm;
            peakEl.textContent = bpm;
        }
        // Colour live BPM by zone
        const pct = bpm / (parseInt(inputEl.max) || 220);
        const colour = pct > 0.85 ? '#f44336' : pct > 0.70 ? '#FF9800' : '#FF5722';
        liveBpmEl.style.color = colour;
    }

    function startTimer() {
        elapsedSec = 0;
        timerEl.textContent = fmtTime(0);
        timerInterval = setInterval(() => {
            elapsedSec++;
            timerEl.textContent = fmtTime(elapsedSec);
            // At 2 min, nudge user (don't auto-stop — they may still be climbing)
            if (elapsedSec === 120) {
                hintEl.textContent = '2 min reached — tap STOP & SAVE when ready.';
            }
        }, 1000);
    }

    async function disconnect() {
        clearInterval(timerInterval);
        timerInterval = null;
        if (bleChar) {
            try { await bleChar.stopNotifications(); } catch (_) {}
            bleChar.removeEventListener('characteristicvaluechanged', onReading);
            bleChar = null;
        }
        if (bleDevice && bleDevice.gatt.connected) bleDevice.gatt.disconnect();
        bleDevice = null;
    }

    window.stopMaxHrMeasure = async function () {
        await disconnect();
        if (peakBpm > 0) {
            inputEl.value = peakBpm;
            await api('log_hr_measurement', { type: 'max', bpm: peakBpm });
            statusEl.textContent = 'SAVED — ' + peakBpm + ' BPM PEAK';
            statusEl.style.color = '#4CAF50';
            hintEl.textContent   = 'Peak saved to profile. Tap MEASURE again anytime to retest.';
        }
        stopBtn.classList.add('d-none');
        if (btn) { btn.innerHTML = '<i class="bi bi-bluetooth me-1"></i>MEASURE'; btn.style.color = '#888'; }
    };

    async function startMeasure() {
        peakBpm    = 0;
        elapsedSec = 0;
        liveBpmEl.textContent = '--';
        peakEl.textContent    = '--';
        timerEl.textContent   = '0:00';
        liveBpmEl.style.color = '#FF5722';
        hintEl.textContent    = 'Connect your monitor, then go all-out — sprint, cycle, row hard. Aim for 2 min. Your peak will be saved.';

        statusEl.textContent = 'SCANNING…';
        statusEl.style.color = '#FFC107';
        if (btn) btn.style.color = '#FFC107';

        try {
            bleDevice = await navigator.bluetooth.requestDevice({
                filters: [{ services: [HR_SERVICE] }],
                optionalServices: [HR_SERVICE],
            });
            bleDevice.addEventListener('gattserverdisconnected', async () => {
                clearInterval(timerInterval);
                statusEl.textContent = 'DISCONNECTED';
                statusEl.style.color = '#f44336';
                if (btn) { btn.innerHTML = '<i class="bi bi-bluetooth me-1"></i>MEASURE'; btn.style.color = '#888'; }
                // Auto-save peak if we got readings
                if (peakBpm > 0) {
                    inputEl.value = peakBpm;
                    await api('log_hr_measurement', { type: 'max', bpm: peakBpm });
                    statusEl.textContent = 'DISCONNECTED — PEAK ' + peakBpm + ' SAVED';
                }
                stopBtn.classList.add('d-none');
            });

            statusEl.textContent = 'CONNECTING…';
            const server  = await bleDevice.gatt.connect();
            const service = await server.getPrimaryService(HR_SERVICE);
            bleChar        = await service.getCharacteristic(HR_CHARACTERISTIC);
            await bleChar.startNotifications();
            bleChar.addEventListener('characteristicvaluechanged', onReading);

            statusEl.textContent = 'GO — PUSH HARD';
            statusEl.style.color = '#f44336';
            hintEl.textContent   = 'Give it everything. Aim for at least 2 minutes.';
            stopBtn.classList.remove('d-none');
            startTimer();
            if (btn) btn.innerHTML = '<i class="bi bi-x me-1"></i>CANCEL';

        } catch (err) {
            clearInterval(timerInterval);
            if (err.name !== 'NotFoundError' && err.name !== 'AbortError') {
                statusEl.textContent = 'ERROR';
                statusEl.style.color = '#f44336';
                hintEl.textContent   = err.message || 'Could not connect.';
            } else {
                statusEl.textContent = 'NOT CONNECTED';
                statusEl.style.color = '#555';
            }
            if (btn) { btn.innerHTML = '<i class="bi bi-bluetooth me-1"></i>MEASURE'; btn.style.color = '#888'; }
            bleDevice = null;
        }
    }

    window.toggleMaxHrMeasure = function () {
        panelOpen = !panelOpen;
        panel.classList.toggle('d-none', !panelOpen);
        if (panelOpen) {
            if (navigator.bluetooth) {
                startMeasure();
            } else {
                statusEl.textContent = 'WEB BLUETOOTH NOT SUPPORTED';
                statusEl.style.color = '#f44336';
                hintEl.textContent   = 'Use Chrome on Android or desktop.';
            }
        } else {
            disconnect();
            statusEl.textContent = 'NOT CONNECTED';
            statusEl.style.color = '#555';
            stopBtn.classList.add('d-none');
            if (btn) { btn.innerHTML = '<i class="bi bi-bluetooth me-1"></i>MEASURE'; btn.style.color = '#888'; }
        }
    };
})();

// ── Trend charts ──────────────────────────────────────────────────────────────
try { (function () {
    const CHART_DEFAULTS = {
        type: 'line',
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: { legend: { display: false }, tooltip: {
                callbacks: { label: ctx => ctx.parsed.y }
            }},
            scales: {
                x: { display: false },
                y: {
                    grid: { color: '#1f1f1f' },
                    ticks: { color: '#444', font: { size: 10, weight: '700' }, maxTicksLimit: 3 },
                }
            }
        }
    };

    function mkDataset(data, color) {
        return {
            data,
            borderColor: color,
            borderWidth: 2,
            pointRadius: data.length <= 10 ? 3 : 0,
            pointBackgroundColor: color,
            fill: true,
            backgroundColor: color + '18',
            tension: 0.35,
        };
    }

    <?php if (count($bw_history) >= 2): ?>
    new Chart(document.getElementById('bwChart'), {
        ...CHART_DEFAULTS,
        data: {
            datasets: [mkDataset(<?= json_encode(array_map(fn($r) => ['x' => strtotime($r['date']) * 1000, 'y' => (float)$r['bodyweight_kg']], $bw_history)) ?>, '#FF5722')]
        },
        options: { ...CHART_DEFAULTS.options, scales: { ...CHART_DEFAULTS.options.scales,
            x: { type: 'linear', display: true, grid: { color: '#1f1f1f' }, ticks: { color: '#888', maxTicksLimit: 5, font: { size: 9 }, callback: value => new Date(value).toLocaleDateString(undefined, { month: 'short', year: '2-digit' }) } },
            y: { ...CHART_DEFAULTS.options.scales.y,
                min: Math.min(...<?= json_encode(array_column($bw_history, 'bodyweight_kg')) ?>) - 2,
                max: Math.max(...<?= json_encode(array_column($bw_history, 'bodyweight_kg')) ?>) + 2,
            }
        }, plugins: { legend: { display: false }, tooltip: { callbacks: { title: items => new Date(items[0].parsed.x).toLocaleDateString(), label: ctx => ctx.parsed.y + ' kg' } } }}
    });
    <?php endif; ?>

    <?php if (count($resting_hr_history) >= 2): ?>
    new Chart(document.getElementById('restingHrChart'), {
        ...CHART_DEFAULTS,
        data: {
            labels: <?= json_encode(array_column($resting_hr_history, 'date')) ?>,
            datasets: [mkDataset(<?= json_encode(array_column($resting_hr_history, 'bpm')) ?>, '#42a5f5')]
        },
        options: { ...CHART_DEFAULTS.options, scales: { ...CHART_DEFAULTS.options.scales,
            y: { ...CHART_DEFAULTS.options.scales.y,
                min: Math.min(...<?= json_encode(array_column($resting_hr_history, 'bpm')) ?>) - 4,
                max: Math.max(...<?= json_encode(array_column($resting_hr_history, 'bpm')) ?>) + 4,
            }
        }}
    });
    <?php endif; ?>

    <?php if (count($max_hr_history) >= 2): ?>
    new Chart(document.getElementById('maxHrChart'), {
        ...CHART_DEFAULTS,
        data: {
            labels: <?= json_encode(array_column($max_hr_history, 'date')) ?>,
            datasets: [mkDataset(<?= json_encode(array_column($max_hr_history, 'bpm')) ?>, '#f44336')]
        },
        options: { ...CHART_DEFAULTS.options, scales: { ...CHART_DEFAULTS.options.scales,
            y: { ...CHART_DEFAULTS.options.scales.y,
                min: Math.min(...<?= json_encode(array_column($max_hr_history, 'bpm')) ?>) - 5,
                max: Math.max(...<?= json_encode(array_column($max_hr_history, 'bpm')) ?>) + 5,
            }
        }}
    });
    <?php endif; ?>
})(); } catch (e) { console.warn('FORGE: chart render failed', e); }

// ── Init ──────────────────────────────────────────────────────────────────────
refreshRestAlertUI();

// ── Resting HR measurement via BLE ───────────────────────────────────────────
(function () {
    const HR_SERVICE        = 0x180D;
    const HR_CHARACTERISTIC = 0x2A37;
    const SAMPLE_TARGET     = 30;

    let bleDevice = null;
    let bleChar   = null;
    let samples   = [];
    let panelOpen = false;

    const panel      = document.getElementById('hrMeasurePanel');
    const btn        = document.getElementById('hrMeasureBtn');
    const statusEl   = document.getElementById('hrMeasStatus');
    const bpmEl      = document.getElementById('hrMeasBpm');
    const heartEl    = document.getElementById('hrMeasHeart');
    const progressEl = document.getElementById('hrMeasProgress');
    const hintEl     = document.getElementById('hrMeasHint');
    const inputEl    = document.getElementById('restingHrInput');

    function parseHR(value) {
        const flags = value.getUint8(0);
        return (flags & 0x01) ? value.getUint16(1, true) : value.getUint8(1);
    }

    function setStatus(text, color) {
        statusEl.textContent = text;
        statusEl.style.color = color || '#555';
    }

    async function onReading(event) {
        const bpm = parseHR(event.target.value);
        if (bpm < 20 || bpm > 200) return;

        bpmEl.textContent = bpm;
        heartEl.style.opacity = '1';
        heartEl.style.animation = 'hrMeasBeat ' + (60000 / bpm).toFixed(0) + 'ms ease-in-out infinite';

        samples.push(bpm);
        const pct = Math.min(100, (samples.length / SAMPLE_TARGET) * 100);
        progressEl.style.width = pct + '%';

        if (samples.length < SAMPLE_TARGET) {
            const remaining = SAMPLE_TARGET - samples.length;
            hintEl.textContent = 'Sit still… ' + remaining + ' more reading' + (remaining === 1 ? '' : 's');
        } else {
            // Enough samples — compute average, save setting + historical record
            const avg = Math.round(samples.reduce((a, b) => a + b, 0) / samples.length);
            inputEl.value = avg;
            await api('log_hr_measurement', { type: 'resting', bpm: avg });
            setStatus('SAVED — ' + avg + ' BPM', '#4CAF50');
            hintEl.textContent = 'Average of ' + samples.length + ' readings saved to your profile.';
            progressEl.style.background = '#4CAF50';
            stopMeasure();
        }
    }

    async function stopMeasure() {
        if (bleChar) {
            try { await bleChar.stopNotifications(); } catch (_) {}
            bleChar.removeEventListener('characteristicvaluechanged', onReading);
            bleChar = null;
        }
        if (bleDevice && bleDevice.gatt.connected) bleDevice.gatt.disconnect();
        bleDevice = null;
        if (btn) {
            btn.innerHTML = '<i class="bi bi-bluetooth me-1"></i>MEASURE';
            btn.style.color = '#888';
        }
    }

    async function startMeasure() {
        samples = [];
        progressEl.style.width = '0%';
        progressEl.style.background = '#FF5722';
        bpmEl.textContent = '--';
        heartEl.style.opacity = '0.2';
        heartEl.style.animation = '';
        hintEl.textContent = 'Sit still and breathe normally — ' + SAMPLE_TARGET + ' readings needed';

        setStatus('SCANNING…', '#FFC107');
        if (btn) btn.style.color = '#FFC107';

        try {
            bleDevice = await navigator.bluetooth.requestDevice({
                filters: [{ services: [HR_SERVICE] }],
                optionalServices: [HR_SERVICE],
            });
            bleDevice.addEventListener('gattserverdisconnected', () => {
                setStatus('DISCONNECTED', '#f44336');
                if (btn) { btn.innerHTML = '<i class="bi bi-bluetooth me-1"></i>MEASURE'; btn.style.color = '#888'; }
                heartEl.style.opacity = '0.2';
                heartEl.style.animation = '';
            });

            setStatus('CONNECTING…', '#FFC107');
            const server  = await bleDevice.gatt.connect();
            const service = await server.getPrimaryService(HR_SERVICE);
            bleChar        = await service.getCharacteristic(HR_CHARACTERISTIC);
            await bleChar.startNotifications();
            bleChar.addEventListener('characteristicvaluechanged', onReading);
            setStatus('READING…', '#4CAF50');
            if (btn) btn.innerHTML = '<i class="bi bi-x me-1"></i>CANCEL';

        } catch (err) {
            if (err.name !== 'NotFoundError' && err.name !== 'AbortError') {
                setStatus('ERROR', '#f44336');
                hintEl.textContent = err.message || 'Could not connect to HR monitor.';
            } else {
                setStatus('NOT CONNECTED', '');
            }
            if (btn) { btn.innerHTML = '<i class="bi bi-bluetooth me-1"></i>MEASURE'; btn.style.color = '#888'; }
            bleDevice = null;
        }
    }

    window.toggleHrMeasure = function () {
        if (!panel) return;
        panelOpen = !panelOpen;
        panel.classList.toggle('d-none', !panelOpen);
        if (panelOpen) {
            // Only auto-start if BT is available
            if (navigator.bluetooth) {
                startMeasure();
            } else {
                setStatus('WEB BLUETOOTH NOT SUPPORTED', '#f44336');
                hintEl.textContent = 'Use Chrome on Android or desktop.';
            }
        } else {
            stopMeasure();
            setStatus('NOT CONNECTED', '');
            bpmEl.textContent = '--';
        }
    };
})();
</script>

</body>
</html>
