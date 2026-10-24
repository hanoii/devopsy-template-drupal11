# devopsy-template-drupal11

Drupal 11 on a [devopsy](https://github.com/hanoii/devopsy-cli) server: the
image is built on the server from this project, the code is read-only at
runtime, and MariaDB 11 keeps the data. Use it to try build-mode releases, or
as a starting point for a Drupal site.

## Using this template

A starting point to own, not a dependency: start a project from it on
GitHub ("Use this template"), or clone it and keep this repository as a
remote (`upstream`) to pull its changes when you choose. Nothing updates
your copy, or what runs on your servers, but your own release.

## Try it

Point the `prod` target at your server in `.devopsy/.env` (gitignored), or in
the environment:

```sh
echo DEVOPSY_SERVER=devopsy@203.0.113.10 >> .devopsy/.env
```

Each environment gets a URL, `<project>-<environment>.<server's wildcard
domain>` (its compose project name, like `drupal11-prod`), whose domain each
release imports from the server's proxy (devopsy-template-traefik), through the
`devopsy.import` label in `compose.yaml`: a release fails while no proxy
runs. The release says what it imported; `devopsy @prod --debug imports`
shows it later, and whether the proxy has changed it since. Override it,
or set it empty for none, per target: `devopsy @prod --vars set --show
DEVOPSY_WILDCARD_DOMAIN`.

```sh
devopsy @prod --release          # build on the server and start the site (runs deploy)
devopsy @prod drush site:install --yes   # or browse to the site for the installer
devopsy @prod drush uli        # a one-time login link
devopsy @prod --shell          # bash in the app container, as the app user
devopsy @prod --shell-host     # a shell on the server, in the current release
devopsy @prod logs -f app
devopsy @prod accesslog        # requests as they come (accesslog --help for filters)
devopsy @prod goaccess         # the access log in GoAccess's terminal view (q quits)
devopsy @prod goaccess > report.html   # or as an HTML report
devopsy @prod --releases
devopsy @prod --status         # the release, its containers, the URL, maintenance mode, Drupal's version
devopsy @prod maintenance on   # take the site down on purpose, its page shown at once; off brings it back
devopsy @prod --destroy        # remove it all: containers, database, files
```

The first release generates `DB_PASSWORD` and `DRUPAL_HASH_SALT` into the
server's `shared/.env` (`secrets`, its prepare step, before it goes live),
then `deploy` starts an empty site: it never installs Drupal.
Browse to it for Drupal's installer, which skips the database step since
settings come from the environment, or install with drush. Later deploys
update an installed site: `drush deploy` (database updates, config import,
cache rebuild and deploy hooks) once `config/sync` has exported
configuration, only database updates until then.

## How it works

- **Build mode,** devopsy's default (no `mode:` in `config.yaml`): a
  release uploads the whole project as git sees it (about 1 MB) and devopsy
  builds the image there before the release goes live, so a failed build
  changes nothing and `deploy` only starts it. Composer runs inside the
  image; `vendor/` and `web/core` are never committed or uploaded. Docker's build cache makes a release without
  dependency changes take seconds: composer keeps its downloads in a
  BuildKit cache mount, and the image copies vendor, core and contrib in
  their own layers before the code, so most releases only rebuild the code
  layer (well under 1 MB here).
- **The image** (`.devopsy/Dockerfile`): FrankenPHP (Caddy and PHP 8.4). Code
  is owned by root and readable only. It has no setuid or setgid binaries
  and no file capabilities (FrankenPHP's, for ports below 1024, is
  removed: the app listens on 8080).
- **No privilege.** Every container runs as a user of its own from its
  first process, never root: the app (and alwaysup and the tools) as
  `APP_UID`, 10001 by default (never 1000, the deploy user on devopsy
  servers), MariaDB as its image's user, 999. Each drops every
  capability and sets `no-new-privileges` (alwaysup keeps the one its
  upstream Caddy binary needs to start), so a compromised app finds no
  way up: `devopsy @prod --shell app --user root` is a root without
  capabilities, which cannot read the app's mode-600 files, chown
  anything or install packages. Never run drush as root: it leaves
  root's files in `/tmp/php` and storage that the app cannot replace.
  `devopsy @prod drush`, `exec` and `--shell` run as the app by default.
- **Read-only.** Every container's root filesystem is read-only
  (MariaDB writes its datadir, and its socket in a tmpfs). The app's only
  writable paths are `/tmp` (a tmpfs, also Caddy's state), `/app/storage`,
  a bind mount of `.devopsy/mnt/storage` (on servers `shared/mnt/storage`)
  holding public files (`sites/default/files` links there) and private
  files, and `/app/logs` (`mnt/logs`), the access log. Caddy never runs
  PHP from `sites/`, however a request writes the path (path info, any
  case, a directory's `index.php`): what the app writes there is only
  ever served as a file (`.devopsy/docker/Caddyfile`). Nor does it run
  any other script than Drupal's entry points, as Drupal's own
  `web/.htaccess` allows: `index.php` and `update.php` at the web root
  and the scripts directly in `core/` (`install.php`, `rebuild.php`,
  `authorize.php`); `autoload.php` and any PHP in modules, themes,
  libraries or core's tests is refused (403). Nor a link: Caddy
  follows symlinks, so one written into public files would serve its
  target (private files, the access log) to anyone, across releases.
  Drupal never creates them, and the app removes any under
  `storage/public` at every start, saying so in its log, and
  `storage/public` and `storage/private` themselves when they are links,
  making them directories again.
  Compiled Twig (PHP that Drupal includes) lives in `/tmp/php`, never in
  `storage/public/php` (Drupal's default), so it cannot outlive the
  container; `drush cr` clears it. A site installed before can remove
  `storage/public/php` by hand once released.
- **Destroying.** `devopsy @prod --destroy` is devopsy's alone: it takes
  the containers, volumes and the built image down, empties `mnt/` as root
  (its files belong to `app` and MariaDB's user), then removes the
  environment's directory. No destroy step.
- **Ownership.** devopsy owns the mounts: `devopsy.mnt.owner` on a
  service (`compose.yaml`) makes it create that service's entries of
  `mnt` and give them to the service's user at every release, whatever
  owner their files arrived with (a sync, an import, a copy by hand):
  `storage` and `logs` to `APP_UID`, `database` to 999. So nothing starts
  as root to chown, and this needs a devopsy with that label: an older
  one leaves a new environment's entries as Docker creates them, root's,
  and the app refuses to start, saying so. `drush`, `compose exec` and
  `devopsy --shell` (bash, the `app` service's `devopsy.shell` label) run
  as the service's user too; `devopsy --shell database` for another
  service.
- **Settings** (`web/sites/default/settings.php`) come from the environment
  set in `compose.yaml`: database, hash salt, any host (Traefik only routes
  the environment's own), and Traefik as the reverse proxy for client IPs
  and HTTPS.
- **Basic auth.** Set `SITE_BASIC_AUTH=user:password` in `.devopsy/.env`
  (on servers `devopsy @prod --vars set SITE_BASIC_AUTH`, then `devopsy
  @prod reload` or a release) and Caddy asks for it on every request. The
  entrypoint hashes the password and drops the plaintext before PHP starts;
  the healthcheck, from inside the container, skips it. Unset or empty,
  the site is open (children are closed by default: "Child environments").
- **Access log.** Caddy logs each request as a JSON line, to the
  container's output (`devopsy @prod logs -f app`) and to
  `/app/logs/access.log`, a mount of its own (`.devopsy/mnt/logs`, on
  servers `shared/mnt/logs`), so it outlives containers and releases and
  stays out of the site's files: rotated at 100MiB, old files compressed,
  10 kept, 90 days at most (`docker/Caddyfile`). `devopsy goaccess` opens it in
  [GoAccess](https://goaccess.io), in its own container (the `goaccess`
  service, never started by `up`), where the site runs:
  its terminal view on a terminal, which follows new requests, an HTML
  report when redirected (`devopsy @prod goaccess > report.html`), or `-o
  json`. Only the current file, unless `--rotated <n>` (or `all`) adds the
  latest rotated ones; every run parses them again. Its settings are in
  `.devopsy/goaccess.conf`: the log format and what counts as a static
  request, so "Requested Files" is pages and PHP, and `devopsy @prod
  goaccess --ignore-statics=panel` shows only those. FrankenPHP runs PHP inside
  Caddy, so a Drupal request's `duration` is PHP's time. `devopsy
  accesslog` prints requests as they come, one per line with their
  milliseconds, from the container's output through `jq` (the `jq` service):
  `devopsy @prod accesslog --drupal --min 500` follows the slow ones,
  `--dynamic` leaves static files out (the same extensions as GoAccess,
  from `goaccess.conf`), `--status 5xx`, `--path`, `--html`; `--no-follow
  --since 1h` for what already happened. `--history` reads the log files
  instead, for requests from before the last release: `devopsy @prod
  accesslog --history --since 3d --until 2d --status 5xx`.
- **The healthcheck** requests `HEALTHCHECK_PATH` (`compose.yaml`, default
  `/robots.txt`: a static file, so only Caddy answers), and Caddy keeps
  those requests out of the access log. Change it there, or per target in
  `.env`, and both follow.
- **Code changes need a release.** OPcache never revalidates (the code cannot
  change), and modules are added with composer, then released.
- **PHP updates come with releases.** The image builds on FrankenPHP's
  floating `1-php8.4-trixie` tag, and devopsy builds with `--pull`, so
  every release builds on that tag's current image: PHP's security fixes
  reach an environment at its next release, and nothing before. After a
  PHP advisory, release each environment (`devopsy @prod --release`, and
  its children) and check `devopsy @prod --status`, which says the PHP
  the app runs. MariaDB is pinned instead ("Child environments": a raw
  datadir must never meet an older server). Requests are kept small for
  PHP's parser (`.devopsy/docker/php.ini`): uploads up to
  `PHP_UPLOAD_MAX_SIZE`, 32M by default (per target in `.env`), no upload
  progress, nesting up to 32. `.devopsy/docker/Caddyfile` has
  `disable_functions` for web requests ready, commented out: it blocks
  the shell behind most exploits and breaks modules that shell out.

## Changing configuration

Configuration lives in `config/sync`, empty until you export it, and is
imported on every deploy once it has some. The code is read-only, so export
from a site to `/tmp` and copy it out, then commit and release it:

```sh
devopsy drush config:export --destination=/tmp/sync --yes
devopsy exec -T app sh -c 'cd /tmp/sync && tar c .' | tar x -C config/sync
```

## Rolling back

`devopsy @prod --rollback` switches to the previous release, with the
image it ran (devopsy keeps each release's image: nothing is rebuilt), and
runs `secrets` and `deploy` there. Database updates are not undone.

## Child environments

`dev` and `pr-*` (`.devopsy/config.yaml`) are children of `prod`: copies of
its site, each with its own containers, URL and `shared/.env`.

```sh
devopsy @dev --release           # the first release syncs prod, then runs deploy
devopsy @pr-12 --release         # one per pull request, the same way
devopsy @dev --sync             # copy prod again (asks for the name; --yes skips)
devopsy @dev --sync -- --exclude=css   # arguments after -- go to rsync, for the files
```

- **The first release syncs.** After the new release goes live and before
  `deploy`, devopsy copies prod's data into it, so `deploy` (database
  updates, config import, caches) runs on prod's data and no empty site is
  ever served. Later releases keep the child's data; `--sync` copies prod
  again and runs `deploy` afterwards. A copy that did not complete (cut
  halfway, a lost connection) leaves the child's app and database down,
  and devopsy refuses releases that do not sync, and rollbacks, until a
  sync completes (`--sync` or `--release-sync`).
- **What is copied:** `storage` (public and private files), the `app`
  service's mount (not `logs`, its access log: `devopsy.data.exclude`
  keeps each environment's own), and the database's datadir, rsynced by
  devopsy from prod's server with the database stopped on both sides
  (`own`) and the app with it, depending on it (the app first: its
  requests in flight finish, then the database): files and database from
  the same moment, and prod is down for as long as the copy takes (the
  first time everything, later only what changed), its visitors waiting
  on `alwaysup` meanwhile (below). The datadir carries prod's users and
  password hashes, and the image's root password is random, so children
  use prod's `DB_PASSWORD` (inherited). `DRUPAL_HASH_SALT` stays the
  child's own. A raw copy needs the same MariaDB on both sides (older
  cannot read newer), so `compose.yaml` pins the exact version: bump it
  there, and each environment upgrades at its next release.
- **A dump instead:** `DATA_DATABASE=stream` on prod (`devopsy @prod
  --vars set --show DATA_DATABASE stream`; children inherit it, so both
  sides agree) streams the database instead of copying its datadir:
  devopsy runs `.devopsy/capabilities/data/export database` on prod,
  where `mariadb-dump --single-transaction` reads it in one transaction,
  and, only once the whole dump arrived, `import database` on the child,
  which replaces its database with it (devopsy has it up first). The
  database keeps running on both sides (the `env` capability sets
  `DATA_DATABASE_STOP` to `never` then), and so does prod's app, its
  files rsynced as they are; the child's app is down while its database
  loads (`devopsy.data.stop=import`). Slower for big databases, no
  downtime on prod. The scripts are also the example of a `stream`
  service for your own services.
- **Exports and imports.** `--export` and `--import` move one service's
  data the way its label says, outside a sync. With
  `DATA_DATABASE=stream` the database's are the same `export` and
  `import` scripts, so `devopsy @prod --export database > dump.sql` is
  the way to get a dump of prod (the site running), and `devopsy
  --import database < dump.sql` loads it locally (with
  `DATA_DATABASE=stream` in `.devopsy/.env` too: each side reads its own
  label), or into a child (`devopsy @dev --import database < dump.sql`,
  which asks for the environment's full name, `<project>/dev`). An import
  stops the app for the load and starts it after; one that fails keeps it
  down, and nothing runs on the database until an import completes, or
  `devopsy @dev --import database --resolved` accepts it as it is (an
  error near the end of a dump, fixed by hand). With the default `mnt`,
  `--export database` is instead a tar of the datadir, the database and
  the app stopped around it, and `devopsy @prod --export app > files.tar`
  is always a tar of the site's files, the site running.
- **Sanitized and closed.** After the copy, before `deploy`, the data
  capability's `finish` runs on the child (`.devopsy/capabilities/data/finish`):
  `drush sql:sanitize` scrubs every user's email and password, so no
  production password opens a child and no mail from it reaches anyone
  (`devopsy @dev drush uli` logs you in); if it fails, the app is stopped
  and the sync fails, so prod's data is never served unsanitized, and the
  next sync's `finish` starts the app again to sanitize. A child
  with no `SITE_BASIC_AUTH`, inherited or its own, gets one generated into
  its `shared/.env`, user `preview`, the password printed that once. To open
  a child on purpose, set the variable empty and reload:
  `SITE_BASIC_AUTH= devopsy @dev --vars set SITE_BASIC_AUTH`, then
  `devopsy @dev reload`; set but empty stays open through later syncs,
  unset would be closed again.
- **Inherited settings.** prod's `inherit:` names variables every child
  gets from prod's server into its own `shared/inherit.env`, on its first
  release, every sync and `devopsy @dev --inherit`: `DATA_DATABASE`
  (above), `DB_PASSWORD` (a raw datadir carries prod's password, a dump
  is imported into a database created with it: the same either way, so
  prod can switch between them under existing children), and
  `SITE_BASIC_AUTH`, so copies of a closed site stay closed, with prod's
  login. A child's own `shared/.env` wins over the inherited file:
  `devopsy @dev --vars set` a value to keep it from following prod,
  `unset` it to follow again.
- **The labels** in `compose.yaml` tell devopsy what to do with each
  service during a copy, read on each side from its own release (the two
  sides must agree, hence the inherited `DATA_DATABASE`; devopsy refuses
  to copy a datadir under a child's running database). `devopsy.data`
  says how the data moves: `mnt` on `app`, `${DATA_DATABASE:-mnt}` on
  `database`. `devopsy.data.stop` says when it is stopped: `import` on
  `app` (while any data is imported), `${DATA_DATABASE_STOP:-own}` on
  `database` (while its own data moves), which the `env` capability
  turns to `never` with `DATA_DATABASE=stream`; and a service that
  `depends_on` a stopped one stops with it, the app with the database.
  Set it yourself to choose otherwise, but never copy a running datadir:
  with `mnt` on the database, keep it `own`.
- **Always there: `alwaysup`.** A small Caddy (as `APP_UID`, read-only,
  `.devopsy/docker/alwaysup/Caddyfile`) with the app's rule on a router
  at priority 2, below the app's: Traefik sends visitors straight to the
  app while its container runs, and to `alwaysup` only while the app's
  router is gone, stopped for a sync or a deploy, recreating, failing
  its healthcheck. Then Caddy tries the app every second for up to
  `ALWAYSUP_WAIT` (60 s) and streams its answer, so a visitor who
  arrived meanwhile gets the real page, late. Only when the app does not
  come back in time does it answer `.devopsy/docker/alwaysup/index.html`
  with status 503 and `Retry-After`, whatever the method. The app's own
  answers, Drupal's maintenance mode included, pass through untouched.
  `devopsy @prod maintenance on` is the deliberate outage: it stops the
  app and flags the page (`mnt/maintenance/on`), which then shows at
  once, no waiting, until `maintenance off` or the next deploy. Edit the
  page to brand it. Nothing of this needs the server's Traefik: any
  Traefik that routes the project routes it.

## Locally

With a local devopsy-template-traefik, the site is at
`https://drupal11.localhost` (the config's project name):

```sh
devopsy --release
devopsy drush site:install --yes
devopsy drush uli
```

`devopsy --release` runs the release steps in place, as a server does:
secrets, the image built, then `deploy`. Run it again after changing the
code; `devopsy up -d --build` only rebuilds and restarts.

`.devopsy/mnt` holds the local database and files; `devopsy --destroy`
removes them with the containers (its files are the app's and MariaDB's,
which you cannot delete yourself), and `devopsy --release` starts over.
