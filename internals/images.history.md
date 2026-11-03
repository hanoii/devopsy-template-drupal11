History, not rules: AGENTS.md's Images win where they differ.

- FrankenPHP without its file capability failed to start with `cap_drop: [ALL]`, with or without `no-new-privileges`: "exec /usr/local/bin/frankenphp: operation not permitted".
- The entrypoint ran as root before, to chown the mounts and drop to `app` with `setpriv`, which also left Docker's default capabilities in the bounding set. Its refusals were each checked on a Docker volume (macOS bind mounts fake ownership); they catch a devopsy without the owner label and a copy by hand.
- `drush` went through the entrypoint before, to never run as root from `compose exec`, which now runs as the service's user.
- MariaDB as 999 was checked on 11.8.9 with no capabilities: a first start on an empty entry, a restart, an upgrade start from 11.4 with `MARIADB_AUTO_UPGRADE`, and the custom export and import; read-only, a first start, a restart and a recreate, the healthcheck, `mariadb-dump`, and `--export`/`--import database` with `volumes` and `custom` (then `mnt` and `stream`).
- `disable_functions` in `php_ini` applies to the web server only (checked: a web request reports them disabled, `php -r` in the same container none).
- `php.ini`'s narrowing came from the php.net advisory of October 2026 (CVE-2026-109618 and CVE-2026-109619, fixed in 8.4.27).
