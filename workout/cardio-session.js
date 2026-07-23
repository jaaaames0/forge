/** Active cardio session timer, live HR display, wake lock, and finish flow. */
(function () {
    const c = window.forgeCardioConfig;
    if (!c) return;
    const key = `forge_cardio_start_${c.workoutId}`;
    let start = Number(localStorage.getItem(key)) || Date.now();
    localStorage.setItem(key, start);
    let wakeLock = null, samples = [];
    const elapsed = () => Math.max(0, Math.floor((Date.now() - start) / 1000));
    const clock = seconds => seconds >= 3600 ? `${Math.floor(seconds / 3600)}:${String(Math.floor(seconds % 3600 / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}` : `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
    function updateTimer() { const value = clock(elapsed()); document.getElementById('workout-duration').textContent = value; document.getElementById('cardio-timer').textContent = value; }
    setInterval(updateTimer, 1000); updateTimer();
    async function requestWakeLock() { if ('wakeLock' in navigator) try { wakeLock = await navigator.wakeLock.request('screen'); } catch (_) {} }
    requestWakeLock(); document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') requestWakeLock(); });
    window.onForgeHRUpdate = bpm => {
        if (!bpm || bpm < 20) return;
        samples.push(bpm); const avg = Math.round(samples.reduce((a, b) => a + b, 0) / samples.length), peak = Math.max(...samples);
        document.getElementById('cardio-hr-bpm').textContent = bpm; document.getElementById('cardio-hr-avg').textContent = avg; document.getElementById('cardio-hr-peak').textContent = peak;
        if (c.restingHr && c.maxHr > c.restingHr) {
            const hrr = Math.round((bpm - c.restingHr) / (c.maxHr - c.restingHr) * 100), pct = Math.max(0, Math.min(100, hrr));
            document.getElementById('cardio-hrr-pct').textContent = `${pct}%`;
            const fill = document.getElementById('cardio-zone-fill'), color = hrr < 50 ? '#4caf50' : hrr < 70 ? '#ff9800' : '#ef5350';
            fill.style.width = `${pct}%`; fill.style.background = color;
            document.getElementById('cardio-hr-zone-label').textContent = hrr < 50 ? 'ZONE 1 — EASY' : hrr < 60 ? 'ZONE 2 — FAT BURN' : hrr < 70 ? 'ZONE 3 — AEROBIC' : hrr < 85 ? 'ZONE 4 — THRESHOLD' : 'ZONE 5 — MAX EFFORT';
        }
    };
    window.openCardioFinishModal = () => { wakeLock?.release(); wakeLock = null; const s = elapsed(); document.getElementById('cardio-finish-duration').textContent = s >= 3600 ? `${Math.floor(s / 3600)}h ${Math.floor(s % 3600 / 60)}m ${s % 60}s` : `${Math.floor(s / 60)}m ${s % 60}s`; bootstrap.Modal.getOrCreateInstance(document.getElementById('cardioFinishModal')).show(); };
    window.confirmCardioFinish = async () => {
        const button = document.querySelector('#cardioFinishModal .btn-forge'); button.disabled = true; button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';
        const data = { workout_id: c.workoutId, exercise_id: c.exerciseId, duration_sec: Math.max(1, elapsed()), distance_km: parseFloat(document.getElementById('cardio-finish-distance').value) || null, elevation_m: parseFloat(document.getElementById('cardio-finish-elevation').value) || null, intensity: parseInt(document.getElementById('cardio-finish-intensity').value, 10) || null, notes: document.getElementById('cardio-finish-notes').value.trim() || null, bodyweight_kg: parseFloat(document.getElementById('cardio-finish-bodyweight').value) || null };
        const result = await window.forgeApi('finish_cardio', data);
        if (result.status === 'success') { localStorage.removeItem(key); location.href = `/forge/history/?id=${c.workoutId}`; return; }
        button.disabled = false; button.innerHTML = '<i class="bi bi-check-lg me-1"></i>SAVE &amp; FINISH'; alert(`Error: ${result.message}`);
    };
    window.cancelWorkout = async () => { if (!confirm('Cancel this cardio session? It will be deleted.')) return; wakeLock?.release(); localStorage.removeItem(key); const result = await window.forgeApi('delete_workout', { id: c.workoutId }); if (result.status === 'success') location.href = '/forge/workout/'; else alert(`Error: ${result.message}`); };
})();
