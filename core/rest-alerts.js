/** Shared active-workout elapsed/rest timer and notification controls. */
(function () {
    const config = window.forgeRestAlertConfig;
    const timerEl = document.getElementById('ab-timer');
    if (!config || !timerEl) return;

    const TIMER_KEY = 'forge_rest_end';
    const NOTIF_KEY = 'forge_notify_rest';
    const SOUND_KEY = 'forge_sound_rest';
    const NOTIF_TAG = 'forge-rest-timer';
    const btnsEl = document.getElementById('ab-timer-btns');
    const display = document.getElementById('ab-rest-display');
    const elapsed = document.getElementById('ab-elapsed');
    let intervalId = null;
    let swReg = null;

    function updateElapsed() {
        const seconds = Math.floor((Date.now() - config.startedMs) / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        if (elapsed) elapsed.textContent = hours ? `${hours}h ${minutes % 60}m` : `${minutes}m`;
    }
    updateElapsed();
    setInterval(updateElapsed, 15000);

    function fmtTime(ms) {
        const seconds = Math.ceil(ms / 1000);
        return `${Math.floor(Math.abs(seconds) / 60)}:${String(Math.abs(seconds) % 60).padStart(2, '0')}`;
    }
    function soundEnabled() { return localStorage.getItem(SOUND_KEY) !== '0'; }
    function notificationSupported() { return 'Notification' in window; }
    function notificationGranted() { return notificationSupported() && Notification.permission === 'granted'; }
    function notifyEnabled() { return localStorage.getItem(NOTIF_KEY) === '1' && notificationGranted(); }

    if (localStorage.getItem(SOUND_KEY) === null) localStorage.setItem(SOUND_KEY, '1');

    function swPost(message) {
        if (!('serviceWorker' in navigator)) return;
        if (swReg?.active) return swReg.active.postMessage(message);
        navigator.serviceWorker.ready.then(reg => reg.active?.postMessage(message)).catch(() => {});
    }
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/forge/sw.js', { scope: '/forge/', updateViaCache: 'none' })
            .then(reg => { swReg = reg; }).catch(() => {});
    }

    async function notifyRestDone() {
        if (!notifyEnabled() || document.visibilityState === 'visible') return;
        try {
            if ('serviceWorker' in navigator) {
                const reg = swReg || await navigator.serviceWorker.ready;
                await reg.showNotification('FORGE — Rest done', {
                    body: 'Time to lift.', icon: '/forge/core/favicon.svg', badge: '/forge/core/favicon.svg',
                    tag: NOTIF_TAG, renotify: true, data: { url: '/forge/workout/' },
                    actions: [{ action: 'open', title: 'Back to workout' }],
                });
                localStorage.setItem('forge_last_notif_tag', NOTIF_TAG);
            } else if (notificationGranted()) {
                new Notification('FORGE — Rest done', { body: 'Time to lift.' });
            }
        } catch (_) {}
    }
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible') return;
        const tag = localStorage.getItem('forge_last_notif_tag');
        if (tag) {
            localStorage.removeItem('forge_last_notif_tag');
            swPost({ type: 'CLOSE_NOTIFICATION', tag });
        }
    });

    function playBell() {
        if (!soundEnabled()) return;
        try {
            const context = new (window.AudioContext || window.webkitAudioContext)();
            [523.25, 1046.5].forEach((frequency, index) => {
                const oscillator = context.createOscillator();
                const gain = context.createGain();
                oscillator.connect(gain); gain.connect(context.destination);
                oscillator.type = 'sine'; oscillator.frequency.value = frequency;
                gain.gain.setValueAtTime(index === 0 ? 0.5 : 0.25, context.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, context.currentTime + 1.8);
                oscillator.start(context.currentTime); oscillator.stop(context.currentTime + 1.8);
            });
        } catch (_) {}
    }

    function hideTimer() {
        clearInterval(intervalId);
        timerEl.style.display = 'none'; btnsEl.style.display = 'none';
        document.body.classList.remove('workout-bar-timer');
        localStorage.removeItem(TIMER_KEY);
    }
    function startTimerUI(endMs) {
        clearInterval(intervalId);
        timerEl.style.display = ''; btnsEl.style.display = 'flex';
        document.body.classList.add('workout-bar-timer');
        const tick = () => {
            const remaining = endMs - Date.now();
            display.textContent = fmtTime(remaining);
            if (remaining > 0) return;
            hideTimer();
            if (document.visibilityState === 'visible') {
                swPost({ type: 'CANCEL_TIMER' });
                navigator.vibrate?.([200, 100, 200]);
                playBell();
            } else {
                notifyRestDone().finally(() => swPost({ type: 'CANCEL_TIMER' }));
            }
        };
        tick();
        intervalId = setInterval(tick, 250);
    }

    window.startRestTimer = function (seconds) {
        const endMs = Date.now() + seconds * 1000;
        localStorage.setItem(TIMER_KEY, endMs); startTimerUI(endMs);
        if (notifyEnabled()) swPost({ type: 'SET_TIMER', endMs });
    };
    window.skipRest = function () {
        hideTimer();
        swPost({ type: 'CANCEL_TIMER' });
    };
    window.addRestTime = function (seconds) {
        const current = parseInt(localStorage.getItem(TIMER_KEY) || '0', 10);
        const endMs = Math.max(Date.now() + 1000, current + seconds * 1000);
        localStorage.setItem(TIMER_KEY, endMs); startTimerUI(endMs);
        if (notifyEnabled()) swPost({ type: 'SET_TIMER', endMs });
    };
    window.forgeToggleNotifications = async function (enable) {
        if (!enable) {
            localStorage.removeItem(NOTIF_KEY); swPost({ type: 'CANCEL_TIMER' }); return true;
        }
        if (!notificationSupported()) return false;
        if (Notification.permission === 'default' && await Notification.requestPermission() !== 'granted') return false;
        if (!notificationGranted()) return false;
        localStorage.setItem(NOTIF_KEY, '1');
        const saved = parseInt(localStorage.getItem(TIMER_KEY) || '0', 10);
        if (saved > Date.now()) swPost({ type: 'SET_TIMER', endMs: saved });
        return true;
    };
    window.forgeSetRestSound = enable => localStorage.setItem(SOUND_KEY, enable ? '1' : '0');
    window.forgeGetRestPrefs = () => ({
        sound: soundEnabled(), notifications: localStorage.getItem(NOTIF_KEY) === '1',
        permission: notificationSupported() ? Notification.permission : 'unsupported',
    });

    const saved = parseInt(localStorage.getItem(TIMER_KEY) || '0', 10);
    if (saved > Date.now()) {
        startTimerUI(saved);
        if (notifyEnabled()) swPost({ type: 'SET_TIMER', endMs: saved });
    }
})();
