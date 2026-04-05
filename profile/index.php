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
        // US Navy formula
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


$page_title   = 'Profile';
$page_context = 'profile';
include __DIR__ . '/../core/page_head.php';
?>

<div class="container-fluid py-3 px-3" style="max-width:600px;margin:0 auto;">

    <h1 class="fw-black mb-4" style="font-size:1.6rem;letter-spacing:-1.5px;">
        PROFILE<span style="color:#FF5722;">_</span>
    </h1>

    <!-- ── IDENTITY ─────────────────────────────────────────────────────── -->
    <div class="stat-label mb-2">IDENTITY</div>
    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12">
                    <label class="stat-label d-block mb-1" style="font-size:0.6rem;">NAME</label>
                    <input type="text" id="p-name" class="form-control"
                           value="<?= htmlspecialchars($profile['name']) ?>"
                           placeholder="Your name" autocomplete="off"
                           onchange="saveSetting('profile_name', this.value)">
                </div>
                <div class="col-6">
                    <label class="stat-label d-block mb-1" style="font-size:0.6rem;">SEX</label>
                    <div class="d-flex gap-2">
                        <?php foreach (['M' => 'MALE', 'F' => 'FEMALE'] as $val => $label): ?>
                        <button class="btn flex-grow-1 sex-btn <?= $profile['sex'] === $val ? 'btn-forge' : '' ?>"
                                data-val="<?= $val ?>"
                                style="<?= $profile['sex'] !== $val ? 'background:#1a1a1a;border:1px solid #333;color:#888;' : '' ?>font-weight:900;font-size:0.8rem;letter-spacing:0.5px;"
                                onclick="setSex('<?= $val ?>')">
                            <?= $label ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-6">
                    <label class="stat-label d-block mb-1" style="font-size:0.6rem;">DATE OF BIRTH</label>
                    <input type="date" id="p-dob" class="form-control"
                           value="<?= htmlspecialchars($profile['dob']) ?>"
                           onchange="saveSetting('profile_dob', this.value)">
                    <?php if ($age !== null): ?>
                    <div class="text-muted mt-1" style="font-size:0.68rem;"><?= $age ?> years old</div>
                    <?php endif; ?>
                </div>
                <div class="col-12">
                    <label class="stat-label d-block mb-1" style="font-size:0.6rem;">HEIGHT</label>
                    <div class="d-flex align-items-center gap-2">
                        <input type="number" id="p-height" class="form-control" inputmode="decimal"
                               step="0.5" min="100" max="250"
                               value="<?= htmlspecialchars($profile['height_cm']) ?>"
                               placeholder="e.g. 178"
                               onchange="saveSetting('profile_height_cm', this.value)">
                        <span class="text-muted" style="font-size:0.85rem;flex-shrink:0;">cm</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── BIOMETRICS ───────────────────────────────────────────────────── -->
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

    <!-- ── LOG MEASUREMENT ──────────────────────────────────────────────── -->
    <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="stat-label">BODY MEASUREMENTS</div>
        <button class="btn btn-forge btn-sm px-3" data-bs-toggle="modal" data-bs-target="#logMeasModal">
            <i class="bi bi-plus-lg me-1"></i>LOG
        </button>
    </div>

    <!-- Derived summary from most recent measurement -->
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

    <!-- Measurement history -->
    <?php if (!empty($measurements)): ?>
    <div class="card mb-3">
        <div class="card-body p-0">
        <?php foreach ($measurements as $i => $m):
            // Compute Navy BF for this entry
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

    <!-- ── REST ALERTS ─────────────────────────────────────────────────── -->
    <div class="stat-label mb-2">REST ALERTS</div>
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div>
                    <div class="stat-label" style="font-size:0.62rem;">BELL SOUND</div>
                    <div class="text-muted" style="font-size:0.68rem;">Play alert tone when rest hits zero</div>
                </div>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="bell-toggle"
                           style="width:2.5em;height:1.4em;cursor:pointer;accent-color:#FF5722;"
                           onchange="setBellToggle(this.checked)">
                </div>
            </div>
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label" style="font-size:0.62rem;">SYSTEM NOTIFICATION</div>
                    <div class="text-muted" style="font-size:0.68rem;">Popup you can tap to return to workout</div>
                </div>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="profile-notif-toggle"
                           style="width:2.5em;height:1.4em;cursor:pointer;accent-color:#FF5722;"
                           onchange="setProfileNotificationToggle(this.checked)">
                </div>
            </div>
            <div id="profile-notif-status" style="font-size:0.65rem;color:#666;margin-top:8px;"></div>
        </div>
    </div>

    <div class="text-center text-muted mb-4" style="font-size:0.65rem;letter-spacing:1px;">
        FORGE · SELF-HOSTED · <a href="/spence/" style="color:#666;">SPENCE ↗</a>
    </div>

</div>

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
    const bell = document.getElementById('bell-toggle');
    const notif = document.getElementById('profile-notif-toggle');
    const status = document.getElementById('profile-notif-status');
    if (!bell || !notif || !status) return;

    bell.checked = !!prefs.sound;
    const enabled = !!prefs.notifications && prefs.permission === 'granted';
    notif.checked = enabled;

    if (prefs.permission === 'denied') {
        status.textContent = 'Notifications blocked in browser settings.';
        status.style.color = '#ef5350';
        notif.disabled = true;
    } else if (prefs.permission === 'unsupported') {
        status.textContent = 'Notifications are not supported in this browser.';
        status.style.color = '#888';
        notif.disabled = true;
    } else {
        status.textContent = enabled ? 'Enabled.' : '';
        status.style.color = '#666';
        notif.disabled = false;
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
        const toggle = document.getElementById('profile-notif-toggle');
        if (toggle) toggle.checked = false;
    }
    refreshRestAlertUI();
}

refreshRestAlertUI();
</script>

</body>
</html>
