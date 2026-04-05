<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/db_helper.php';

$db   = get_db_connection();
$unit = 'kg';

$template_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

// ---- TEMPLATE EDITOR ----
if ($template_id) {
    $stmt = $db->prepare("SELECT * FROM templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$template) { header("Location: /forge/templates/"); exit; }

    $stmt = $db->prepare("
        SELECT te.*, e.name as exercise_name, e.muscle_primary, e.category
        FROM template_exercises te
        JOIN exercises e ON e.id = te.exercise_id
        WHERE te.template_id = ?
        ORDER BY te.order_num
    ");
    $stmt->execute([$template_id]);
    $tex = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $page_title   = htmlspecialchars($template['name']);
    $page_context = 'templates';
    include __DIR__ . '/../core/page_head.php';
?>

<div class="container-fluid py-3 px-3" style="max-width:700px; margin:0 auto;">

    <a href="/forge/workout/" class="d-inline-flex align-items-center gap-1 mb-3 text-decoration-none"
       style="color:#888; font-size:0.8rem; font-weight:700; text-transform:uppercase; letter-spacing:1px;">
        <i class="bi bi-chevron-left"></i> WORKOUTS
    </a>

    <!-- Template name (editable) -->
    <div class="d-flex align-items-center gap-2 mb-1">
        <input type="text" id="tpl-name" class="form-control fw-black"
               value="<?= htmlspecialchars($template['name']) ?>"
               style="font-size:1.3rem; letter-spacing:-1px; background:transparent !important; border:none !important; border-bottom:1px solid #333 !important; border-radius:0 !important; padding:4px 0 !important; box-shadow:none !important;">
    </div>
    <div class="mb-3">
        <input type="text" id="tpl-notes" class="form-control"
               value="<?= htmlspecialchars($template['notes'] ?? '') ?>"
               placeholder="Notes (optional)"
               style="background:transparent !important; border:none !important; border-bottom:1px solid #1a1a1a !important; border-radius:0 !important; padding:4px 0 !important; font-size:0.85rem; color:#888 !important; box-shadow:none !important;">
    </div>

    <!-- Exercise list -->
    <div id="tex-list" class="mb-3">
    <?php foreach ($tex as $i => $te): ?>
    <div class="tex-row card mb-2" data-te-id="<?= $te['id'] ?>">
        <div class="card-body py-2 px-3">
            <div class="d-flex align-items-center gap-2">
                <!-- Reorder -->
                <div class="d-flex flex-column gap-0" style="flex-shrink:0;">
                    <button class="btn-icon py-0" style="min-height:24px; min-width:28px;" onclick="moveExercise(<?= $te['id'] ?>, -1)" type="button" <?= $i === 0 ? 'disabled' : '' ?>>
                        <i class="bi bi-chevron-up" style="font-size:0.8rem;"></i>
                    </button>
                    <button class="btn-icon py-0" style="min-height:24px; min-width:28px;" onclick="moveExercise(<?= $te['id'] ?>, 1)" type="button" <?= $i === count($tex)-1 ? 'disabled' : '' ?>>
                        <i class="bi bi-chevron-down" style="font-size:0.8rem;"></i>
                    </button>
                </div>
                <!-- Name -->
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex align-items-center gap-2">
                        <span style="font-weight:700; font-size:0.9rem;"><?= htmlspecialchars($te['exercise_name']) ?></span>
                        <?php if ($te['ab_group']): ?>
                        <span class="ab-badge" data-ab="<?= $te['ab_group'] ?>"><?= $te['ab_group'] ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted" style="font-size:0.72rem;">
                        <?php
                        $target = $te['target_sets'] ? $te['target_sets'] . ' × ' : '';
                        if ($te['target_reps_min'] && $te['target_reps_max'] && $te['target_reps_min'] != $te['target_reps_max'])
                            $target .= $te['target_reps_min'] . '–' . $te['target_reps_max'] . ' reps';
                        elseif ($te['target_reps_min'])
                            $target .= $te['target_reps_min'] . ' reps';
                        if ($te['target_rpe']) $target .= ' @ RPE ' . $te['target_rpe'];
                        echo $target ?: 'No targets set';
                        ?>
                        · <span style="color:#FF5722;"><?= htmlspecialchars($te['progression_scheme']) ?></span>
                    </div>
                </div>
                <!-- Edit toggle -->
                <button class="btn-icon" type="button"
                        data-bs-toggle="collapse" data-bs-target="#te-settings-<?= $te['id'] ?>">
                    <i class="bi bi-sliders" style="font-size:0.9rem;"></i>
                </button>
                <!-- Remove -->
                <button class="btn-icon" onclick="removeTemplateExercise(<?= $te['id'] ?>, this)" type="button">
                    <i class="bi bi-x-lg" style="font-size:0.9rem;"></i>
                </button>
            </div>

            <!-- Collapsible settings -->
            <?php $is_double = ($te['progression_scheme'] === 'double'); ?>
            <div class="collapse show mt-2 pt-2" id="te-settings-<?= $te['id'] ?>" style="border-top:1px solid #222;">
                <div class="row g-2">
                    <div class="col-6 col-sm-3">
                        <label class="stat-label d-block mb-1">SETS</label>
                        <input type="number" class="form-control form-control-sm te-sets" inputmode="numeric"
                               value="<?= $te['target_sets'] ?? '' ?>" placeholder="—" min="1">
                    </div>
                    <div class="col-6 col-sm-4 te-reps-single"<?= $is_double ? ' style="display:none;"' : '' ?>>
                        <label class="stat-label d-block mb-1">REPS</label>
                        <input type="number" class="form-control form-control-sm te-reps" inputmode="numeric"
                               value="<?= $te['target_reps_min'] ?? '' ?>" placeholder="—" min="1">
                    </div>
                    <div class="col-3 col-sm-2 te-reps-range"<?= !$is_double ? ' style="display:none;"' : '' ?>>
                        <label class="stat-label d-block mb-1">REPS MIN</label>
                        <input type="number" class="form-control form-control-sm te-reps-min" inputmode="numeric"
                               value="<?= $te['target_reps_min'] ?? '' ?>" placeholder="—" min="1">
                    </div>
                    <div class="col-3 col-sm-2 te-reps-range"<?= !$is_double ? ' style="display:none;"' : '' ?>>
                        <label class="stat-label d-block mb-1">REPS MAX</label>
                        <input type="number" class="form-control form-control-sm te-reps-max" inputmode="numeric"
                               value="<?= $te['target_reps_max'] ?? '' ?>" placeholder="—" min="1">
                    </div>
                    <div class="col-6 col-sm-2">
                        <label class="stat-label d-block mb-1">TARGET RPE</label>
                        <input type="number" class="form-control form-control-sm te-rpe" inputmode="decimal"
                               value="<?= $te['target_rpe'] ?? '' ?>" placeholder="—" step="0.5" min="6" max="10">
                    </div>
                    <div class="col-6 col-sm-3">
                        <label class="stat-label d-block mb-1">+<?= strtoupper($unit) ?> STEP</label>
                        <input type="number" class="form-control form-control-sm te-increment" inputmode="decimal"
                               value="<?= $te['progression_increment_kg'] ?? 2.5 ?>" step="0.5" min="0.5">
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-6">
                        <label class="stat-label d-block mb-1">PROGRESSION</label>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach (['linear', 'double', 'rpe'] as $scheme): ?>
                            <label class="d-flex align-items-center gap-1" style="cursor:pointer; font-size:0.8rem; font-weight:700; color:#888;">
                                <input type="radio" class="te-scheme" name="scheme-<?= $te['id'] ?>"
                                       value="<?= $scheme ?>" <?= $te['progression_scheme'] === $scheme ? 'checked' : '' ?>>
                                <?= strtoupper($scheme) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="stat-label d-block mb-1">A/B VARIANT</label>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach (['' => 'ALWAYS', 'A' => 'A ONLY', 'B' => 'B ONLY'] as $val => $label): ?>
                            <label class="d-flex align-items-center gap-1" style="cursor:pointer; font-size:0.8rem; font-weight:700; color:#888;">
                                <input type="radio" class="te-ab-group" name="ab-<?= $te['id'] ?>"
                                       value="<?= $val ?>" <?= ($te['ab_group'] ?? '') === $val ? 'checked' : '' ?>>
                                <?= $label ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($tex)): ?>
    <p class="text-muted text-center py-3" id="empty-msg">No exercises yet. Add one below.</p>
    <?php endif; ?>
    </div>

    <!-- Add exercise -->
    <button class="btn btn-forge-outline w-100 mb-3" type="button"
            data-bs-toggle="modal" data-bs-target="#addTeModal" onclick="loadExList()">
        <i class="bi bi-plus-lg me-1"></i>ADD EXERCISE
    </button>

    <button id="save-tpl-btn" class="btn btn-forge w-100" onclick="saveTemplate()" type="button"
            style="font-weight:900; font-size:0.85rem; letter-spacing:1px; min-height:48px;">
        SAVE TEMPLATE
    </button>
    <button class="btn w-100 mt-2" onclick="deleteCurrentTemplate()" type="button"
            style="background:transparent; border:1px solid #5a1f1f; color:#ef5350; font-weight:800; letter-spacing:0.6px; min-height:44px;">
        <i class="bi bi-trash me-1"></i>DELETE TEMPLATE
    </button>

</div>

<style>
.ab-badge {
    display: inline-block;
    font-size: 0.65rem;
    font-weight: 900;
    line-height: 1;
    padding: 2px 5px;
    border-radius: 3px;
    letter-spacing: 0.5px;
    flex-shrink: 0;
}
.ab-badge[data-ab="A"] { background: #1a3a5c; color: #64b5f6; }
.ab-badge[data-ab="B"] { background: #3a1a5c; color: #ce93d8; }
</style>

<!-- Add Exercise to Template Modal -->
<div class="modal fade" id="addTeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom:1px solid #333; padding:12px 16px;">
                <input type="search" id="te-search" class="form-control form-control-sm me-2"
                       placeholder="Search exercises…" oninput="filterTeList(this.value)"
                       autocomplete="off" autocorrect="off" autocapitalize="off">
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="te-ex-list"></div>
            </div>
        </div>
    </div>
</div>

<script>
const TEMPLATE_ID = <?= $template_id ?>;
const UNIT        = <?= json_encode($unit) ?>;

async function api(action, data = {}) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(data)) if (v !== null && v !== undefined) fd.append(k, v);
    const r = await fetch('/forge/core/api.php', { method: 'POST', body: fd });
    return r.json();
}

// Auto-save name/notes on change (silent background save)
async function saveTemplateMeta() {
    await api('update_template', {
        id:    TEMPLATE_ID,
        name:  document.getElementById('tpl-name').value.trim() || 'Untitled',
        notes: document.getElementById('tpl-notes').value,
    });
}
document.getElementById('tpl-name').addEventListener('change', saveTemplateMeta);
document.getElementById('tpl-notes').addEventListener('change', saveTemplateMeta);

// Toggle REPS vs REPS MIN/MAX based on scheme selection
function updateRepsUI(row) {
    const isDouble = row.querySelector('.te-scheme[value="double"]')?.checked;
    row.querySelectorAll('.te-reps-single').forEach(el => el.style.display = isDouble ? 'none' : '');
    row.querySelectorAll('.te-reps-range').forEach(el => el.style.display = isDouble ? '' : 'none');
}

// Wire up scheme radio change on a row
function wireSchemeToggle(row) {
    row.querySelectorAll('.te-scheme').forEach(radio => {
        radio.addEventListener('change', () => updateRepsUI(row));
    });
}

// Init on page load
document.querySelectorAll('.tex-row').forEach(wireSchemeToggle);

// Save all exercises + meta in one go
async function saveTemplate() {
    const btn = document.getElementById('save-tpl-btn');
    btn.disabled = true;
    btn.textContent = 'SAVING…';

    await saveTemplateMeta();

    for (const row of document.querySelectorAll('.tex-row')) {
        const teId    = parseInt(row.dataset.teId);
        const abVal   = row.querySelector('.te-ab-group:checked')?.value || '';
        const scheme  = row.querySelector('.te-scheme:checked')?.value || 'double';
        const isDouble = scheme === 'double';
        const repsMin = isDouble
            ? row.querySelector('.te-reps-min')?.value
            : row.querySelector('.te-reps')?.value;
        const repsMax = isDouble
            ? row.querySelector('.te-reps-max')?.value
            : row.querySelector('.te-reps')?.value;
        await api('update_template_exercise', {
            id:                       teId,
            target_sets:              row.querySelector('.te-sets').value,
            target_reps_min:          repsMin,
            target_reps_max:          repsMax,
            target_rpe:               row.querySelector('.te-rpe').value,
            progression_increment_kg: row.querySelector('.te-increment').value,
            progression_scheme:       scheme,
            ab_group:                 abVal || null,
        });
        // Sync A/B badge in header
        const nameDiv = row.querySelector('.d-flex.align-items-center.gap-2');
        let badge = nameDiv?.querySelector('.ab-badge');
        if (abVal === 'A' || abVal === 'B') {
            if (!badge) { badge = document.createElement('span'); badge.className = 'ab-badge'; nameDiv.appendChild(badge); }
            badge.dataset.ab = abVal;
            badge.textContent = abVal;
        } else if (badge) {
            badge.remove();
        }
    }

    btn.textContent = 'SAVED ✓';
    btn.disabled = false;
    setTimeout(() => { btn.textContent = 'SAVE TEMPLATE'; }, 1500);
}

async function deleteCurrentTemplate() {
    if (!confirm('Delete this template? Past workouts stay in history.')) return;
    const r = await api('delete_template', { id: TEMPLATE_ID });
    if (r.status === 'success') {
        window.location.href = '/forge/workout/';
    } else {
        alert(r.message || 'Failed to delete template');
    }
}

// Remove template exercise
async function removeTemplateExercise(teId, btn) {
    const r = await api('remove_template_exercise', { id: teId });
    if (r.status === 'success') {
        btn.closest('.tex-row').remove();
        if (!document.querySelectorAll('.tex-row').length) {
            const list = document.getElementById('tex-list');
            list.insertAdjacentHTML('beforeend', '<p class="text-muted text-center py-3" id="empty-msg">No exercises yet.</p>');
        }
    }
}

// Reorder exercises via up/down
async function moveExercise(teId, dir) {
    const rows  = [...document.querySelectorAll('.tex-row')];
    const idx   = rows.findIndex(r => parseInt(r.dataset.teId) === teId);
    const newIdx = idx + dir;
    if (newIdx < 0 || newIdx >= rows.length) return;

    const list = document.getElementById('tex-list');
    if (dir === -1) list.insertBefore(rows[idx], rows[newIdx]);
    else            list.insertBefore(rows[newIdx], rows[idx]);

    const order = [...document.querySelectorAll('.tex-row')].map(r => parseInt(r.dataset.teId));
    await api('reorder_template_exercises', { order: JSON.stringify(order) });

    // Update disabled state of up/down buttons
    document.querySelectorAll('.tex-row').forEach((row, i) => {
        const btns = row.querySelectorAll('button');
        btns[0].disabled = i === 0;
        btns[1].disabled = i === document.querySelectorAll('.tex-row').length - 1;
    });
}

// Add exercise to template (modal)
let allExList = [], exListLoaded = false;

async function loadExList() {
    if (exListLoaded) return;
    const r = await api('list_exercises');
    allExList = r.data || [];
    exListLoaded = true;
    renderTeList(allExList);
}

function filterTeList(q) {
    const filtered = q.toLowerCase()
        ? allExList.filter(e => e.name.toLowerCase().includes(q.toLowerCase()) ||
            (e.muscle_primary || '').toLowerCase().includes(q.toLowerCase()))
        : allExList;
    renderTeList(filtered);
}

function renderTeList(list) {
    const existing = new Set([...document.querySelectorAll('.tex-row')].map(r => parseInt(r.dataset.teId)));
    // We need exercise IDs, not te IDs — re-fetch from DOM
    // Actually let's just check by data attribute on existing rows (need exercise_id)
    // Simpler: just show all and let duplicate prevention happen server-side
    const container = document.getElementById('te-ex-list');
    if (!list.length) { container.innerHTML = '<p class="text-muted text-center py-4">None found.</p>'; return; }

    const groups = {};
    list.forEach(e => { const g = e.muscle_primary || 'Other'; if (!groups[g]) groups[g] = []; groups[g].push(e); });

    let html = '';
    for (const [grp, exes] of Object.entries(groups)) {
        html += `<div class="px-3 pt-3 pb-1 stat-label" style="background:#0a0a0a;">${grp}</div>`;
        exes.forEach(e => {
            html += `<div class="d-flex align-items-center justify-content-between px-3 py-3 te-pick-item"
                         data-ex-id="${e.id}" style="border-bottom:1px solid #1a1a1a; cursor:pointer; min-height:52px;">
                <div>
                    <div style="font-weight:700; font-size:0.9rem;">${e.name}</div>
                    <div class="text-muted" style="font-size:0.72rem;">${e.category || ''}</div>
                </div>
                <i class="bi bi-plus-circle" style="font-size:1.2rem; color:#555;"></i>
            </div>`;
        });
    }
    container.innerHTML = html;

    container.querySelectorAll('.te-pick-item').forEach(item => {
        item.addEventListener('click', () => addExerciseToTemplate(parseInt(item.dataset.exId),
            allExList.find(e => e.id === parseInt(item.dataset.exId))));
    });
}

async function addExerciseToTemplate(exerciseId, ex) {
    bootstrap.Modal.getInstance(document.getElementById('addTeModal')).hide();
    const r = await api('add_template_exercise', { template_id: TEMPLATE_ID, exercise_id: exerciseId });
    if (r.status !== 'success') return;

    document.getElementById('empty-msg')?.remove();

    const teId = r.data.id;
    const html = `
    <div class="tex-row card mb-2" data-te-id="${teId}">
        <div class="card-body py-2 px-3">
            <div class="d-flex align-items-center gap-2">
                <div class="d-flex flex-column gap-0" style="flex-shrink:0;">
                    <button class="btn-icon py-0" style="min-height:24px;min-width:28px;" onclick="moveExercise(${teId},-1)" type="button">
                        <i class="bi bi-chevron-up" style="font-size:0.8rem;"></i>
                    </button>
                    <button class="btn-icon py-0" style="min-height:24px;min-width:28px;" onclick="moveExercise(${teId},1)" type="button">
                        <i class="bi bi-chevron-down" style="font-size:0.8rem;"></i>
                    </button>
                </div>
                <div class="flex-grow-1 min-w-0">
                    <div style="font-weight:700;font-size:0.9rem;">${ex?.name || 'Exercise'}</div>
                    <div class="text-muted" style="font-size:0.72rem;">No targets set · <span style="color:#FF5722;">double</span></div>
                </div>
                <button class="btn-icon" type="button" data-bs-toggle="collapse" data-bs-target="#te-settings-${teId}">
                    <i class="bi bi-sliders" style="font-size:0.9rem;"></i>
                </button>
                <button class="btn-icon" onclick="removeTemplateExercise(${teId},this)" type="button">
                    <i class="bi bi-x-lg" style="font-size:0.9rem;"></i>
                </button>
            </div>
            <div class="collapse show mt-2 pt-2" id="te-settings-${teId}" style="border-top:1px solid #222;">
                <div class="row g-2">
                    <div class="col-6 col-sm-3"><label class="stat-label d-block mb-1">SETS</label>
                        <input type="number" class="form-control form-control-sm te-sets" inputmode="numeric" placeholder="—" min="1">
                    </div>
                    <div class="col-6 col-sm-4 te-reps-single" style="display:none;"><label class="stat-label d-block mb-1">REPS</label>
                        <input type="number" class="form-control form-control-sm te-reps" inputmode="numeric" placeholder="—" min="1">
                    </div>
                    <div class="col-3 col-sm-2 te-reps-range"><label class="stat-label d-block mb-1">REPS MIN</label>
                        <input type="number" class="form-control form-control-sm te-reps-min" inputmode="numeric" placeholder="—" min="1">
                    </div>
                    <div class="col-3 col-sm-2 te-reps-range"><label class="stat-label d-block mb-1">REPS MAX</label>
                        <input type="number" class="form-control form-control-sm te-reps-max" inputmode="numeric" placeholder="—" min="1">
                    </div>
                    <div class="col-6 col-sm-2"><label class="stat-label d-block mb-1">TARGET RPE</label>
                        <input type="number" class="form-control form-control-sm te-rpe" inputmode="decimal" placeholder="—" step="0.5" min="6" max="10">
                    </div>
                    <div class="col-6 col-sm-3"><label class="stat-label d-block mb-1">+${UNIT.toUpperCase()} STEP</label>
                        <input type="number" class="form-control form-control-sm te-increment" inputmode="decimal" value="2.5" step="0.5" min="0.5">
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-6"><label class="stat-label d-block mb-1">PROGRESSION</label>
                        <div class="d-flex flex-wrap gap-2">
                            ${['linear','double','rpe'].map(s => `<label class="d-flex align-items-center gap-1" style="cursor:pointer;font-size:0.8rem;font-weight:700;color:#888;">
                                <input type="radio" class="te-scheme" name="scheme-${teId}" value="${s}" ${s==='double'?'checked':''}>
                                ${s.toUpperCase()}</label>`).join('')}
                        </div>
                    </div>
                    <div class="col-6"><label class="stat-label d-block mb-1">A/B VARIANT</label>
                        <div class="d-flex flex-wrap gap-2">
                            ${[['','ALWAYS'],['A','A ONLY'],['B','B ONLY']].map(([v,l]) => `<label class="d-flex align-items-center gap-1" style="cursor:pointer;font-size:0.8rem;font-weight:700;color:#888;">
                                <input type="radio" class="te-ab-group" name="ab-${teId}" value="${v}" ${v===''?'checked':''}>
                                ${l}</label>`).join('')}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>`;
    document.getElementById('tex-list').insertAdjacentHTML('beforeend', html);
    const newRow = document.querySelector(`.tex-row[data-te-id="${teId}"]`);
    if (newRow) wireSchemeToggle(newRow);
}

document.getElementById('addTeModal').addEventListener('show.bs.modal', () => {
    document.getElementById('te-search').value = '';
    if (exListLoaded) renderTeList(allExList);
});
</script>

<?php

} else {
// ---- TEMPLATE LIST ----
    $templates = $db->query("
        SELECT t.*, COUNT(te.id) as exercise_count,
               SUM(CASE WHEN te.ab_group IS NOT NULL THEN 1 ELSE 0 END) as ab_count
        FROM templates t
        LEFT JOIN template_exercises te ON te.template_id = t.id
        GROUP BY t.id
        ORDER BY t.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $page_title   = 'Templates';
    $page_context = 'templates';
    include __DIR__ . '/../core/page_head.php';
?>

<div class="container-fluid py-3 px-3" style="max-width:700px; margin:0 auto;">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="fw-black mb-0" style="font-size:1.6rem; letter-spacing:-1.5px;">
            TEMPLATES<span style="color:#FF5722;">_</span>
        </h1>
        <button class="btn btn-forge btn-sm px-3" data-bs-toggle="modal" data-bs-target="#newTplModal">
            <i class="bi bi-plus-lg me-1"></i>NEW
        </button>
    </div>

    <?php if (empty($templates)): ?>
    <div class="text-center py-5">
        <i class="bi bi-layout-text-sidebar" style="font-size:3rem; color:#333;"></i>
        <p class="text-muted mt-3">No templates yet.<br>Create one to speed up starting workouts.</p>
    </div>
    <?php else: ?>
    <div class="d-grid gap-2">
    <?php foreach ($templates as $t): ?>
    <div class="card">
        <div class="card-body py-3 px-3">
            <div class="d-flex align-items-start justify-content-between">
                <div class="min-w-0 me-2">
                    <div class="fw-black" style="font-size:1rem; letter-spacing:-0.5px;">
                        <?= htmlspecialchars($t['name']) ?>
                    </div>
                    <?php if ($t['notes']): ?>
                    <div class="text-muted" style="font-size:0.75rem;"><?= htmlspecialchars($t['notes']) ?></div>
                    <?php endif; ?>
                    <div class="mt-1 d-flex align-items-center gap-2" style="font-size:0.75rem; color:#888;">
                        <span><i class="bi bi-list-ul me-1"></i><?= $t['exercise_count'] ?> exercise<?= $t['exercise_count'] != 1 ? 's' : '' ?></span>
                        <?php if ($t['ab_count'] > 0):
                            $next_ab = ($t['last_ab_variant'] === 'A') ? 'B' : 'A';
                        ?>
                        <span style="font-size:0.65rem; font-weight:900; padding:1px 5px; border-radius:3px; <?= $next_ab === 'A' ? 'background:#1a3a5c;color:#64b5f6;' : 'background:#3a1a5c;color:#ce93d8;' ?>">
                            NEXT: <?= $next_ab ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="d-flex gap-1 flex-shrink-0">
                    <a href="/forge/templates/?id=<?= $t['id'] ?>" class="btn btn-sm btn-forge-outline px-3" style="min-height:36px; line-height:1; display:flex; align-items:center;">
                        EDIT
                    </a>
                    <form method="POST" action="/forge/workout/" style="display:inline;">
                        <input type="hidden" name="action" value="start_workout">
                        <input type="hidden" name="template_id" value="<?= $t['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-forge px-3" style="min-height:36px;">
                            <i class="bi bi-lightning-charge-fill"></i>
                        </button>
                    </form>
                    <button class="btn-icon" onclick="deleteTemplate(<?= $t['id'] ?>, this)" type="button">
                        <i class="bi bi-trash" style="font-size:0.85rem;"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<!-- New Template Modal -->
<div class="modal fade" id="newTplModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom:1px solid #333;">
                <h5 class="modal-title fw-black uppercase">NEW TEMPLATE</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="stat-label d-block mb-1">NAME</label>
                    <input type="text" id="new-tpl-name" class="form-control" placeholder="e.g. Push A, Pull Day…" autocomplete="off">
                </div>
                <div>
                    <label class="stat-label d-block mb-1">NOTES</label>
                    <input type="text" id="new-tpl-notes" class="form-control" placeholder="Optional">
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid #333;">
                <button type="button" class="btn" data-bs-dismiss="modal" style="background:#1e1e1e;border:1px solid #333;color:#888;">CANCEL</button>
                <button type="button" class="btn btn-forge flex-grow-1" onclick="createTemplate()">CREATE</button>
            </div>
        </div>
    </div>
</div>

<script>
async function api(action, data = {}) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(data)) if (v !== null && v !== undefined) fd.append(k, v);
    const r = await fetch('/forge/core/api.php', { method: 'POST', body: fd });
    return r.json();
}

async function createTemplate() {
    const name = document.getElementById('new-tpl-name').value.trim();
    if (!name) { document.getElementById('new-tpl-name').focus(); return; }
    const r = await api('add_template', { name, notes: document.getElementById('new-tpl-notes').value });
    if (r.status === 'success') window.location.href = '/forge/templates/?id=' + r.data.id;
    else alert(r.message);
}

async function deleteTemplate(id, btn) {
    if (!confirm('Delete this template? Past workouts stay in history.')) return;
    const r = await api('delete_template', { id });
    if (r.status === 'success') btn.closest('.card').remove();
    else alert(r.message || 'Failed to delete template');
}
</script>

<?php } ?>
</body>
</html>
