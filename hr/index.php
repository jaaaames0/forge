<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/db_helper.php';

$db = get_db_connection();

$max_hr     = (int)(get_user_setting($db, 'profile_max_hr', 0) ?: 0);
$resting_hr = (int)(get_user_setting($db, 'profile_resting_hr', 0) ?: 0);
$dob        = get_user_setting($db, 'profile_dob', '');
$age        = null;
if ($dob) {
    try { $age = (int)(new DateTime())->diff(new DateTime($dob))->y; } catch (Exception $e) {}
}
// Best estimate of max HR: stored value → 220−age → fallback 190
$est_max_hr = $max_hr ?: ($age ? 220 - $age : 190);

$page_title   = 'Heart Rate';
$page_context = 'hr';
$extra_head   = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';
$extra_styles = '<style>
/* ── HR Demo ─────────────────────────────────────── */
.hr-page {
    min-height: calc(100vh - 80px);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
    padding: 1.5rem 1rem 6rem;
}

/* Status pill */
.hr-status {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.3rem 0.85rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    background: #222;
    color: #888;
    border: 1px solid #333;
    transition: all 0.3s;
}
.hr-status.connecting { color: #FFC107; border-color: #FFC107; background: rgba(255,193,7,0.1); }
.hr-status.connected  { color: #4CAF50; border-color: #4CAF50; background: rgba(76,175,80,0.1); }
.hr-status.error      { color: #f44336; border-color: #f44336; background: rgba(244,67,54,0.1); }
.status-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: currentColor;
}
.status-dot.pulse-dot {
    animation: statusPulse 1.4s ease-in-out infinite;
}
@keyframes statusPulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: 0.3; }
}

