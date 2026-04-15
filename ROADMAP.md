# ROADMAP

Ideas and future directions for FORGE. Not prioritised — just things worth building when the time is right.

## Cardio — distance splits

Currently cardio sessions capture a single distance figure at the end. A SPLIT button mid-session could mark lap/km splits, storing each as its own `workout_set` row with distance + elapsed time. This would enable per-km pace charting, negative/positive split detection, and average pace chips. Needs schema design (multi-row vs single-row per session) and UX thought for mid-run tapping.

## Multi-user

Currently a single-user app gated by `FORGE_ACCESS_KEY`. Moving to proper multi-user would unlock sharing FORGE with others on the same server.

**Approach:** invite-only (admin creates accounts manually — no public self-registration). Keeps the self-hosted ethos.

**Schema changes:**
```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);
-- Add user_id FK to: workouts, templates, personal_records, body_measurements
-- exercises: user_id NULL = global seeded; user_id = N = custom exercise owned by user N
-- user_settings: add user_id column (or replace with user_profiles table — see below)
```

**Auth rewrite:**
- `core/auth.php` becomes session-based (login page at `/forge/login.php`, bcrypt passwords, 90-day cookie — same lifetime as now)
- All pages already redirect through `auth.php`, so the change is contained to one file
- Existing data migrated to `user_id = 1` (owner account) — nothing lost

**Migration path:** SQLite `ALTER TABLE … ADD COLUMN` for FK additions, then `UPDATE … SET user_id = 1` to backfill.

## Profile and settings (multi-user prep)

Before multi-user lands, the profile data model needs to be ready for it. Currently all profile fields (name, sex, DOB, height, resting HR, max HR) are stored as `profile_*` key-value rows in `user_settings` — fine for one user, awkward for many.

**Proposed change:** introduce a `user_profiles` table with typed columns:
```sql
CREATE TABLE user_profiles (
    user_id      INTEGER PRIMARY KEY REFERENCES users(id),
    display_name TEXT,
    sex          TEXT,    -- 'M', 'F', NULL (optional — used only for Navy BF% formula)
    dob          TEXT,    -- ISO date
    height_cm    REAL,
    resting_hr   INTEGER,
    max_hr       INTEGER
);
```
App-behaviour settings (warmup presets, rest timer, notification prefs) stay in `user_settings` with a `user_id` column added.

**Wider-audience considerations:**
- `sex` is only used for the Navy BF% formula — make it optional and label it clearly. If absent, fall back to caliper-only BF% or skip the estimate.
- Derived stats (VO2max, BMI, lean/fat mass) should degrade gracefully when any input field is missing rather than showing nothing.
- Unit preference (`kg` / `lbs`, `cm` / `ft+in`) should be per-user and stored in their profile, not a global setting.

## Exercise library

- **Exercise descriptions** — technique notes, cues, common mistakes. Could be a freeform notes field or structured (setup / execution / tips).
- **Exercise video links** — link to a reference video per exercise (YouTube or self-hosted).
- **Muscle diagrams** — visual indicator of primary/secondary muscles hit.

## Program design

- **Multi-week programs** — a "program" groups several templates into a sequence (e.g. Week 1: A/B/A, Week 2: B/A/B). Currently templates are standalone; this would let you run periodised blocks.
- **Cyclical/wave loading** — define loading waves across weeks (e.g. 3×5 → 4×4 → 5×3 → deload) that auto-advance after each session.
- **Program library** — a repository of well-known programs (5/3/1, GZCLP, RTO, etc.) that can be imported as a starting point.

## SPENCE integration

SPENCE (`/srv/jaaaames.com/spence/`) handles diet and nutrition. Natural integration points:

- **Calorie targets informed by training** — on heavy training days suggest higher intake; rest days lower.
- **Shared dashboard** — a combined "today" view across both apps (training + nutrition).
- **Combined plans** — a unified "plan" object that covers both a training program and a nutrition protocol for a goal (e.g. a 12-week cut with specific macros and a defined lifting program).
- **Shared body metrics** — bodyweight logged in either app is visible in both.

## Quality of life

- **Workout notes per session** — how you felt, sleep, stress, etc. (field exists in schema, not exposed in UI yet).
- **Plate calculator** — given a target weight and available plates, show the per-side loading.
- **Export** — CSV or JSON export of all workout history.
- **PR highlights in workout history** — surface the `is_pr` flag already stored on `workout_sets` in the history detail view so past PRs are visible when reviewing old sessions.
- **Feet/inches on profile** — unit toggle (cm | ft + in) on the USER tab. Store height as cm in the DB; convert on input/display in JS. Persist preference in `localStorage` or user profile settings.
- **TWA packaging (stretch goal)** — `bubblewrap` wraps FORGE into a sideloadable Android APK via Trusted Web Activity. BLE and Web Bluetooth work unchanged under Chrome WebView. iOS: Web Bluetooth not supported in Safari, not a priority.
