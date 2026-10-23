# AGENTS.md

devopsy-template-drupal11 is Drupal 11 on FrankenPHP with MariaDB, run
with [devopsy-cli](https://github.com/hanoii/devopsy-cli): built on the
server (build mode), read-only at runtime, children synced from prod.
`README.md` is how to use it; keep it in step with every change.

This file holds the rules, the decisions with their reasons, and the
findings a later session must not have to rediscover. How a file works
is in its comments (`compose.yaml` explains every label).

## Layout

Everything devopsy reads is in `.devopsy/`: `compose.yaml` (`app`,
`database`, `alwaysup`, and the `goaccess` and `jq` tools), `Dockerfile`,
`docker/` (the entrypoint, the drush wrapper, Caddy's and PHP's
settings, alwaysup's Caddyfile and page), `commands/` and
`capabilities/` (`env`, `status`, `data`). Drupal's settings come from
the environment (`web/sites/default/settings.php`).

## Rules

- **No container has privilege, ever:** each runs as its own numeric
  UID from its first process (`user:` in `compose.yaml`), with
  `cap_drop: [ALL]` and `no-new-privileges`, read-only where the image
  allows; a capability comes back only with a stated reason (alwaysup's
  `NET_BIND_SERVICE`, below). Images built here carry no setuid or
  setgid binaries and no file capabilities. Why: the site must hold up
  with the app fully compromised, and code running as the app then finds
  nothing to escalate with; a `--user 0` shell is a root without
  capabilities.
- **The app's UID is `APP_UID`** (10001 by default), never 0 and never
  1000 (the deploy user, in the docker group), and every place that
  names it reads the variable: the app's `user:` and build argument, its
  `devopsy.mnt.owner`, alwaysup, goaccess (the access log is mode 600)
  and jq.
- **Ownership is devopsy's, through `devopsy.mnt.owner`,** on each
  service that writes an entry of `mnt`: `storage` and `logs` the app's,
  `database` 999's. Keep each label in step with its service's `user:`:
  the label is explicit on purpose, so a change of `user:` never changes
  the data's owner silently. Nothing in an image chowns or needs root to
  start. Entries written from the host (`maintenance`, by `deploy`) have
  no owner label and stay the deploy user's.
- **Everything written at runtime lives in `mnt` or `/tmp`:** the
  release tree is read-only and goes with the next release.
  Compiled Twig is PHP Drupal includes, so it goes to `/tmp/php`
  (`php_storage` in settings.php), not to `public://php`, where it would
  outlive releases; an existing site's `storage/public/php` can be
  removed by hand once released.
- **No PHP ever runs from `sites/`,** however a request writes the path
  (`docker/Caddyfile`): `sites/default/files` is the storage mount, the
  one place under the web root the app can write, so a file the app was
  tricked into writing must never become code. The check sits between
  php_server's rewrite and its php handler, which is why php_server is
  written out (FrankenPHP's own expansion, from `frankenphp adapt`):
  there the path is the script PHP would run. Why not a regex on the
  request path, as before: PHP splits at `.php` ignoring case and
  passes the rest as path info, so `evil.php/x`, `evil.php%2Fx` and
  `evil.PHP` ran, and a directory's `index.php` runs from a request
  with no `.php` in it (`files/evil/`). The match ignores case and is
  not anchored at the end. When FrankenPHP's php_server changes, compare
  `frankenphp adapt` of a bare `php_server` with the route.
- **Pin MariaDB exactly** (`mariadb:11.8.9`), and bump it on purpose: a
  sync copies the datadir raw between servers, and a server keeps the
  image it pulled first, so a floating tag would let a datadir meet an
  older server, which cannot read it (downgrades are unsupported).
  `MARIADB_AUTO_UPGRADE` updates the system tables when the pin moves
  up.
- **Do not pin FrankenPHP:** the base, `dunglas/frankenphp:1-php8.4-trixie`,
  floats, and devopsy's build mode builds with `--pull`, so each release
  builds on the tag's current image and PHP's fixes arrive with
  releases. MariaDB's reason has no PHP equivalent. `--status` prints
  the PHP the app runs (`capabilities/status/show`).

## Decisions and gotchas

- **No `USER` in the Dockerfile; `user:` in compose:** the image still
  runs as root when someone wants that (a debugging `docker run`), and
  the entrypoint does not refuse root. The `app` user exists only to
  name the UID and give it `/tmp` as home (Docker sets `HOME` from
  `/etc/passwd` for a numeric `user:`).
- **FrankenPHP's file capability is removed** (`setcap -r`): the
  upstream binary carries `cap_net_bind_service=ep`, and execve refuses
  a binary whose file capabilities the bounding set lacks, so with
  `cap_drop: [ALL]` it does not start ("exec /usr/local/bin/frankenphp:
  operation not permitted"), with or without `no-new-privileges`. We
  listen on 8080. alwaysup runs the upstream Caddy image unchanged,
  whose binary carries the same capability: hence its `cap_add:
  [NET_BIND_SERVICE]`.
- **The entrypoint only hashes the basic-auth password and creates
  `public/` and `private/`:** it ran as root before, to chown the mounts
  and drop to `app` with `setpriv`, which also left Docker's default
  capabilities in the bounding set. It fails with a message when
  `storage` is not writable (a devopsy without the owner label).
- **`drush` is a wrapper for `--root` only:** it went through the
  entrypoint to never run as root from `compose exec`, which now runs
  as the service's user.
- **MariaDB as 999 from the start:** the official image's entrypoint
  starts as root only to chown its datadir and skips that when started
  as its user, provided the datadir is 999's, which the label
  guarantees. Read-only too: it writes only the datadir, `/run/mysqld`
  (socket and pid file) and `/tmp`, both tmpfs, the first given to 999
  with tmpfs options (`uid=999,gid=999`; a tmpfs is root's otherwise,
  and Compose's long syntax has no owner). Checked on 11.8.9 with no
  capabilities: a first start on an empty entry, a restart, an upgrade
  start from 11.4 with `MARIADB_AUTO_UPGRADE`, and the stream export and
  import; read-only, a first start, a restart and a recreate, the
  healthcheck, `mariadb-dump`, and `--export`/`--import database` with
  `mnt` and `stream`. Its image
  keeps its setuid binaries (not ours to rebuild); without capabilities
  and with `no-new-privileges` they give nothing. Its log warns that
  `io_uring_queue_init()` failed with EPERM: Docker's seccomp profile,
  not the hardening; it falls back.
- **`disable_functions` for web requests is documented, commented out,
  never enforced** (`docker/Caddyfile`): it blocks the shell behind
  most exploits and breaks modules that shell out in a request, the
  site's choice. In FrankenPHP's `php_ini`, which applies to the web
  server only (checked: a web request reports them disabled, `php -r`
  in the same container none), never in `php.ini`, which also reaches
  drush, which needs `proc_open`.
- **`php.ini` narrows request parsing** (the php.net advisory of
  October 2026, CVE-2026-109618 and CVE-2026-109619, fixed in 8.4.27):
  `session.upload_progress.enabled = 0` (Drupal core does not use it),
  `max_input_nesting_level = 32` (PHP's default is 64), and uploads
  under 64M, `PHP_UPLOAD_MAX_SIZE` (32M), per target. PHP 8.3 and
  later read `${VAR:-default}` in ini files.
- **macOS fakes ownership on bind mounts** (OrbStack, Docker Desktop):
  locally every file looks right whoever owns it, so the owner label,
  the entrypoint's refusal and an `mnt` import's owners are only
  meaningful on Linux (an OrbStack machine).

## Checks

```sh
cd .devopsy && docker run --rm -v "$PWD:/mnt" -w /mnt koalaman/shellcheck:stable -s sh docker/entrypoint docker/drush-wrapper commands/* capabilities/*/*
```

The hardening, on a running environment (`devopsy @<target> exec -T`
for one on a server):

```sh
devopsy exec -T app grep -E 'Cap(Eff|Bnd)|NoNewPrivs' /proc/1/status       # zeros, 1
devopsy exec -T database grep -E 'Cap(Eff|Bnd)|NoNewPrivs' /proc/1/status  # zeros, 1
devopsy exec -T --user 0 app sh -c 'chown 10001 /tmp'                      # fails
devopsy exec -T app find / -xdev -perm /6000 -type f                       # nothing
```

A chown to oneself succeeds without capabilities, so the `--user 0`
check chowns to another user. PHP the app writes into its files never
runs (every line 403; from inside the container, past basic auth):

```sh
devopsy exec -T app sh -c 'cd storage/public && echo "<?php echo 1;" > t.php && cp t.php t.PHP && mkdir -p t && cp t.php t/index.php'
for p in t.php t.php/x t.php%2Fx t.PHP t.PHP/x t/ ../files/t.php; do
  devopsy exec -T app curl -s --path-as-is -o /dev/null -w "%{http_code} $p\n" "http://localhost:8080/sites/default/files/$p"
done
devopsy exec -T app sh -c 'cd storage/public && rm -r t.php t.PHP t'
```

Then the site: the installer, an image
upload with an image style, `drush uli`, `drush cr`, `config:export
--destination=/tmp/sync`, and `--export`/`--import database` with
`DATA_DATABASE=stream` and with the default `mnt`.

## Commits

Conventional commits (`feat:`, `fix:`, `docs:`, `build:`...), `!` for a
change existing environments must act on.