/* Big BPM display */
.bpm-display {
    text-align: center;
    margin: 2rem 0 1rem;
}
.bpm-number {
    font-size: clamp(5rem, 28vw, 9rem);
    font-weight: 900;
    line-height: 1;
    color: #FF5722;
    font-variant-numeric: tabular-nums;
    letter-spacing: -4px;
    transition: color 0.5s;
}
.bpm-number.dim { color: #333; }
.bpm-unit {
    font-size: 1rem;
    font-weight: 700;
    color: #555;
    text-transform: uppercase;
    letter-spacing: 0.15em;
    margin-top: 0.25rem;
}

/* Heart icon */
.heart-wrap {
    margin: 0.5rem 0 1.5rem;
    text-align: center;
}
.heart-icon {
    font-size: 3.5rem;
    color: #FF5722;
    display: inline-block;
    opacity: 0.25;
    transition: opacity 0.4s;
}
.heart-icon.alive {
    opacity: 1;
    animation: heartbeat var(--beat-dur, 0.8s) ease-in-out infinite;
}
@keyframes heartbeat {
    0%   { transform: scale(1);    }
    14%  { transform: scale(1.25); }
    28%  { transform: scale(1);    }
    42%  { transform: scale(1.15); }
    70%  { transform: scale(1);    }
    100% { transform: scale(1);    }
}

/* HR zone badge */
.zone-badge {
    display: inline-block;
    padding: 0.2rem 0.75rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    background: #222;
    color: #555;
    border: 1px solid #333;
    transition: all 0.4s;
    margin-bottom: 1.5rem;
}

/* Stats row */
.hr-stats {
    display: flex;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
    justify-content: center;
}
.hr-stat {
    text-align: center;
}
.hr-stat-val {
    font-size: 1.4rem;
    font-weight: 900;
    color: #e0e0e0;
    font-variant-numeric: tabular-nums;
}
.hr-stat-val.dim { color: #333; }
.hr-stat-label {
    font-size: 0.62rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #555;
    margin-top: 2px;
}

/* Chart */
.chart-wrap {
    width: 100%;
    max-width: 480px;
    background: #1a1a1a;
    border: 1px solid #2a2a2a;
    border-radius: 12px;
    padding: 1rem 1rem 0.5rem;
    margin-bottom: 1rem;
}
.chart-wrap canvas { display: block; }

/* Device name */
.device-name {
    font-size: 0.72rem;
    color: #444;
    text-align: center;
    margin-bottom: 0.5rem;
    font-family: monospace;
}

/* Connect button — fixed bottom, full width */
.hr-btn-bar {
    position: fixed;
    bottom: 0; left: 0; right: 0;
    padding: 1rem;
    background: linear-gradient(to top, #0a0a0a 70%, transparent);
    display: flex;
    justify-content: center;
}
#connectBtn {
    min-height: 52px;
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    border-radius: 12px;
    width: 100%;
    max-width: 340px;
    border: none;
    background: #FF5722;
    color: #fff;
    transition: background 0.2s, transform 0.1s;
}
#connectBtn:active { transform: scale(0.97); }
#connectBtn.connected { background: #333; color: #888; border: 1px solid #444; }
#connectBtn:disabled  { background: #222; color: #444; }

/* No-BT warning */
.no-bt-warning {
    max-width: 340px;
    text-align: center;
    padding: 1.5rem 1rem;
}
</style>';

include __DIR__ . '/../core/page_head.php';
?>

<div class="hr-page">

    <!-- Status -->
    <div id="statusPill" class="hr-status">
        <span class="status-dot" id="statusDot"></span>
        <span id="statusText">NOT CONNECTED</span>
    </div>

    <!-- Big BPM -->
    <div class="bpm-display">
        <div class="bpm-number dim" id="bpmNumber">--</div>
        <div class="bpm-unit">BPM</div>
    </div>

    <!-- Beating heart -->
    <div class="heart-wrap">
        <i class="bi bi-heart-fill heart-icon" id="heartIcon"></i>
    </div>

    <!-- Zone -->
    <div class="zone-badge" id="zoneBadge">ZONE —</div>

    <!-- Stats: min / avg / max -->
    <div class="hr-stats">
        <div class="hr-stat">
            <div class="hr-stat-val dim" id="statMin">--</div>
            <div class="hr-stat-label">MIN</div>
        </div>
        <div class="hr-stat">
            <div class="hr-stat-val dim" id="statAvg">--</div>
            <div class="hr-stat-label">AVG</div>
        </div>
        <div class="hr-stat">
            <div class="hr-stat-val dim" id="statMax">--</div>
            <div class="hr-stat-label">MAX</div>
        </div>
    </div>

    <!-- Rolling HR chart — last 60 samples (~1 min) -->
    <div class="chart-wrap">
        <canvas id="hrChart" height="80"></canvas>
    </div>

    <div class="device-name" id="deviceName">&nbsp;</div>

    <!-- No Web Bluetooth fallback — hidden by default, shown via JS -->
    <div class="no-bt-warning text-muted d-none" id="noBtWarning">
        <i class="bi bi-bluetooth" style="font-size: 2rem; display: block; margin-bottom: 0.5rem; color: #444;"></i>
        <strong class="d-block mb-1" style="color: #888;">Web Bluetooth not available</strong>
        Use <strong>Chrome</strong> (or Chrome-based browser) on Android or desktop.<br>
        Safari and Firefox do not support Web Bluetooth.
    </div>

</div>

<!-- Connect button -->
<div class="hr-btn-bar">
    <button id="connectBtn">
        <i class="bi bi-bluetooth me-2"></i>CONNECT MONITOR
    </button>
</div>

<script>
// ── Constants ────────────────────────────────────────────────────────────────
const HR_SERVICE        = 0x180D;
const HR_CHARACTERISTIC = 0x2A37;
const MAX_SAMPLES       = 60;

// HR zone thresholds (% of max HR)
// We'll use a generic 190 bpm max if profile doesn't provide one
const MAX_HR = <?= $est_max_hr ?>;

const ZONES = [
    { name: 'REST',    min: 0,    max: 0.50, color: '#607D8B', label: 'ZONE 1 — REST' },
    { name: 'FAT BURN',min: 0.50, max: 0.60, color: '#4CAF50', label: 'ZONE 2 — FAT BURN' },
    { name: 'AEROBIC', min: 0.60, max: 0.70, color: '#2196F3', label: 'ZONE 3 — AEROBIC' },
    { name: 'ANAEROBIC',min:0.70, max: 0.85, color: '#FF9800', label: 'ZONE 4 — ANAEROBIC' },
    { name: 'MAX',     min: 0.85, max: 1.00, color: '#f44336', label: 'ZONE 5 — MAX EFFORT' },
];

// ── State ────────────────────────────────────────────────────────────────────
let device      = null;
let server      = null;
let characteristic = null;
let samples     = [];   // { bpm, ts }
let sessionMin  = Infinity;
let sessionMax  = 0;

// ── DOM refs ─────────────────────────────────────────────────────────────────
const bpmEl      = document.getElementById('bpmNumber');
const heartEl    = document.getElementById('heartIcon');
const statusPill = document.getElementById('statusPill');
const statusDot  = document.getElementById('statusDot');
const statusText = document.getElementById('statusText');
const zoneBadge  = document.getElementById('zoneBadge');
const statMin    = document.getElementById('statMin');
const statAvg    = document.getElementById('statAvg');
const statMax    = document.getElementById('statMax');
const deviceName = document.getElementById('deviceName');
const connectBtn = document.getElementById('connectBtn');

// ── Chart setup ──────────────────────────────────────────────────────────────
const ctx = document.getElementById('hrChart').getContext('2d');
const chart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: [],
        datasets: [{
            data: [],
            borderColor: '#FF5722',
            borderWidth: 2,
            pointRadius: 0,
            fill: true,
            backgroundColor: 'rgba(255,87,34,0.08)',
            tension: 0.3,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        plugins: { legend: { display: false }, tooltip: { enabled: false } },
        scales: {
            x: { display: false },
            y: {
                min: 40, max: 200,
                grid: { color: '#1f1f1f' },
                ticks: {
                    color: '#444',
                    font: { size: 10, weight: '700' },
                    maxTicksLimit: 4,
                }
            }
        }
    }
});

// ── Helpers ───────────────────────────────────────────────────────────────────
function setStatus(state, text) {
    statusPill.className = 'hr-status ' + state;
    statusText.textContent = text;
    statusDot.className = 'status-dot' + (state === 'connecting' ? ' pulse-dot' : '');
}

function getZone(bpm) {
    const pct = bpm / MAX_HR;
    return ZONES.find(z => pct >= z.min && pct < z.max) || ZONES[ZONES.length - 1];
}

function updateUI(bpm) {
    // BPM number
    bpmEl.textContent = bpm;
    bpmEl.classList.remove('dim');

    // Heart beat animation — one beat cycle = 60000ms / bpm
    const beatDur = (60000 / bpm).toFixed(0) + 'ms';
    heartEl.style.setProperty('--beat-dur', beatDur);
    heartEl.classList.add('alive');

    // Zone
    const zone = getZone(bpm);
    zoneBadge.textContent = zone.label;
    zoneBadge.style.background = zone.color + '22';
    zoneBadge.style.color       = zone.color;
    zoneBadge.style.borderColor = zone.color + '55';

    // Session stats
    sessionMin = Math.min(sessionMin, bpm);
    sessionMax = Math.max(sessionMax, bpm);
    const avg  = Math.round(samples.reduce((s, x) => s + x.bpm, 0) / samples.length);

    statMin.textContent = sessionMin === Infinity ? '--' : sessionMin;
    statAvg.textContent = samples.length ? avg : '--';
    statMax.textContent = sessionMax === 0 ? '--' : sessionMax;
    [statMin, statAvg, statMax].forEach(el => el.classList.remove('dim'));

    // Chart — adjust Y axis to fit data nicely
    const chartMin = Math.max(30, sessionMin - 10);
    const chartMax = Math.min(220, sessionMax + 10);
    chart.options.scales.y.min = chartMin;
    chart.options.scales.y.max = chartMax;
    chart.data.labels  = samples.map((_, i) => i);
    chart.data.datasets[0].data = samples.map(s => s.bpm);
    chart.update('none');
}

function resetUI() {
    bpmEl.textContent = '--';
    bpmEl.classList.add('dim');
    heartEl.classList.remove('alive');
    zoneBadge.textContent = 'ZONE —';
    zoneBadge.style = '';
    [statMin, statAvg, statMax].forEach(el => {
        el.textContent = '--';
        el.classList.add('dim');
    });
    deviceName.innerHTML = '&nbsp;';
    samples = [];
    sessionMin = Infinity;
    sessionMax = 0;
    chart.data.labels = [];
    chart.data.datasets[0].data = [];
    chart.update('none');
}

// ── Parse HR Measurement characteristic ──────────────────────────────────────
// Byte 0: flags. Bit 0: 0=uint8 HR, 1=uint16 HR
function parseHR(value) {
    const flags = value.getUint8(0);
    const is16bit = flags & 0x01;
    return is16bit ? value.getUint16(1, true) : value.getUint8(1);
}

function onHRNotification(event) {
    const bpm = parseHR(event.target.value);
    if (bpm < 20 || bpm > 250) return; // sanity filter

    samples.push({ bpm, ts: Date.now() });
    if (samples.length > MAX_SAMPLES) samples.shift();

    updateUI(bpm);
}

// ── Disconnect ────────────────────────────────────────────────────────────────
async function disconnect() {
    if (characteristic) {
        try { await characteristic.stopNotifications(); } catch (_) {}
        characteristic.removeEventListener('characteristicvaluechanged', onHRNotification);
        characteristic = null;
    }
    if (device && device.gatt.connected) {
        device.gatt.disconnect();
    }
    device  = null;
    server  = null;
    setStatus('', 'NOT CONNECTED');
    connectBtn.className = '';
    connectBtn.innerHTML = '<i class="bi bi-bluetooth me-2"></i>CONNECT MONITOR';
    resetUI();
}

// ── Connect ───────────────────────────────────────────────────────────────────
async function connect() {
    try {
        setStatus('connecting', 'SCANNING…');
        connectBtn.disabled = true;

        device = await navigator.bluetooth.requestDevice({
            filters: [{ services: [HR_SERVICE] }],
            // Also accept devices that advertise a name (some HR monitors don't advertise service UUIDs)
            optionalServices: [HR_SERVICE],
        });

        device.addEventListener('gattserverdisconnected', () => {
            setStatus('error', 'DISCONNECTED');
            connectBtn.disabled = false;
            connectBtn.className = '';
            connectBtn.innerHTML = '<i class="bi bi-bluetooth me-2"></i>RECONNECT';
            heartEl.classList.remove('alive');
        });

        setStatus('connecting', 'CONNECTING…');
        server = await device.gatt.connect();

        setStatus('connecting', 'READING…');
        const service = await server.getPrimaryService(HR_SERVICE);
        characteristic = await service.getCharacteristic(HR_CHARACTERISTIC);

        await characteristic.startNotifications();
        characteristic.addEventListener('characteristicvaluechanged', onHRNotification);

        setStatus('connected', 'CONNECTED');
        deviceName.textContent = device.name || 'Unknown device';
        connectBtn.disabled = false;
        connectBtn.className = 'connected';
        connectBtn.innerHTML = '<i class="bi bi-x-circle me-2"></i>DISCONNECT';

    } catch (err) {
        console.error(err);
        if (err.name === 'NotFoundError' || err.name === 'AbortError') {
            // User cancelled the picker — silently reset
            setStatus('', 'NOT CONNECTED');
        } else {
            setStatus('error', 'ERROR — TAP TO RETRY');
        }
        connectBtn.disabled = false;
        connectBtn.className = '';
        connectBtn.innerHTML = '<i class="bi bi-bluetooth me-2"></i>CONNECT MONITOR';
        device = null;
        server = null;
        characteristic = null;
    }
}

// ── Init ──────────────────────────────────────────────────────────────────────
if (!navigator.bluetooth) {
    document.getElementById('noBtWarning').classList.remove('d-none');
    connectBtn.disabled = true;
    connectBtn.innerHTML = '<i class="bi bi-bluetooth-fill me-2"></i>WEB BLUETOOTH NOT SUPPORTED';
} else {
    connectBtn.addEventListener('click', () => {
        if (device && device.gatt.connected) {
            disconnect();
        } else {
            connect();
        }
    });
}
</script>

<?php
// Footer close
echo '</body></html>';
?>
