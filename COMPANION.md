# COMPANION.md

## Why This File Exists

This is the operational manual and technical textbook for FORGE. It explains why things are built the way they are, how to run and debug it locally, and what to check when something breaks. A developer dropping into the codebase for the first time should be able to read this and understand the project in an afternoon.

---

## Mental Model

FORGE is a state machine built on SQLite.

```
Templates → Workout (started) → Exercises Added → Sets Logged → Workout (finished)
                                          ↓
                                   PR Detection
                                   (e1RM comparison
                                    on every set save)
```

The core loop is: start a workout, add exercises from the library, log sets. Each set save triggers an e1RM check. If it's a PR, flag it. Finish the workout and commit everything.

Everything else — rest timer, HR monitor, progression hints, A/B rotation — is peripheral UX sugar layered on top of this loop.

---

## Core Concepts

### 1RM Estimation — Epley Formula

Estimated 1RM is computed as: `weight × (1 + reps / 30)`.

This is calculated client-side in the browser before every set save, and server-side in `api.php` after every save to confirm the client value and update `personal_records` if needed.

The ceiling is 30 reps. Beyond 30 reps, the formula degrades. Anything above 30 reps is treated as cardio or bodyweight endurance — e1RM is not computed.

```php
// db_helper.php — maybe_update_pr()
function maybe_update_pr(PDO $db, int $exercise_id, float $weight, int $reps, string $formula = 'epley'): void {
    if ($reps < 1 || $reps > 30) return;
    $e1rm = $weight * (1 + $reps / 30.0);
    // compare to stored record for this exercise, update if higher
}
```

Why Epley and not Brzycki? Both are validated. Epley is the default because it's the historical standard in barbell training literature (Starting Strength, Practical Programming). The difference between the two formulas is ~2–3% at typical rep ranges — clinically insignificant for training purposes.

### Progression Schemes

Three progression schemes are stored per-exercise on `template_exercises.progression_scheme`:

**`linear`:** You have a target weight and target reps. You hit the reps. Next session, the weight goes up by the increment. This is the simplest scheme and the default for novice trainees.

**`double`:** You have a rep range (e.g. 3–5). You do 3 sets. If all three sets hit 5 reps, next session the weight goes up and you restart at the bottom of the range. If you get 4, 5, 5 — you stay at the same weight and try to push all three sets to 5 next time. This is the most common scheme for barbell training beyond the novice phase.

**`rpe`:** You have a target RPE (e.g. 7). If your average RPE across working sets is below target − 1 (i.e. 6), the suggestion is +2.5kg. If it's above target (8 or 9), the suggestion is the same weight or −2.5kg. This is autoregulation — you're adjusting based on how hard the set felt.

The progression suggestion is stored in `workout_exercises.progression_applied_weight`. This field survives workout cancellation and page reload — it's the canonical "what weight are we doing" value for this session.

```php
// workout_exercises.progression_applied_weight
// Set when:
// 1. Exercise added to active workout (from previous best or template target)
// 2. Progression accepted by user
// 3. Workout finished — stored back to template for next session
```

### A/B Template Rotation

Some training programs alternate between two variants of the same template — typically distributing exercises so you hit each muscle group twice per week rather than once.

Example (Starting Strength A/B):
- **Workout A:** Squat, Bench, Row
- **Workout B:** Squat, Deadlift, Overhead Press

The `template_exercises.ab_group` field is NULL (always included), 'A', or 'B'.

When you start a workout from an A/B template, the system:
1. Reads `templates.last_ab_variant` to find which variant was used last
2. Seeds the opposite variant's exercises into `workout_exercises`
3. Stores the chosen variant on `workouts.ab_variant` — **at this point, no template state has changed**
4. On workout finish: writes `workouts.ab_variant` to `templates.last_ab_variant`

This means if you start a workout and cancel it, the A/B counter does not advance. This was a bug fixed in v0.4.0.

### Web Bluetooth HR

Web Bluetooth is a browser API for communicating with BLE (Bluetooth Low Energy) devices. FORGE uses it to connect to a BLE heart rate monitor.

The standard GATT Heart Rate Service has UUID `0x180D`. The Heart Rate Measurement characteristic has UUID `0x2A37`. The value is a single byte (or two bytes for extended HR) representing BPM.

