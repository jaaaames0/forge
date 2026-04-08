# CHANGELOG

All notable changes to FORGE are documented here.

## v0.3.0 - 2026-04-08

### Added
- Profile → Settings tab (split from Profile page) with rest alert controls (bell sound + system notification toggles) accessible via gear icon modal. Auto-dismiss of system rest-timer notification when user returns to FORGE.
- Editable warmup presets per category (compound, bodyweight, accessory). Each preset is a list of `{ pct, reps }` rows where `pct` is a % dropdown (Bar, 50%–95%) or null for empty bar.
- PR badge column in workout set table: small red **PR** badge between RPE and tick for sets beating all-time estimated 1RM. PR trophy burst animation (2-second gold trophy grow-and-fade) on tick; no persistent trophy icon.
- No rest timer on final set: completing the last working set across all exercises cancels any active timer and shows a green **FINISH WORKOUT** button in the active bar on all pages.
- Template cards on the workout start screen show "Last performed X" and exercise tags (name × sets). EDIT mode toggle and trash icons removed; pencil EDIT button always visible. Template editor's Delete button moved to top-right header. Templates list page (`/forge/templates/`) uses the same card design.
- All exercises (seeded and custom) now editable from their individual page via a pencil icon. `rest_category` field exposed in exercise editor (`compound_big4`, `compound`, `bodyweight`, `isolation`).
- Exercise reordering within active workout via up/down chevron buttons in each exercise card header, persisted immediately to the database. Smart insertion places new exercises after the most recently active exercise. Viewport follows moved/added exercises below the sticky header.

### Changed
- Profile page split into USER (identity, biometrics, measurements) and SETTINGS (rest alerts, warmup presets) tabs. Identity card uses tighter 2-column layout; height field integer-only with `max="250"`.
- Warmup calculator: bar checkbox replaced with `%` dropdown (Bar, 50%–95%); `pct: null` represents bar sets; `buildWarmupSets` reads `pct == null` instead of `s.bar`. Bodyweight exercises excluded from warmup icon/calculation.
- PR row highlight removed: completed sets no longer get a red background/border regardless of PR status. Epley formula ceiling raised from 12 to 30 reps across all six locations (`api.php`, `db_helper.php`, `workout/index.php`, `exercises/index.php`, `history/index.php`, `progress_api.php`). Session-best 1RM comparison uses `ROUND(..., 1)` for floating-point consistency.
- All exercises now editable regardless of `is_custom` flag (distinction preserved only for delete eligibility). Pencil buttons removed from exercise list and recently-used rows.
- "Next up" bar scan checks **all** exercise blocks in order (previously only forward from the most recently active block), correctly identifying incomplete exercises earlier in the workout.
- `+SET` / `−SET` button bar stays in viewport while spam-tapping — viewport auto-scrolls by the exact row height after each add/remove.
- SPENCE nav link opens in a new tab (`target="_blank" rel="noopener"`).
- Progression hint `(last best: N)` parenthetical removed (value was misleading); suggestion + prefilled weights survive page reload via `workout_exercises.progression_applied_weight`. Hint hides only when all working sets logged AND suggestion never accepted.

### Fixed
- Tapping `− SET` when only one set exists removes the exercise entirely (with confirmation), instead of leaving an empty exercise block.
- Rest-timer notification: `fireNotification` made synchronous when timer already expired; negative `setTimeout` delay avoided.
- Cardio exercise page: top row shows Sessions, Total KM, Total Time; second row shows Best Speed, Best Distance, Best Duration record cards. `maybe_update_cardio_pr` now tracks `best_duration` (longest continuous effort). Stats query includes `total_duration_sec`. Workout history detail view uses correct columns (#, TYPE, TIME, KM, AVG SPEED, AVG HR, MAX HR); AVG SPEED computed as distance/duration per set.
- Finish Workout and Cancel Workout buttons cancel any active rest timer before opening/deleting the modal.
- Workout done state reverts correctly when a set is un-ticked, a set is added, a drop set is added, or a new exercise is added.
- Exercise stat cards on individual exercise pages have uniform height (`height:100%`); e1RM and 5RM cards no longer have orange/red borders.
- Rest timer settings moved from the Exercises page modal to Profile → Settings tab; "TIMERS" button removed from the exercises list view.

## v0.2.0 - 2026-04-05

### Added
- Service worker (`sw.js`) for rest-timer background notifications.
- Rest alert preferences:
  - Bell sound toggle.
  - System notification toggle.
- Rest alert controls on Profile page.
- Template delete controls:
  - In workout start-screen edit mode.
  - In template editor.
- Session-level PR persistence (`workout_sets.is_pr`) with trophy rendering on saved rows.
- Live "Next up" updates in active workout bar after each logged set.
- Long-press row delete guard to avoid accidental delete while interacting with inputs.
- Workout-level per-exercise `target_sets` persistence for active workouts (including non-template workouts).

### Changed
- Workout page now correctly preserves active workout render state after header/nav changes.
- Active workout bar now uses workout-level `target_sets` (falls back to template target sets, then default 3).
- Reps are now required for non-cardio set saves (frontend and backend validation).
- Notification behavior is now background-only:
  - No popup while FORGE is already visible/focused.
  - Popup remains available when app is backgrounded.
- Dashboard template rotation query improved (`last_used`/usage ordering).

### Fixed
- Split active-workout state bug where footer showed active while workout body rendered start screen.
- "All sets done" false positives caused by non-persisted added-set rows after reload.
- Notification toggle fallback behavior when active workout bar helper is unavailable.
- Template delete safety:
  - Deletion blocked if template is used by current active workout.
  - Historical workouts are preserved by nulling `workouts.template_id` before template deletion.
