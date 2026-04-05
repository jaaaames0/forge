# ROADMAP

Ideas and future directions for FORGE. Not prioritised — just things worth building when the time is right.

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
- **Dedicated settings page** — if settings expand beyond a few toggles, move rest alerts + timer settings into a single gear-icon settings surface.
