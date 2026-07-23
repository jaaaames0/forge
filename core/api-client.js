/**
 * Shared JSON API client for FORGE's form-data endpoints.
 * Pages keep their small local `api()` wrapper only when they need a different
 * endpoint, but no longer duplicate request construction.
 */
(function () {
    window.forgeApi = async function (action, data = {}, endpoint = '/forge/core/api.php') {
        const form = new FormData();
        form.append('action', action);
        for (const [key, value] of Object.entries(data)) {
            if (value !== null && value !== undefined) form.append(key, value);
        }
        const response = await fetch(endpoint, { method: 'POST', body: form });
        return response.json();
    };
})();
