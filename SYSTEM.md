# SYSTEM.md

## Purpose

FORGE is a self-hosted barbell training journal. Its single job is to help you apply progressive overload — week after week, year after year — with minimal friction and zero corporate intermediation. It is a barbell app. Everything that does not serve progressive overload is absent.

## Tech Stack

- **Backend:** PHP 8.2+, no framework — files served directly by Nginx + PHP-FPM
- **Database:** SQLite 3 (WAL mode) via PDO — single-file, portable, no daemon required
- **Frontend:** Bootstrap 5.3 dark theme, Bootstrap Icons, Chart.js 4.x, vanilla JS (`fetch()` only)
- **Service Worker:** Background rest timer notifications (`sw.js`)
- **Bluetooth:** Web Bluetooth GATT HR Service — standard BLE HR monitor support
- **Auth:** Single shared key (`FORGE_ACCESS_KEY`), 90-day secure httponly cookie
- **Sister app:** SPENCE (`/srv/jaaaames.com/spence/`) — same architecture, shared patterns

## Architecture

Three-layer stack, no framework, no build step:

```
workout/index.php          ← Workout logger (primary page, mid-workout use)
templates/index.php         ← Template builder
history/index.php          ← Session history
progress/index.php          ← e1RM trends, PRs, body comp, cardio
exercises/index.php         ← Exercise library + per-exercise stats
profile/index.php           ← User profile, biometrics, body metrics
hr/index.php                ← BLE HR monitor tech demo
core/
  auth.php                  ← Access key check, session cookie, requires all pages
  db_helper.php             ← PDO singleton, WAL, foreign keys, helper functions
  api.php                   ← POST-only JSON actions: exercise/workout/set CRUD
  progress_api.php          ← 1RM estimates, PR history, volume queries
  header.php                ← Navbar with cross-link to SPENCE
  page_head.php             ← Bootstrap CDN includes, dark theme meta
  forge.css                 ← Mobile-first overrides, accent (#FF5722), input sizing
database/
  schema.sql                ← Full DDL, committed
  forge_empty.db            ← Blank template DB
  forge.db                  ← Live data (gitignored)
```

## Design Goals

- **Barbell-orthodoxy:** Built for compound barbell training first. Everything else is accessory.
- **Mobile mid-workout:** Used standing in the gym, phone in hand, thumbs. No hover-dependent UI.
- **Zero friction logging:** Three taps from opening the app to logging a set.
- **Self-hosted sovereignty:** No third-party dependencies at runtime. PHP + SQLite. Your server.
- **Progressive overload as the only metric:** PR detection, e1RM tracking, progression hints — all serve load increase over time.

## Design Non-Goals

- No social features, leaderboards, or gamification
- No exercise video library (videos bloat the app and die when YouTube links rot)
- No meal tracking (SPENCE handles nutrition; FORGE does not duplicate it)
- No periodisation calculators or training load dashboards beyond volume queries
- No multi-user support until the single-user product is genuinely complete
- No public registration or community features

## Runtime Topology

- **Deployed:** `/srv/jaaaames.com/forge/` (Nginx vhost, PHP-FPM socket)
- **Database:** `database/forge.db` — gitignored live data
- **Auth key:** `core/credentials.env` — gitignored
- **Migration target:** OptiPlex (pending delivery) — full jaaaames.com stack migrates when hardware arrives
- **Access:** HTTPS only, key-based session cookie, samesite=Strict
- **No CDN for app assets** — Bootstrap and Chart.js CDN only; app CSS/JS is inline PHP

## Design Decisions

| Decision | Chosen Because |
|----------|---------------|
| PHP no framework | Zero overhead, zero deps, PHP ships with the server |
| SQLite WAL | Portable single file, WAL handles concurrent reads safely |
| Vanilla JS fetch | No build step, no bundle, no React, no bloat |
| Bootstrap dark | Known quantity, mobile-first grid, accessible |
| Epley formula | Most validated 1RM estimate for rep ranges 1–30 |
| Key-based auth | Single user, no account system needed yet |
| BLE GATT HR | Standard protocol, works with any HR monitor, no vendor lock-in |
| Karvonen %HRR | Uses actual resting/max HR rather than age-predicted max |

## Key Design Patterns

- **Action-dispatched API:** All API calls are POST to `api.php` or `progress_api.php` with `$_POST['action']` dispatching to handler branches
- **PDO singleton:** `get_db_connection()` returns the same PDO instance; WAL + busy_timeout prevents lock contention
- **Progression hints:** Stored on `template_exercises.progression_applied_weight` — survives page reloads and workout cancellations
- **A/B state isolation:** Variant stored on `workouts.ab_variant` at seed time, only committed to `templates.last_ab_variant` on successful finish

---

*Last updated: 2026-04-27*
