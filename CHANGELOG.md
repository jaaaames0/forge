# CHANGELOG

All notable changes to FORGE are documented here.

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
