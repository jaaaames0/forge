-- FORGE Database Schema

CREATE TABLE IF NOT EXISTS exercises (
    id              INTEGER PRIMARY KEY,
    name            TEXT    NOT NULL UNIQUE,
    category        TEXT,   -- 'Barbell', 'Dumbbell', 'Machine', 'Cable', 'Bodyweight', 'Cardio', 'Other'
    muscle_primary  TEXT,   -- 'Chest', 'Back', 'Legs', 'Shoulders', 'Arms', 'Core', 'Glutes', 'Cardio'
    muscle_secondary TEXT,
    is_custom       INTEGER DEFAULT 0,
    notes           TEXT,
    bw_factor       REAL DEFAULT NULL,  -- fraction of bodyweight lifted (null = not a BW exercise)
    rest_category   TEXT DEFAULT NULL   -- 'compound_big4', 'compound', 'bodyweight', 'isolation'
);

CREATE TABLE IF NOT EXISTS workouts (
    id          INTEGER PRIMARY KEY,
    name        TEXT,
    template_id INTEGER REFERENCES templates(id),
    started_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME,
    notes       TEXT,
    bodyweight_kg REAL
);

CREATE TABLE IF NOT EXISTS workout_sets (
    id          INTEGER PRIMARY KEY,
    workout_id  INTEGER NOT NULL REFERENCES workouts(id) ON DELETE CASCADE,
    exercise_id INTEGER NOT NULL REFERENCES exercises(id),
    set_number  INTEGER,
    set_type    TEXT DEFAULT 'working', -- 'warmup', 'working', 'dropset', 'failure'
    weight_kg   REAL,
    reps        INTEGER,
    rpe         REAL,
    duration_sec     INTEGER,
    distance_km      REAL,
    added_weight_kg  REAL DEFAULT NULL, -- for bodyweight exercises: user-entered +KG (weight_kg stores effective load)
    hr_avg           INTEGER DEFAULT NULL,
    hr_max           INTEGER DEFAULT NULL,
    intensity        INTEGER DEFAULT NULL, -- 1-10 effort/resistance rating (cardio)
    notes            TEXT
);

CREATE TABLE IF NOT EXISTS cardio_records (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    exercise_id INTEGER NOT NULL REFERENCES exercises(id),
    record_type TEXT NOT NULL, -- 'avg_speed', 'cardiac_efficiency', 'best_distance'
    value       REAL NOT NULL,
    duration_sec  INTEGER,
    distance_km   REAL,
    avg_hr        INTEGER,
    intensity     INTEGER,
    achieved_at   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS workout_exercises (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    workout_id  INTEGER NOT NULL REFERENCES workouts(id) ON DELETE CASCADE,
    exercise_id INTEGER NOT NULL REFERENCES exercises(id),
    order_num   INTEGER DEFAULT 0,
    UNIQUE(workout_id, exercise_id)
);

CREATE TABLE IF NOT EXISTS templates (
    id               INTEGER PRIMARY KEY,
    name             TEXT NOT NULL,
    notes            TEXT,
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_ab_variant  TEXT DEFAULT NULL  -- 'A' or 'B'; which variant was used last time
);

CREATE TABLE IF NOT EXISTS template_exercises (
    id                      INTEGER PRIMARY KEY,
    template_id             INTEGER NOT NULL REFERENCES templates(id) ON DELETE CASCADE,
    exercise_id             INTEGER NOT NULL REFERENCES exercises(id),
    order_num               INTEGER DEFAULT 0,
    target_sets             INTEGER,
    target_reps_min         INTEGER,
    target_reps_max         INTEGER,
    target_rpe              REAL,
    progression_scheme      TEXT DEFAULT 'double', -- 'linear', 'double', 'rpe'
    progression_increment_kg REAL DEFAULT 2.5,
    ab_group                TEXT DEFAULT NULL      -- NULL = always included, 'A' or 'B' = only on that variant
);

CREATE TABLE IF NOT EXISTS personal_records (
    id             INTEGER PRIMARY KEY,
    exercise_id    INTEGER NOT NULL REFERENCES exercises(id),
    weight_kg      REAL,
    reps           INTEGER,
    estimated_1rm  REAL,
    achieved_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_settings (
    key   TEXT PRIMARY KEY,
    value TEXT
);

CREATE TABLE IF NOT EXISTS body_measurements (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    measured_at     TEXT NOT NULL DEFAULT (datetime('now')),
    waist_cm        REAL,
    neck_cm         REAL,
    hip_cm          REAL,
    caliper_bf_pct  REAL    -- user-entered BF% from skinfold calipers
);

-- Default settings
INSERT OR IGNORE INTO user_settings (key, value) VALUES ('unit', 'kg');
INSERT OR IGNORE INTO user_settings (key, value) VALUES ('rest_timer_sec', '90');
INSERT OR IGNORE INTO user_settings (key, value) VALUES ('1rm_formula', 'epley');
INSERT OR IGNORE INTO user_settings (key, value) VALUES ('time_format', '24h');
