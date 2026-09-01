# STATE.md

## Project State Snapshot

- **Stage:** v0.4.0 — stable, in active daily use
- **Deployment:** Production on jaaaames.com (`/srv/jaaaames.com/forge/`). Migration to OptiPlex pending hardware delivery.
- **Current Target:** Migration to OptiPlex once hardware arrives
- **Current Focus:** Maintaining training log; no active feature development

---

## Working Agreements

- All pages/API endpoints start with `require_once __DIR__ . '/../core/auth.php';`
- Database writes use PDO prepared statements exclusively — no string interpolation
- Auth key lives in `core/credentials.env` (gitignored) or `FORGE_ACCESS_KEY` env var
- PR detection runs after every working set save via `maybe_update_pr()`
- Progression hints are stored on `workout_exercises.progression_applied_weight` — not recalculated on page reload
- BLE HR auto-reconnects using `getDevices()` with exponential backoff
- Rest timer uses `localStorage` to persist across page navigation; SW handles background only

---

## Current Sprint Focus

Maintaining training log. No active feature work. The primary blocker is hardware: the OptiPlex server has not yet arrived, so the full infrastructure migration is paused.

---

## Active Task Board

### In Progress

_(nothing currently in active development)_

### Todo

- [ ] Migrate jaaaames.com stack to OptiPlex once hardware arrives
- [ ] Implement multi-user auth rewrite (schema stubs exist; auth.php rewrite)
- [ ] Surface PR highlights in workout history detail view
- [ ] CSV/JSON export
- [ ] Plate calculator

### Deferred

- SPENCE integration (shared profile, dynamic TDEE, combined dashboard)
- Multi-week programs with periodisation waves
- Exercise video links
- Feet/inches unit toggle
- Program library (5/3/1, GZCLP importable templates)
- TWA packaging via bubblewrap

### Done

- v0.4.0 (2026-04-16): BLE HR, cardio mode, HR timeline, PR trophy animation, A/B template fixes
- v0.3.0 (2026-04-08): Profile/settings split, PR redesign, exercise editing, warmup presets
- v0.2.0 (2026-04-05): Service worker, background rest notifications, template delete safety

---

## Blockers

1. **OptiPlex not arrived** — tracking: 363GP5067272 (Australia Post). Public holiday delay. Migration blocked until hardware arrives.
2. **SPENCE integration** — both apps store bodyweight independently; no shared profile or API. Decision needed on how deep integration should go before any work begins.

---

## Decisions Log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2026-04-27 | Bootstrap stateful-projects 7-file docs in project root | Aligning FORGE with workspace documentation standard |
| 2026-04-16 | Ship BLE HR with Karvonen %HRR, not age-predicted MHR | James has actual HR data; age-predicted is inaccurate |
| 2026-04-16 | Store HR samples every 5 seconds, peak from last 20s per set | Balances granularity with storage; 20s window catches set peak reliably |
| 2026-04-16 | Variant stored on workout row, committed to template only on finish | Prevents A/B counter desync on cancelled workouts |
| 2026-04-08 | PR trophy animation: 2s gold grow-fade, no persistent icon | PR flag is in the data; UI shouldn't permanently alter the set row |
| 2026-04-08 | Rest notification: background-only, bell plays in foreground | Users don't want a notification when they're staring at the app |
| 2026-04-05 | SW notification: page client cancels SW timer when app is visible | Prevents double-fire when user returns to app as timer expires |
| Pre-v0.2 | Epley formula default, rep ceiling 30 | Epley validated for 1–30 rep range; Brzycki not used |
| Pre-v0.2 | PDO WAL mode, busy_timeout 5000, foreign_keys ON | WAL prevents read locking under concurrent access |
| Pre-v0.2 | Orange (#FF5722) accent for FORGE, Blue (#00A3FF) for SPENCE | Visual differentiation between the two apps on same server |

---

## Daily Update Template

```
Date: YYYY-MM-DD
- Yesterday:
- Today:
- Risks/Blockers:
- Decisions Needed:
```

---

*Last updated: 2026-04-27*
