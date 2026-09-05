# Deployment layout

Forge is developed in this repository, but the website does not execute code
from this working tree.

- Editable source: `/home/james/src/forge`
- Versioned runtime: `/usr/local/lib/forge/RELEASE`
- Public compatibility path: `/srv/jaaaames.com/forge`
- Mutable database: `/var/lib/forge/forge.db`
- Credentials: `/srv/secrets/forge_credentials.env`

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

Deployments must use a checkpoint, a timed rollback, SQLite integrity checks,
PHP lint, nginx validation, HTTP/authentication tests, and a real application
write before the old runtime is retired.