```
Browser (navigator.bluetooth)
    → requestDevice({ filters: [{ services: ['heart_rate'] }] })
    → connectGATT()
    → startNotifications(handle)
    → callback fires every ~1000ms with new BPM value
```

The BPM value is stored in `workout_hr_samples` every 5 seconds. On each set tick, the peak BPM from the last 20 seconds is written to `workout_sets.hr_max`.

**Karvonen %HRR** is computed as: `(hr_max − resting_hr) / (max_hr − resting_hr) × 100`

This requires both resting and max HR to be stored in the user profile. If either is missing, raw BPM is shown instead.

### Rest Timer

The rest timer is implemented in two parts:

1. **Page client (`workout/index.php` inline JS):** Runs a `setInterval` that ticks every second, updates the display, plays a bell sound when it hits zero, and stores the end time in `localStorage` so the timer survives navigation.

2. **Service worker (`sw.js`):** When the timer is started, it sets an `setTimeout` for the duration. If the browser is backgrounded (or the page is closed), the SW handles the notification. When the user returns to the app, the page client cancels the SW timer and takes over.

This prevents double-fire: if the user returns to the app exactly as the notification would have fired, the page client cancels the SW timer before it can fire.

The timer duration is determined by the exercise's `rest_category`:
- `compound_big4`: 300s (squat, bench, deadlift, OHP)
- `compound`: 240s (other compound lifts)
- `bodyweight`: 180s
- `isolation`: 120s

These defaults are per-user configurable in Profile → Settings.

### SQLite WAL Mode

SQLite's WAL (Write-Ahead Logging) mode is enabled in `db_helper.php`:

```php
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("PRAGMA busy_timeout=5000");
$db->exec("PRAGMA foreign_keys=ON");
```

WAL mode allows concurrent readers while a writer is active — multiple PHP-FPM workers can serve reads without blocking each other. The `busy_timeout=5000` means if a writer is holding the lock, readers will wait up to 5 seconds.

For a single-user app used by one person at a time, this is overkill. For the intended multi-user scenario with a few hundred users, it's still likely sufficient — the write locks are extremely brief (milliseconds).

---

## Architecture Tour

### `core/auth.php`

Every page and API endpoint includes this first. It checks for a valid session cookie. If absent or invalid, it renders the access key entry form. On valid key, sets a 90-day secure cookie.

```php
// Simplified flow:
if (no valid cookie) {
    render_key_entry_form();
    exit;
}
```

The access key itself is checked against `getenv('FORGE_ACCESS_KEY')` or `core/credentials.env`. The session is stateless — the cookie contains enough information to validate the request without server-side session storage.

### `core/db_helper.php`

Returns the PDO singleton. All database operations go through here.

```php
function get_db_connection(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO('sqlite:' . __DIR__ . '/../database/forge.db');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("PRAGMA journal_mode=WAL");
        $pdo->exec("PRAGMA busy_timeout=5000");
        $pdo->exec("PRAGMA foreign_keys=ON");
    }
    return $pdo;
}
```

### `core/api.php`

POST-only JSON endpoint. Every action is dispatched by `$_POST['action']`. Returns `{status, message, data}`.

Actions are grouped: exercises, workouts, sets, HR, body measurements. Each action branch is self-contained with its own validation and error handling.

### `core/progress_api.php`

Read-only queries for the progress page: e1RM trends, PR history, volume per exercise over time, body composition.

### `workout/index.php`

The main page. Handles both GET (render active workout or start screen) and POST (start workout from template).

The PHP is responsible for the initial render of the active workout state. After that, all interactions are via AJAX/fetch to `api.php` and `progress_api.php`.

### `sw.js`

The service worker. Handles:
- `install`: cache the app shell
- `activate`: clear old caches
- `message`: receive timer start/cancel commands from the page
- `setTimeout` notification: fire when timer expires in background

---

## Local Run Loop

FORGE runs on a remote server. There is no local development loop for the PHP app itself — you edit on the server or via a mounted filesystem.

For the JS/CSS:
```bash
# No build step — CSS is in forge.css, JS is inline in PHP files
# Edit directly: /srv/jaaaames.com/forge/core/forge.css
# Or in PHP: /srv/jaaaames.com/forge/workout/index.php

# To test changes:
# 1. Edit the file
# 2. Refresh the browser (FORGE has no caching headers — verify with curl)
```

