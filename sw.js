/**
 * FORGE Service Worker
 * Handles background rest timer notifications.
 * Receives messages: { type: 'SET_TIMER', endMs } | { type: 'CANCEL_TIMER' }
 */

let timerId = null;

self.addEventListener('message', (event) => {
    const { type, endMs } = event.data || {};

    if (type === 'CANCEL_TIMER') {
        clearTimeout(timerId);
        timerId = null;
        return;
    }

    if (type === 'SET_TIMER' && endMs) {
        clearTimeout(timerId);
        const delay = endMs - Date.now();
        if (delay <= 0) {
            fireNotification();
        } else {
            timerId = setTimeout(fireNotification, delay);
        }
    }
});

async function fireNotification() {
    timerId = null;

    // Do not show a popup if FORGE is already open and visible/focused.
    const clientsList = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    const forgeVisible = clientsList.some((client) =>
        client.url.includes('/forge/') && (client.focused || client.visibilityState === 'visible')
    );
    if (forgeVisible) return;

    self.registration.showNotification('FORGE — Rest done', {
        body: 'Time to lift.',
        icon: '/forge/core/favicon.svg',
        badge: '/forge/core/favicon.svg',
        tag: 'forge-rest-timer',
        renotify: false,
        silent: false,
        data: { url: '/forge/workout/' },
        actions: [{ action: 'open', title: 'Back to workout' }],
    });
}

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = event.notification.data?.url || '/forge/workout/';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
            for (const client of list) {
                if (client.url.includes('/forge/') && 'focus' in client) {
                    client.navigate(target);
                    return client.focus();
                }
            }
            return clients.openWindow(target);
        })
    );
});
