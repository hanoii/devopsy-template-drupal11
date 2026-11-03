# AGENTS.md

devopsy-template-drupal11 is Drupal 11 on FrankenPHP with MariaDB, run with [devopsy-cli](https://github.com/hanoii/devopsy-cli): built on the server (build mode), read-only at runtime, children synced from prod. `README.md` is how to use it, kept in step with every change; how a file works is in its comments (`compose.yaml` explains every label).

## Layout

- Everything devopsy reads is in `.devopsy/`: `compose.yaml` (`app`, `database`, `alwaysup`, and the `goaccess` and `jq` tools), `Dockerfile`, `docker/` (the entrypoint, the drush wrapper, Caddy's and PHP's settings, alwaysup's Caddyfile and page), `commands/` and `capabilities/` (`env`, `status`, `data`).
- Drupal's settings come from the environment (`web/sites/default/settings.php`).

## Rules

- No container has privilege: each runs as its own numeric UID from its first process (`user:` in `compose.yaml`), with `cap_drop: [ALL]` and `no-new-privileges`, read-only where the image allows, since the site must hold up with the app fully compromised (its code finds nothing to escalate with, and a `--user 0` shell is a root without capabilities).
- A capability comes back only with a stated reason (alwaysup's `NET_BIND_SERVICE`, below).
- Images built here carry no setuid or setgid binaries and no file capabilities.
- The app's UID is `APP_UID` (10001 by default), never 0 and never 1000 (the deploy user, in the docker group), and every place that names it reads the variable: the app's `user:` and build argument, alwaysup, goaccess (the access log is mode 600) and jq.
- Ownership is devopsy's, through `devopsy.mnt.owner=user` on each service that writes an entry of `mnt` (`storage` and `logs` the app's, `database` 999's), so the data's owner follows the service's `user:` and the two never drift apart.
- Nothing in an image chowns or needs root to start.
- Entries written from the host (`maintenance`, by `deploy`) have no owner label and stay the deploy user's.
- Everything written at runtime goes in `mnt` or `/tmp`: the release tree is read-only and goes with the next release.
- Compiled Twig goes to `/tmp/php` (`php_storage` in settings.php), never `public://php`, since it is PHP Drupal includes and would outlive releases there; an existing site's `storage/public/php` can be removed by hand once released.
- No PHP runs from `sites/`, however a request writes the path (`docker/Caddyfile`): `sites/default/files` is the storage mount, the one place under the web root the app can write, so a file the app was tricked into writing must never become code.
- The `sites/` check sits between php_server's rewrite and its php handler, where the path is the script PHP would run, which is why php_server is written out (FrankenPHP's own expansion, from `frankenphp adapt`).
- The `sites/` match ignores case and is exactly what reaches the php handler, a script or one with path info (`\.php(/|$)`), never a regex on the request path: PHP splits at `.php` ignoring case and passes the rest as path info, and a directory's `index.php` runs from a request with no `.php` in it.
- The match is anchored on what reaches the handler, so files whose names only contain `.php` (for example `my.pharmacy.jpg`, `report.php.pdf`) and their image style derivatives are served.
- When FrankenPHP's php_server changes, compare `frankenphp adapt` of a bare `php_server` with the route, by hand, as part of updating the base's tag or reviewing a FrankenPHP release: a build that diffs the two would block a release carrying PHP's security fix until the Caddyfile caught up.
- Only Drupal's entry scripts run (`docker/Caddyfile`, `@other_php`, beside the `sites/` rule): `index.php` and `update.php` at the web root, and the scripts directly in `core/` without path info (`install.php`, `rebuild.php`, `authorize.php`); code in modules, themes, libraries and core's tests is never meant to run alone.
- The entry-script rule is Drupal's own, from its `web/.htaccess` (`RewriteRule "^(.+/.*|autoload)\.php($|/)" - [F]`, except `RewriteCond %{REQUEST_URI} !/core/[^/]*\.php$`), matched on the script php_server would run, with its path info, ignoring case as PHP's split does; the exception as Drupal writes it, case-sensitive.
- `autoload.php` and `autoload_runtime.php` at the root are refused too (the latter a scaffold file newer than the rule, which run alone only fails).
- The test front controllers `.htaccess` lets through (`core/modules/system/tests/`) stay refused: they are for core's own test runs.
- No symlinks in public files: the entrypoint removes every one under `storage/public` at each start, saying which (`docker/entrypoint`), and fails when it cannot, since Caddy's file_server follows links and has no setting not to, so a link the app was tricked into writing would serve its target anonymously.
- Accepted: a link is served between its creation and the next start.
- `public/` and `private/` themselves, when links, are removed, said, and made again as directories before anything tests them, since `-e`, `-d` and `-w` follow links.
- MariaDB floats on its LTS series (`mariadb:11.8`, never `mariadb:11`, which a plain release would carry across series), so every release pulls its fixes, while devopsy keeps the database going forward only and holds a child on the parent's exact image after a raw datadir sync (an older MariaDB cannot read a newer one's data).
- `MARIADB_AUTO_UPGRADE` updates the system tables when the image moves up.
- The next LTS is an edit in `compose.yaml`, then one `--release --accept-image database` per environment.
- FrankenPHP's base floats (`dunglas/frankenphp:1-php8.4-trixie`), and build mode builds with `--pull`, so PHP's fixes arrive with releases; `--status` prints the PHP the app runs (`capabilities/status/show`).

## Images

- No `USER` in the Dockerfile, `user:` in compose, so the image still runs as root for whoever wants that (a debugging `docker run`), and the entrypoint does not refuse root.
- The `app` user exists only to name the UID and give it `/tmp` as home (Docker sets `HOME` from `/etc/passwd` for a numeric `user:`).
- FrankenPHP's file capability is removed (`setcap -r`), and the app listens on 8080: execve refuses a binary whose file capabilities the bounding set lacks, so with `cap_drop: [ALL]` the upstream binary's `cap_net_bind_service=ep` keeps it from starting.
- alwaysup runs the upstream Caddy image unchanged, whose binary carries the same capability, hence its `cap_add: [NET_BIND_SERVICE]`.
- The entrypoint only hashes the basic-auth password, removes symlinks from public files and in place of `public/` and `private/`, and creates `public/` and `private/`.
- The entrypoint fails with a message saying which, when `storage` is not a mount (missing on the read-only root, or in the container's own filesystem, gone at its next recreate), or `public/` or `private/` is not a directory, not creatable, or there and not writable by the container's user.
- `drush` is a wrapper for `--root` only.
- Never drush as root (`--user 0`, `--shell app --user root`): what it writes (compiled Twig in `/tmp/php`, files in storage) is root's, which the app cannot replace, and a root without capabilities cannot write into the app's own directories at all; run it as the app, `exec`'s default user.
- `accesslog` prints no control characters: the app writes the log and a request's URL can hold any byte, so jq strips them (C0, DEL and C1) from every field before they reach the operator's terminal.
- MariaDB runs as 999 from the start: the official image's entrypoint chowns its datadir only when started as root, and the owner label makes the datadir 999's.
- MariaDB is read-only too: it writes only the datadir, `/run/mysqld` (socket and pid file) and `/tmp`, both tmpfs, the first given to 999 with tmpfs options (`uid=999,gid=999`), since a tmpfs is root's otherwise and Compose's long syntax has no owner.
- MariaDB's image keeps its setuid binaries (not ours to rebuild): without capabilities and with `no-new-privileges` they give nothing.
- MariaDB's warning that `io_uring_queue_init()` failed with EPERM is Docker's seccomp profile, not the hardening; it falls back.
- `disable_functions` for web requests is documented, commented out, never enforced (`docker/Caddyfile`): it blocks the shell behind most exploits and breaks modules that shell out in a request, the site's choice.
- `disable_functions` goes in FrankenPHP's `php_ini`, which applies to the web server only, never in `php.ini`, which also reaches drush, which needs `proc_open`.
- `php.ini` narrows request parsing: `session.upload_progress.enabled = 0` (Drupal core does not use it), `max_input_nesting_level = 32` (PHP's default is 64), and uploads under 64M, `PHP_UPLOAD_MAX_SIZE` (32M), per target.
- PHP 8.3 and later read `${VAR:-default}` in ini files.
- macOS fakes ownership on bind mounts (OrbStack, Docker Desktop), so the owner label, the entrypoint's refusal and an `mnt` import's owners are checked on Linux (an OrbStack machine), and an ownership check runs on a Docker volume.

## Checks

```sh
cd .devopsy && docker run --rm -v "$PWD:/mnt" -w /mnt koalaman/shellcheck:stable -s sh docker/entrypoint docker/drush-wrapper commands/* capabilities/*/*
```

The hardening, on a running environment (`devopsy @<target> exec -T` for one on a server); a chown to oneself succeeds without capabilities, so the `--user 0` check chowns to another user:

```sh
devopsy exec -T app grep -E 'Cap(Eff|Bnd)|NoNewPrivs' /proc/1/status       # zeros, 1
devopsy exec -T database grep -E 'Cap(Eff|Bnd)|NoNewPrivs' /proc/1/status  # zeros, 1
devopsy exec -T --user 0 app sh -c 'chown 10001 /tmp'                      # fails
devopsy exec -T app find / -xdev -perm /6000 -type f                       # nothing
```

PHP the app writes into its files never runs (every line 403; from inside the container, past basic auth):

```sh
devopsy exec -T app sh -c 'cd storage/public && mkdir -p t && for f in t.php t.PHP t/index.php; do echo "<?php echo 1;" > $f; done'
for p in t.php t.php/x t.php%2Fx t.PHP t.PHP/x t/ ../files/t.php ./t.php t/../t.php /t.php; do
  devopsy exec -T app curl -s --path-as-is -o /dev/null -w "%{http_code} $p\n" "http://localhost:8080/sites/default/files/$p"
done
for u in /sites/default//files/t.php /index.php/../sites/default/files/t.php; do
  devopsy exec -T app curl -s --path-as-is -o /dev/null -w "%{http_code} $u\n" "http://localhost:8080$u"
done
devopsy exec -T app sh -c 'cd storage/public && rm -rf t.php t.PHP t'
```

Only Drupal's entry scripts run (the first five reach PHP, a 200, a 302 or Drupal's own 403 with a page; the rest are an empty 403):

```sh
for u in / /index.php/user/login /core/install.php /core/rebuild.php /core/authorize.php \
  /core/install.php/x /autoload.php /autoload_runtime.php /core/lib/Drupal.php \
  /core/modules/contact/tests/drupal-7.contact.database.php /core/modules/system/tests/http.php; do
  devopsy exec -T app curl -s -o /dev/null -w "%{http_code} %{size_download} $u\n" "http://localhost:8080$u"
done
```

A symlink the app plants in public files is gone after a restart, and the app's log names it:

```sh
devopsy exec -T app ln -s /app/storage/private storage/public/p
devopsy restart app && devopsy logs app | grep 'removing the symlink'
```

- Files whose names only contain `.php` are served (200): `my.pharmacy.jpg` and `report.php.pdf` in `storage/public`, and an image style derivative of the first, requested twice (the second from the file Drupal wrote).
- Then the site: the installer, an image upload with an image style, `drush uli`, `drush cr`, `config:export --destination=/tmp/sync`, and `--export`/`--import database` with `DATA_DATABASE=custom` and with the default `volumes`.

## Histories

The root's sections have `internals/rules.history.md`, `internals/images.history.md` and `internals/checks.history.md`, read as Workflow's Docs says.