---

## Testing Loop

There is no automated test suite. The testing methodology is manual:

```bash
# 1. Start a workout
# 2. Add exercises
# 3. Log sets
# 4. Check PR detection
# 5. Check progression suggestions
# 6. Cancel workout — verify state resets correctly

# For BLE HR testing:
# 1. Start workout
# 2. Pair HR monitor
# 3. Log sets with HR
# 4. Verify BPM badge appears
# 5. Check workout history for HR timeline
```

---

## Debug Checklist

### Workout won't start
1. Is `database/forge.db` writable? (`chmod 666 database/forge.db`)
2. Is `core/credentials.env` present and readable by PHP-FPM?
3. Is the session cookie being set? Check browser devtools → Application → Cookies
4. Check PHP-FPM error log: `tail /var/log/php-fpm/error.log`

### Rest timer not firing
1. Is the page visible when the timer expires? If yes, check the bell sound URL in the JS
2. If backgrounded: is the service worker registered? Check `chrome://serviceworker-internals/`
3. Check `sw.js` console for `setTimeout` handler errors

### BLE HR not connecting
1. Is the browser Chromium-based? (Chrome, Edge, Brave — not Safari or Firefox)
2. Is the HR monitor in pairing mode? (usually hold power button until LED blinks)
3. Check `navigator.bluetooth` availability: `chrome://bluetooth-internals/` or `edge://bluetooth-internals/`
4. Has the user previously paired and granted permission? Browser remembers device permissions
5. Check the console for `SecurityError` — indicates permission was denied

### PR not detected
1. Was the set saved as `set_type = 'working'`? Warmup sets don't trigger PR checks
2. Is `reps` between 1 and 30?
3. Check `personal_records` table directly: `sqlite3 database/forge.db "SELECT * FROM personal_records WHERE exercise_id = ?"`
4. Was `maybe_update_pr()` called in the save action in `api.php`?

### A/B rotation counter advancing on cancelled workout
1. This was a bug in v0.3.0, fixed in v0.4.0. Confirm `workout_exercises.ab_variant` is stored on the workout row, not written to the template until finish.
2. Check: `SELECT id, template_id, ab_variant, finished_at FROM workouts ORDER BY id DESC LIMIT 5`

### Database locked error
1. Is WAL mode enabled? Check `db_helper.php` — `PRAGMA journal_mode` should return `wal`
2. Is `busy_timeout` set to 5000?
3. Multiple simultaneous writes from different PHP-FPM workers can cause brief locks — normal under high concurrency

---

## Key Technical Decisions

### Why PHP?

The original question was: why not Python/Node/Rust? The answer is that PHP was the path of least resistance on the target VPS, and it has one decisive advantage for a project like this: PHP files are served directly by the web server without any runtime server. No Gunicorn, no PM2, no build step. You drop a file and it works.

The tradeoff is that PHP's type system and ecosystem are less ergonomic than modern alternatives. But for a CRUD app with no complex async requirements, this doesn't matter.

### Why SQLite?

Single file, portable, zero configuration, zero daemon. SQLite is the right choice for a single-user app that may eventually need to be moved to different hardware. You copy the file and you're done.

The WAL mode concern about write locking is real but overstated for this use case. A typical workout session does 20–30 writes. At SQLite speeds, that's a few milliseconds of wall time. You would need dozens of concurrent users before write contention becomes measurable.

### Why no ORM?

The argument for an ORM is developer ergonomics and query safety. The argument against for a project this size: an ORM adds a dependency, a build step, and a layer of abstraction that obscures what's actually happening with the database. PDO with prepared statements is explicit, fast, and requires zero configuration.

### Why no JavaScript framework?

A framework like React or Vue adds a build step, a bundle, and a state management problem. FORGE doesn't need any of that. The UI is mostly forms that submit to an API and update a small part of the DOM. Vanilla `fetch()` + direct DOM manipulation handles everything.

The cost of this choice is that the PHP files are a mix of server-rendered HTML and inline JavaScript. This is not a scalable architecture for a large team, but it is perfectly legible for a single developer.

---

*Last updated: 2026-04-27*
