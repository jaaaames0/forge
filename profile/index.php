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
                            <input type="number" class="form-control" inputmode="numeric"
                                   min="30" max="120" value="<?= htmlspecialchars($profile['resting_hr']) ?>"
                                   placeholder="e.g. 58"
                                   onchange="saveSetting('profile_resting_hr', this.value)">
                            <span class="text-muted" style="font-size:0.75rem;flex-shrink:0;">bpm</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="stat-label d-block mb-1" style="font-size:0.6rem;">MAX HR</label>
                        <div class="d-flex align-items-center gap-2">
                            <input type="number" class="form-control" inputmode="numeric"
                                   min="100" max="230" value="<?= htmlspecialchars($profile['max_hr']) ?>"
                                   placeholder="<?= $est_max_hr ? "~{$est_max_hr}" : 'e.g. 185' ?>"
                                   onchange="saveSetting('profile_max_hr', this.value)">
                            <span class="text-muted" style="font-size:0.75rem;flex-shrink:0;">bpm</span>
                        </div>
                        <?php if (!$profile['max_hr'] && $est_max_hr): ?>
                        <div class="text-muted mt-1" style="font-size:0.65rem;">Est. from age: <?= $est_max_hr ?> bpm</div>
                        <?php endif; ?>
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
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(data)) if (v !== null && v !== undefined && v !== '') fd.append(k, v);
    const r = await fetch('/forge/core/api.php', { method: 'POST', body: fd });
    return r.json();
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

// ── Init ──────────────────────────────────────────────────────────────────────
refreshRestAlertUI();
</script>

</body>
</html>
