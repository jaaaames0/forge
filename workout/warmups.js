/** FORGE workout warmup calculator. Requires Bootstrap and forgeWarmupPresets. */
(function () {
    const WARMUP_KEYS = {
        compound_big4: 'compound',
        compound: 'compound',
        isolation: 'accessory',
    };
    window.forgeWarmupKeys = WARMUP_KEYS;

    function floorTo2_5(value) {
        return Math.floor(value / 2.5) * 2.5;
    }

    function buildWarmupSets(restCategory, working) {
        const key = WARMUP_KEYS[restCategory] || 'compound';
        const presets = Array.isArray(window.forgeWarmupPresets?.[key])
            ? window.forgeWarmupPresets[key]
            : null;
        let html = `<div class="stat-label mb-3">WORKING: <span style="font-family:monospace;color:#fff;">${working}kg</span></div>`;

        if (!presets?.length) {
            return html + '<div class="text-muted text-center py-3" style="font-size:.8rem;">No warmup defined in Settings</div>';
        }

        presets.forEach((set, index) => {
            const weight = set.pct == null
                ? '20kg <span style="color:#888;font-size:.8rem;">(bar)</span>'
                : `${floorTo2_5(working * set.pct / 100)}kg <span style="color:#888;font-size:.8rem;">(${set.pct}%)</span>`;
            html += `<div class="d-flex align-items-center justify-content-between py-2" style="border-bottom:1px solid #2a2a2a;">
                <div><span class="stat-label">SET ${index + 1}</span></div>
                <div style="font-family:monospace;font-weight:900;font-size:1rem;color:#e0e0e0;">
                    ${weight} <span style="color:#888;font-size:.85rem;">× ${set.reps}</span>
                </div>
            </div>`;
        });
        return html;
    }

    function controls(exerciseId, working, label) {
        return `<div class="mt-3 d-flex gap-2 align-items-center">
            <input type="number" id="warmup-override" class="form-control" inputmode="decimal"
                   placeholder="Different weight?" style="max-width:160px;" value="${working ?? ''}">
            <button class="btn btn-forge" style="font-size:.8rem;" onclick="recalcWarmup(${exerciseId})">${label}</button>
        </div>`;
    }

    window.openWarmupCalc = function (exerciseId) {
        const block = document.querySelector(`.exercise-block[data-exercise-id="${exerciseId}"]`);
        const restCategory = block.dataset.restCategory || 'compound';
        let working = null;
        for (const row of block.querySelectorAll('.set-row[data-set-type="working"]')) {
            const input = row.querySelector('.weight-input');
            if (input && input.value !== '') {
                working = parseFloat(input.value);
                break;
            }
        }

        const body = document.getElementById('warmup-modal-body');
        body.innerHTML = working > 0
            ? buildWarmupSets(restCategory, working) + controls(exerciseId, working, 'RECALC')
            : `<p class="text-muted" style="font-size:.82rem;">Enter your working weight:</p>${controls(exerciseId, null, 'CALC')}<div id="warmup-sets-out" class="mt-3"></div>`;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('warmupModal')).show();
    };

    window.recalcWarmup = function (exerciseId) {
        const block = document.querySelector(`.exercise-block[data-exercise-id="${exerciseId}"]`);
        const input = document.getElementById('warmup-override');
        const working = parseFloat(input.value);
        if (!Number.isFinite(working) || working <= 0) {
            input.style.borderColor = '#ff4444';
            return;
        }
        input.style.borderColor = '';
        const content = buildWarmupSets(block.dataset.restCategory || 'compound', working);
        const output = document.getElementById('warmup-sets-out');
        if (output) output.innerHTML = content;
        else document.getElementById('warmup-modal-body').innerHTML = content + controls(exerciseId, working, 'RECALC');
    };
})();
