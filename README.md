# FORGE

Self-hosted gym and workout tracking web app. Built for personal use on a phone at the gym. Sister app to [SPENCE](../spence/).

![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-7952B3?logo=bootstrap&logoColor=white)

## What it does

- Log workouts from templates with set-by-set tracking (weight, reps, RPE)
- Rest timer that persists when navigating between pages
- Progression suggestions (linear, double progression, RPE-based)
- e1RM tracking and personal record detection
- Exercise stats: best sets, estimated 1RM history, volume trends
- Full session history per exercise
- Body composition tracking (waist/neck/hip measurements, Navy BF% formula)
- Cardio tracking (time, distance, heart rate, cardiac efficiency)
- Installable as a PWA (Add to Home Screen on Android/iOS)

## Tech stack

- **Backend**: PHP 8.2+, no framework
- **Database**: SQLite 3 via PDO (WAL mode)
- **Frontend**: Bootstrap 5.3 dark theme, Bootstrap Icons, Chart.js 4.x, vanilla JS (`fetch()`)
- **Server**: Nginx + PHP-FPM
- **Auth**: Single-user key-based session auth (90-day cookie)

No build step. PHP files are served directly.

## Requirements

- PHP 8.2+ with `pdo_sqlite` extension
- Nginx (or Apache) with PHP-FPM
- SQLite 3
- Write permission on `database/` directory

## Installation

### 1. Clone

```bash
git clone https://github.com/yourusername/forge.git /srv/yoursite.com/forge
```

### 2. Database

```bash
sqlite3 database/forge.db < database/schema.sql
chmod 666 database/forge.db
```

Or copy the blank template (same result):

```bash
cp database/forge_empty.db database/forge.db
chmod 666 database/forge.db
```

~50 common exercises are seeded automatically on first run (barbell, dumbbell, cable, machine, bodyweight, cardio). Templates are not seeded — create your own in the Templates section.

### 3. Auth key

Create `core/credentials.env`:

```
FORGE_ACCESS_KEY=your_secret_passphrase_here
```

Or set it as an environment variable: `FORGE_ACCESS_KEY=...`

Pick something long and random — this is your only login credential.

### 4. Nginx config

```nginx
location /forge/ {
    root /srv/yoursite.com;
    index index.php;
    try_files $uri $uri/ /forge/index.php?$query_string;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### 5. Open in browser

Navigate to `https://yoursite.com/forge/` — you'll be prompted for your access key.

## Add to Home Screen (Android Chrome)

Open FORGE in Chrome, tap the three-dot menu → **Add to Home screen**. The app will install with the FORGE icon and run in standalone mode (no browser chrome).

On iOS Safari: Share → **Add to Home Screen**.

## Directory structure

```
core/           Page shell (auth, DB, CSS, header, active workout bar)
workout/        Workout logger — the main page
templates/      Template builder
exercises/      Exercise library + individual stats
history/        Session history + per-exercise history
progress/       e1RM trends, volume, body comp, cardio PRs
profile/        User settings (bodyweight, height, units)
settings/       App settings (rest timer, 1RM formula)
database/       schema.sql, forge_empty.db (forge.db is gitignored)
```

## Configuration

Settings stored in `user_settings` table, editable via the Settings page:

| Key | Default | Description |
|-----|---------|-------------|
| `unit` | `kg` | Weight unit |
| `rest_timer_sec` | `90` | Default rest timer duration |
| `1rm_formula` | `epley` | 1RM estimation formula |

## Gitignored files

- `database/forge.db` — your live data
- `database/forge.db-wal`, `database/forge.db-shm` — SQLite WAL files
- `core/credentials.env` — your access key
