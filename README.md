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

Each environment also gets `<project>-<environment>.<server's wildcard
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
devopsy @prod --destroy        # remove it all: containers, database, files (runs destroy)
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

- **Build mode,** devopsy's default (no `mode:` in `config.yaml`): a release uploads the
  whole project as git sees it (about 1 MB) and `deploy` runs `devopsy build`
  there. Composer runs inside the image; `vendor/` and `web/core` are never
  committed or uploaded. Docker's build cache makes a release without
  dependency changes take seconds: composer keeps its downloads in a
  BuildKit cache mount, and the image copies vendor, core and contrib in
  their own layers before the code, so most releases only rebuild the code
  layer (well under 1 MB here).
- **The image** (`.devopsy/Dockerfile`): FrankenPHP (Caddy and PHP 8.4). Code
  is owned by root and readable only; the app runs as `app`, UID 10001, never
  root and never 1000, the deploy user on devopsy servers. The UID is fixed in
  the image, not passed around as a setting.
- **Read-only.** The container's root filesystem is read-only. The only
  writable paths are `/tmp` (a tmpfs, also Caddy's state), `/app/storage`,
  a bind mount of `.devopsy/mnt/storage` (on servers `shared/mnt/storage`)
  holding public files (`sites/default/files` links there) and private
  files, and `/app/logs` (`mnt/logs`), the access log.
- **Destroying.** `destroy`, the environments' destroy step, takes the
  containers down and empties `mnt/` from the database image as root: its
  files belong to `app` and MariaDB's user, which the deploy user cannot
  remove. Then devopsy removes the environment's directory.
- **Ownership.** `shared/mnt` belongs to the deploy user, so the entrypoint
  starts as root, gives `/app/storage` and `/app/logs` to `app`, and drops
  to `app` with `setpriv`. `drush` in the image is a wrapper that does the
  same, so `devopsy exec app drush` never runs as root. `devopsy --shell`
  opens bash as `app` too: `compose exec` skips the entrypoint, so the
  `app` service's labels say so (`devopsy.shell=true`,
  `devopsy.shell.user=app`). `devopsy --shell app --user root` for root,
  `devopsy --shell database` for another service.
- **Settings** (`web/sites/default/settings.php`) come from the environment
  set in `compose.yaml`: database, hash salt, any host (Traefik only routes
  the environment's own), and Traefik as the reverse proxy for client IPs
  and HTTPS.
- **Basic auth.** Set `SITE_BASIC_AUTH=user:password` in `.devopsy/.env`
  (on servers `devopsy @prod --vars set SITE_BASIC_AUTH`, then `devopsy
  @prod reload` or a release) and Caddy asks for it on every request. The
  entrypoint hashes the password and drops the plaintext before PHP starts;
  the healthcheck, from inside the container, skips it. Unset, the site is
  open.
- **Access log.** Caddy logs each request as a JSON line, to the
  container's output (`devopsy @prod logs -f app`) and to
  `/app/logs/access.log`, a mount of its own (`.devopsy/mnt/logs`, on
  servers `shared/mnt/logs`), so it outlives containers and releases and
  stays out of the site's files: rotated at 100MiB, old files compressed, 10 kept, 90 days at
  most (`docker/Caddyfile`). `devopsy goaccess` opens it in
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

## Changing configuration

Configuration lives in `config/sync`, empty until you export it, and is
imported on every deploy once it has some. The code is read-only, so export
from a site to `/tmp` and copy it out, then commit and release it:

```sh
devopsy drush config:export --destination=/tmp/sync --yes
devopsy exec -T app sh -c 'cd /tmp/sync && tar c .' | tar x -C config/sync
```

## Rolling back

`devopsy @prod --rollback` switches to the previous release and runs `deploy`
there, which builds it again (from cache). Database updates are not undone.

## Child environments

`dev` and `pr-*` (`.devopsy/config.yaml`) are children of `prod`: copies of
its site, each with its own containers, URL and `shared/.env`.

```sh
devopsy @dev --release           # the first release clones prod, then runs deploy
devopsy @pr-12 --release         # one per pull request, the same way
devopsy @dev --clone             # copy prod again (asks for the name; --yes skips)
devopsy @dev --clone -- --exclude=css   # arguments after -- go to rsync, for the files
```

- **The first release clones.** After the new release goes live and before
  `deploy`, devopsy copies prod's data into it, so `deploy` (database
  updates, config import, caches) runs on prod's data and no empty site is
  ever served. Later releases keep the child's data; `--clone` copies prod
  again and runs `deploy` afterwards.
- **What is copied:** `storage` (public and private files) and `logs`,
  rsynced by devopsy from prod's server (the `app` service's mounts, with
  the app paused on both sides), and the database as a dump
  (`.devopsy/capabilities/clone/push`, which devopsy runs on prod for
  services labeled `custom`): prod keeps running,
  `mariadb-dump --single-transaction` reads it in one transaction, and the
  child imports it into its own running database, replacing what it had,
  only once the whole dump arrived. Each side uses its own credentials:
  the child keeps its `DB_PASSWORD` and `DRUPAL_HASH_SALT`, since
  `shared/.env` is never cloned.
- **A raw copy instead:** label `database` `devopsy.clone=stop` and
  devopsy rsyncs its datadir with the database stopped on both sides.
  Faster for big databases, but prod's database is down for the copy, and
  the datadir carries prod's users and password hashes while the image's
  root password is random: the child's `DB_PASSWORD` would no longer open
  it, so add `DB_PASSWORD` to prod's `inherit:` too. The push script then
  has nothing left to do (no `custom` service) and is not run.
- **Inherited settings.** prod's `inherit:` names variables every child
  gets from prod's server, on its first release and every clone, prod's
  value winning: `SITE_BASIC_AUTH`, so copies of a closed site stay
  closed, with prod's login.
- **The labels** in `compose.yaml` tell devopsy what to do with each
  service during a copy, on both sides (unlabeled services are left out):
  `devopsy.clone=pause` on `app`, frozen while its mounts are copied
  (Traefik keeps its router: visitors wait, then get their page), and
  `devopsy.clone=custom` on `database`, the push script's. A paused
  database flushes nothing, so it is never `pause`.
- **The maintenance page.** The `maintenance` service (nginx as 10001,
  read-only) answers every request with `.devopsy/docker/maintenance/index.html`,
  status 503 and `Retry-After: 30`. Its router has the app's rule at
  priority 2, below the app's, so it answers at once while the app is
  stopped (Traefik drops the app's router then). The app's router sends its
  502 and 503 answers there too (an `errors` middleware asking for
  `/hold.html`, the same page without `Retry-After`): the server's Traefik
  then keeps retrying the app for a while (its `hold` plugin) before
  showing it, so a restart or a failing start is a wait, not an error
  page. That includes Drupal's own maintenance mode for anonymous
  visitors. Not during a clone: a paused app keeps its router, and
  requests wait instead. Edit the page to brand it.

## Locally

With a local devopsy-template-traefik, the site is at
`https://drupal11.localhost` (the config's project name):

```sh
devopsy deploy
devopsy drush site:install --yes
devopsy drush uli
```

`.devopsy/mnt` holds the local database and files; `devopsy down` and
deleting it starts over.
