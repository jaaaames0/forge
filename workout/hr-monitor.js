/** Web Bluetooth heart-rate controller for active FORGE workouts. */
(function () {
    const config = window.forgeHrConfig;
    if (!config || !navigator.bluetooth) return;

    const SERVICE = 0x180D, CHARACTERISTIC = 0x2A37;
    const DEVICE_KEY = 'forge_hr_device', SAMPLE_MS = 5000, FLUSH_MS = 30000, WATCH_TIMEOUT_MS = 20000;
    const badge = document.getElementById('hr-badge');
    const icon = document.getElementById('hr-badge-icon');
    const badgeBpm = document.getElementById('hr-badge-bpm');
    let device = null, characteristic = null, state = 'disconnected', liveBpm = 0;
    let sampleTimer = null, flushTimer = null, watchAbort = null, disconnectBound = false;
    let pending = [], readings = [];

    const stored = () => !!localStorage.getItem(DEVICE_KEY);
    const timestamp = () => new Date().toISOString().slice(0, 19).replace('T', ' ');
    const parseBpm = value => value.getUint8(0) & 1 ? value.getUint16(1, true) : value.getUint8(1);

    function render() {
        if (!badge) return;
        badge.style.display = 'flex';
        const connected = state === 'connected';
        const pendingState = state === 'connecting' || state === 'watching';
        const known = state === 'known';
        const color = connected || known ? '#FF5722' : pendingState ? '#FFC107' : '#444';
        badge.style.borderColor = connected || known ? '#FF5722' : pendingState ? '#FFC107' : '#333';
        badge.title = connected ? 'HR connected — tap to disconnect' : pendingState
            ? (state === 'watching' ? 'Waiting for monitor…' : 'Connecting…')
            : known ? 'Tap to reconnect HR monitor' : 'Tap to connect HR monitor';
        icon.style.color = color; badgeBpm.style.color = color;
        badgeBpm.textContent = connected ? (liveBpm || '--') : pendingState ? '…' : known ? '+' : '--';
    }
    function setState(next) { state = next; render(); }
    function stopSampling() { clearInterval(sampleTimer); clearInterval(flushTimer); sampleTimer = flushTimer = null; }
    function startSampling() {
        stopSampling();
        sampleTimer = setInterval(() => { if (liveBpm) pending.push({ bpm: liveBpm, sampled_at: timestamp() }); }, SAMPLE_MS);
        flushTimer = setInterval(flush, FLUSH_MS);
    }
    async function flush() {
        if (!pending.length) return;
        const batch = pending.splice(0);
        try {
            const result = await window.forgeApi('log_hr_sample', { workout_id: config.workoutId, samples: JSON.stringify(batch) });
            if (result.status !== 'success') throw new Error(result.message);
        } catch (_) { pending.unshift(...batch); }
    }
    function onReading(event) {
        const bpm = parseBpm(event.target.value);
        if (bpm < 20 || bpm > 250) return;
        liveBpm = bpm; readings.push({ bpm, at: Date.now() });
        if (readings.length > 60) readings.shift();
        badgeBpm.textContent = bpm; icon.style.color = badgeBpm.style.color = '#FF5722';
        window.onForgeHRUpdate?.(bpm);
    }
    async function connect(target) {
        try {
            setState('connecting');
            const server = await target.gatt.connect();
            const service = await server.getPrimaryService(SERVICE);
            characteristic = await service.getCharacteristic(CHARACTERISTIC);
            await characteristic.startNotifications();
            characteristic.addEventListener('characteristicvaluechanged', onReading);
            device = target; localStorage.setItem(DEVICE_KEY, target.name || '');
            if (!disconnectBound) { target.addEventListener('gattserverdisconnected', onDisconnect); disconnectBound = true; }
            setState('connected'); startSampling(); return true;
        } catch (error) { console.warn('FORGE HR: connect failed —', error.message); return false; }
    }
    function watchAndConnect(target) {
        if (!target.watchAdvertisements) return setState(stored() ? 'known' : 'disconnected');
        setState('watching'); watchAbort = new AbortController();
        const timeout = setTimeout(() => { watchAbort?.abort(); watchAbort = null; setState(stored() ? 'known' : 'disconnected'); }, WATCH_TIMEOUT_MS);
        target.addEventListener('advertisementreceived', async () => {
            clearTimeout(timeout); watchAbort?.abort(); watchAbort = null;
            if (!await connect(target)) setState(stored() ? 'known' : 'disconnected');
        }, { once: true });
        target.watchAdvertisements({ signal: watchAbort.signal }).catch(() => {
            clearTimeout(timeout); watchAbort = null; setState(stored() ? 'known' : 'disconnected');
        });
    }
    function onDisconnect() {
        disconnectBound = false; characteristic = null; liveBpm = 0; stopSampling(); flush();
        if (device) watchAndConnect(device); else setState(stored() ? 'known' : 'disconnected');
    }
    async function autoReconnect() {
        if (!navigator.bluetooth.getDevices) return setState(stored() ? 'known' : 'disconnected');
        const devices = await navigator.bluetooth.getDevices().catch(() => []);
        const savedName = localStorage.getItem(DEVICE_KEY);
        const target = devices.find(item => item.name === savedName) || devices.find(item => item.name) || devices[0];
        if (!target) return setState(stored() ? 'known' : 'disconnected');
        if (!await connect(target)) watchAndConnect(target);
    }
    async function manualConnect() {
        try {
            setState('connecting');
            const target = await navigator.bluetooth.requestDevice({ filters: [{ services: [SERVICE] }], optionalServices: [SERVICE] });
            disconnectBound = false;
            if (!await connect(target)) watchAndConnect(target);
        } catch (error) {
            if (!['NotFoundError', 'AbortError'].includes(error.name)) console.warn('FORGE HR: picker failed —', error.message);
            setState(stored() ? 'known' : 'disconnected');
        }
    }
    async function disconnect() {
        watchAbort?.abort(); watchAbort = null; stopSampling(); await flush();
        if (characteristic) { try { await characteristic.stopNotifications(); } catch (_) {} characteristic.removeEventListener('characteristicvaluechanged', onReading); characteristic = null; }
        if (device) {
            if (disconnectBound) { device.removeEventListener('gattserverdisconnected', onDisconnect); disconnectBound = false; }
            if (device.gatt.connected) device.gatt.disconnect(); device = null;
        }
        liveBpm = 0; localStorage.removeItem(DEVICE_KEY); setState('disconnected');
    }

    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flush(); });
    window.addEventListener('pagehide', flush);
    window.forgeHR = {
        toggleConnect() { if (state === 'connected') disconnect(); else if (state === 'disconnected' || state === 'known') manualConnect(); },
        getBpm: () => liveBpm, getState: () => state,
        getPeakBpm(windowMs = 20000) {
            const recent = readings.filter(reading => reading.at >= Date.now() - windowMs);
            return recent.length ? Math.max(...recent.map(reading => reading.bpm)) : (liveBpm || null);
        },
    };
    render();
    setTimeout(autoReconnect, 600);
})();
