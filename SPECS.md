# SPECS.md

## Scope

FORGE tracks barbell compound training. It logs workouts, sets, reps, weight, RPE, and (optionally) heart rate. It calculates e1RM, detects PRs, suggests progression, and persists rest timers. It does not prescribe programs, manage periodisation, track nutrition, or support multiple users.

## Functional Requirements

### FR-1: Workout Logging
The system SHALL allow the user to start a workout from a template or blank, add exercises during the session, log sets with weight/reps/RPE, and finish or cancel the workout. In-progress workouts persist in the database with `finished_at = NULL`.

### FR-2: Set Tracking
The system SHALL track: weight (kg), reps, RPE (1–10), set type (warmup/working/dropset/failure), duration (cardio, seconds), distance (km), elevation (m), and HR (avg/max). All fields except weight/reps are optional.

### FR-3: Rest Timer
The system SHALL provide a countdown timer (fixed bottom bar, persists across navigation) that fires an audible bell and a service worker notification when it expires. Timer duration is configurable per exercise `rest_category`. No timer fires on the final set of a workout.

### FR-4: Progression Suggestions
The system SHALL suggest the next working weight for each exercise based on the exercise's `progression_scheme` (linear/double/RPE). Suggestions are shown in the workout UI and pre-fill the weight input on next session.

### FR-5: PR Detection
After each working set save, the system SHALL compute estimated 1RM using the Epley formula and compare it to the stored `personal_records` entry for that exercise. If the new e1RM exceeds the stored record, the set is flagged `is_pr = 1` and the record is updated.

### FR-6: A/B Template Rotation
The system SHALL allow templates to define exercises as group A, group B, or always-included. Starting a workout from such a template seeds the opposite variant from the last session. The variant is stored on the workout row at seed time and committed to the template only on workout finish.

### FR-7: Heart Rate Capture
The system SHALL connect to a BLE HR monitor via Web Bluetooth, store samples every 5 seconds during a workout, and display live BPM in the workout header. Peak BPM from the last 20 seconds is saved to `workout_sets.hr_max` on each set tick.

### FR-8: Karvonen %HRR
The system SHALL compute heart rate reserve percentage for each set using stored profile values for resting HR and max HR. Falls back to raw BPM if profile values are absent.

### FR-9: Cardio Mode
The system SHALL support discrete cardio workouts with a stopwatch, live HR display, HR zone label, and a finish modal capturing distance, elevation, perceived intensity, and notes. Avg/max HR are auto-computed from stored samples.

### FR-10: Body Composition
The system SHALL accept body measurements (waist, neck, hip, caliper BF%) and compute Navy BF% formula. Trend charts display body weight and resting HR over time.

### FR-11: Exercise Library
The system SHALL seed approximately 50 common barbell and dumbbell exercises on first run (when `exercises` table is empty). Custom exercises can be added, edited, and deleted (custom only).

### FR-12: Template Builder
The system SHALL allow creation of named workout templates with ordered exercises, per-exercise target sets/reps/RPE, progression scheme, progression increment, and optional A/B grouping.

### FR-13: PWA Install
The system SHALL include a service worker and manifest enabling Add to Home Screen on Android Chrome and iOS Safari. The app runs in standalone mode with no browser chrome.

### FR-14: Workout History
The system SHALL display a paginated list of completed workouts with exercise count, set count, and total volume. Per-workout detail shows all sets, e1RM, and (if available) HR timeline.

### FR-15: Per-Exercise Statistics
The system SHALL display best sets, e1RM history, and volume trends per exercise. Cardio exercises show total distance, total time, best speed, and best duration.

## Non-Functional Requirements

### NFR-1: Mobile-First
All form inputs use `font-size: 16px` to prevent iOS zoom on focus. All interactive elements have `min-height: 44px`. No hover-dependent UI. The rest timer is fixed-position, not a modal.

### NFR-2: Offline-Capable
The service worker caches the app shell and serves rest notifications without network connectivity.

### NFR-3: Self-Hosted
The system requires no external services beyond a PHP 8.2+ capable web server and SQLite 3. No Composer, no npm, no build pipeline.

### NFR-4: No jQuery
All frontend JS is vanilla. No frameworks, no reactive libraries.

### NFR-5: Secure Auth
Sessions are 90-day, secure, httponly, samesite=Strict. No session fixation vectors. Key stored in environment or gitignored file only.

## Constraints

- PHP 8.2+ with `pdo_sqlite` extension
- Nginx or Apache with PHP-FPM
- Web Bluetooth available only in Chromium-based browsers (Chrome, Edge, Brave). Not available in Safari or Firefox.
- BLE HR monitor must implement the standard GATT Heart Rate Service (0x180D) and Heart Rate Measurement characteristic (0x2A37)
- `navigator.wakeLock` required for cardio screen wake — fallback graceful (screen may dim)
- SQLite WAL mode required for concurrent read performance under load

## Acceptance Criteria

- A user can start a workout, add three exercises, log 15 sets, and finish the workout in under 3 minutes of focused interaction
- The rest timer fires a bell in the app and a notification in the background when the app is in the background
- A new PR on a set shows a visible trophy animation within 500ms of the set save
- The app installs on Android Chrome via Add to Home Screen and runs in standalone mode without browser chrome
- A BLE HR monitor pairs once and reconnects automatically on subsequent page loads without user action
- The database schema is fully defined in `database/schema.sql` with foreign keys enforced
- All pages require auth; no page is accessible without a valid session cookie

---

*Last updated: 2026-04-27*
