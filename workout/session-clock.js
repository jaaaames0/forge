/** Header session clock for an active strength workout. */
(function () {
    const config = window.forgeWorkoutClockConfig;
    const display = document.getElementById('workout-duration');
    if (!config || !display) return;

    function update() {
        const elapsed = Math.max(0, Math.floor((Date.now() - config.startedMs) / 1000));
        const hours = Math.floor(elapsed / 3600);
        const minutes = Math.floor((elapsed % 3600) / 60);
        const seconds = elapsed % 60;
        display.textContent = (hours ? `${hours}:` : '')
            + String(minutes).padStart(hours ? 2 : 1, '0')
            + `:${String(seconds).padStart(2, '0')}`;
    }
    function schedule() {
        update();
        setTimeout(schedule, 1000 - (Date.now() % 1000));
    }
    schedule();
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') update();
    });
    window.addEventListener('focus', update);
})();
