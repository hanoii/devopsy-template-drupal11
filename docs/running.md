# Running the site

- **Build mode,** devopsy's default (no `mode:` in `config.yaml`): a
  release uploads the whole project as git sees it (about 1 MB) and devopsy
  builds the image there before the release goes live, so a failed build
  changes nothing and `deploy` only starts it. Composer runs inside the
  image; `vendor/` and `web/core` are never committed or uploaded. Docker's build cache makes a release without
  dependency changes take seconds: composer keeps its downloads in a
  BuildKit cache mount, and the image copies vendor, core and contrib in
  their own layers before the code, so most releases only rebuild the code
  layer (well under 1 MB here).
- **Destroying.** `devopsy @prod --destroy` is devopsy's alone: it takes
  the containers, volumes and the built image down, empties `mnt/` as root
  (its files belong to `app` and MariaDB's user), then removes the
  environment's directory. No destroy step.
- **Settings** (`web/sites/default/settings.php`) come from the environment
  set in `compose.yaml`: database, hash salt, any host (Traefik only routes
  the environment's own), and Traefik as the reverse proxy for client IPs
  and HTTPS, its only headers trusted (`X-Forwarded-For` and `-Proto`:
  Traefik never removes `Forwarded`, which Symfony refuses with a 500 when
  it disagrees with the others).
- **Behind a Cloudflare Tunnel** (devopsy-template-traefik's [Behind a
  Cloudflare Tunnel](https://github.com/hanoii/devopsy-template-traefik/blob/main/docs/tunnel.md)), nothing changes on a server whose sites are all
  tunneled. On a server with direct sites too, a tunneled one sets
  `PROXY_ENTRYPOINTS: tunnel` in its target's `env`, with
  `PROXY_CERTRESOLVER` unset (TLS ends at Cloudflare's edge). Both
  routers, the app's and `alwaysup`'s, take it. Uploads through Cloudflare
  are 100 MB at most on its Free and Pro plans.
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
  the app runs. MariaDB floats on its series too ([Child environments](children.md)).
  Requests are kept small for PHP's parser (`.devopsy/docker/php.ini`):
  uploads up to `PHP_UPLOAD_MAX_SIZE`, 32M by default (per target in
  `.env`), no upload progress, nesting up to 32. `.devopsy/docker/Caddyfile` has
  `disable_functions` for web requests ready, commented out: it blocks
  the shell behind most exploits and breaks modules that shell out.

### Changing configuration

Configuration lives in `config/sync`, empty until you export it, and is
imported on every deploy once it has some. The code is read-only, so export
from a site to `/tmp` and copy it out, then commit and release it:

```sh
devopsy drush config:export --destination=/tmp/sync --yes
devopsy exec -T app sh -c 'cd /tmp/sync && tar c .' | tar x -C config/sync
```

### Rolling back

`devopsy @prod --rollback` switches to the previous release, with the
image it ran (devopsy keeps each release's image: nothing is rebuilt), and
runs `secrets` and `deploy` there. Database updates are not undone, and
the database image never goes back: it stays on the newest image its
data ran.
