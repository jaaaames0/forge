# Deployment layout

Forge is developed in this repository, but the website does not execute code
from this working tree.

- Editable source: `/home/james/src/forge`
- Versioned runtime: `/usr/local/lib/forge/RELEASE`
- Public compatibility path: `/srv/jaaaames.com/forge`
- Mutable database: `/var/lib/forge/forge.db`
- Credential: `/etc/forge/access-key` (`0640 root:forge-web`; the dedicated pool
  supplies the path through `FORGE_CREDENTIAL_FILE`)

The runtime is created from a clean Git archive and is owned by root. Its
`database/forge.db` entry is a symlink to the mutable database. Never copy the
live database into Git or edit PHP below `/srv` or `/usr/local/lib`.

`FORGE_DB_PATH` and `FORGE_CREDENTIAL_FILE` can override the default database
and credential-file paths for tests and dedicated PHP-FPM pools. Credential
values must remain in protected files, not in pool configuration.

The current host has a known PHP CLI loader conflict with
`/usr/local/lib/libsqlite3.so.0`. Until that separate package issue is repaired,
run the regression suite with the system library selected explicitly:

```bash
LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu php tests/regression.php
```

## Deploying

Code-only changes are fast-lane deploys (`/home/james/AGENTS.md`, host handbook
§9.1). Commit, then:

```bash
fastdeploy forge             # tests, build, PHP lint, SQLite snapshot, switch, reload
fastdeploy forge --status
fastdeploy forge --rollback  # back to the previous release
```

`/srv/jaaaames.com/forge` links to `/usr/local/lib/forge/current`, which links to
the release. `fastdeploy` exports an allowlist of tracked runtime paths (see
`/home/james/src/fastdeploy/projects/forge.conf`) into a new root-owned release,
recreates the state symlinks, takes a consistent SQLite snapshot under
`/data/security-backups/fastdeploy/forge`, switches `current`, reloads
`php8.3-fpm`, and switches back if `https://jaaaames.com/forge/` stops
answering. A new top-level runtime directory must be added to that allowlist.

Use the full procedure (checkpoint, timed rollback, operator acceptance) for
changes to the PHP-FPM pool, AppArmor hat, credentials, nginx, or a schema
change the previous release cannot read.
