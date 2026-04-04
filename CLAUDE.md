# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

FORGE is a self-hosted single-user gym and workout tracking web app. Sister app to SPENCE (`/srv/jaaaames.com/spence/`), which shares its architecture. No build step — PHP files are served directly by Nginx + PHP-FPM 8.2+.

## Database Setup

```bash
# Initialize from schema
sqlite3 database/forge.db < database/schema.sql
chmod 666 database/forge.db

# Or copy the blank template
cp database/forge_empty.db database/forge.db
chmod 666 database/forge.db
```

The live `database/forge.db` is gitignored. `database/forge_empty.db` (blank template) and `database/schema.sql` are committed.

## Architecture

Three-layer stack with no framework:

**Frontend** — PHP pages with inline vanilla JS using `fetch()` for AJAX. Pages are in subdirectories (`workout/`, `history/`, `progress/`, `exercises/`, `templates/`, `settings/`, root `index.php`).

**API** — POST-only JSON endpoints in `core/api.php` (exercise/workout/set CRUD) and `core/progress_api.php` (1RM estimates, PR history, volume queries). All actions dispatched via `$_POST['action']`. Wrap everything in try/catch and return `{status, message, data}`.

**Database** — PDO SQLite3 via `core/db_helper.php`. `get_db_connection()` returns PDO with `ERRMODE_EXCEPTION`, `busy_timeout 5000`, `journal_mode=WAL`, and `foreign_keys=ON`. Timezone is `Australia/Sydney`.

### Mandatory includes

Every page and every API endpoint must start with:
```php
<?php require_once __DIR__ . '/../core/auth.php'; ?>
```
(adjust path depth as needed — auth must be the first include)

Auth reads the access key from `getenv('FORGE_ACCESS_KEY')` or `core/credentials.env` (gitignored). Session lifetime is 90 days, secure httponly cookies, samesite=Strict.

## UI Conventions

- Bootstrap 5.3.0 dark theme (CDN), Bootstrap Icons 1.11.0 (CDN), Chart.js 4.x (CDN)
- Background `#121212`, cards `#1a1a1a`, borders `#333`, text `#e0e0e0`
- **Accent color: `#FF5722` (deep orange)** — distinguishes FORGE from SPENCE's `#00A3FF` blue
- Font-weight 900 uppercase labels, monospaced stats, muted secondary labels
- All icons via `bi-*` Bootstrap Icon classes
- No jQuery — vanilla `fetch()` only

The navbar (in `core/header.php`) must include a cross-link to `/spence/`.

## Key Domain Logic

**1RM Estimation** — Epley formula (default): `weight × (1 + reps / 30)`. Only calculate for `reps > 1 && reps <= 12`. Auto-insert/update `personal_records` after every set save if the new estimated 1RM exceeds the current record.

**Progression schemes** (stored on `template_exercises.progression_scheme`):
- `linear` — after hitting all target reps, suggest `+increment_kg`
- `double` — rep range target; if all sets hit top of range, suggest `+increment_kg` and drop to bottom of range
- `rpe` — if avg RPE < target − 1, suggest +2.5 kg; if RPE > target, suggest same or −2.5 kg

**Workout logger** (`workout/index.php`) — the most-used page. In-progress state persists in DB (`started_at` set, `finished_at` null). The "Previous" column in the set table must pull the most recent `workout_sets` for that exercise to show continuity across sessions.

**Exercise seeding** — On first run, detect empty `exercises` table and INSERT ~50 common exercises (squat, deadlift, bench, OHP, row, pull-up, etc.).

## Database Schema Summary

```
exercises       — id, name, category, muscle_primary, muscle_secondary, is_custom, notes
workouts        — id, name, template_id, started_at, finished_at, notes, bodyweight_kg
workout_sets    — id, workout_id, exercise_id, set_number, set_type, weight_kg, reps, rpe,
                  duration_sec, distance_km, notes
templates       — id, name, notes, created_at
template_exercises — id, template_id, exercise_id, order_num, target_sets,
                     target_reps_min, target_reps_max, target_rpe,
                     progression_scheme, progression_increment_kg
personal_records — id, exercise_id, weight_kg, reps, estimated_1rm, achieved_at
```

`set_type` values: `warmup`, `working`, `dropset`, `failure`.  
`category` values: `Barbell`, `Dumbbell`, `Machine`, `Cable`, `Bodyweight`, `Cardio`, `Other`.  
`muscle_primary` values: `Chest`, `Back`, `Legs`, `Shoulders`, `Arms`, `Core`, `Glutes`, `Cardio`.

## Mobile-First Design

FORGE is used mid-workout at the gym on a phone. All UI decisions should default to mobile:

- All form inputs use `font-size: 16px` — prevents iOS from zooming on focus (defined in `forge.css`)
- All inputs and buttons have `min-height: 44px` for tap targets
- `inputmode="decimal"` on weight fields, `inputmode="numeric"` on reps/sets
- No hover-dependent UI — everything must be tap-accessible
- The rest timer is a **fixed bottom bar** (`position:fixed; bottom:0`), not a modal
- Primary workout logger actions should be in the lower half of the screen (thumb zone)
- `viewport` meta uses `maximum-scale=1.0` to prevent double-tap zoom
- When timer is active, `<body>` gets class `timer-active` which adds `padding-bottom:80px` to prevent content hiding behind the bar

## Reference: SPENCE

SPENCE at `/srv/jaaaames.com/spence/` is the canonical reference implementation. Copy patterns from its `core/auth.php`, `core/db_helper.php`, `core/page_head.php`, `core/header.php`, and `core/spence.css` wholesale — FORGE should mirror these files with FORGE-specific naming and the orange accent color.
