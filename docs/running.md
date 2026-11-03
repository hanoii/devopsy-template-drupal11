# Running the site

## Build mode

The project uses build mode, devopsy's default (no `mode:` in
`config.yaml`). A release uploads the whole project as git sees it,
about 1 MB, and devopsy builds the image on the server before the
release goes live. A failed build changes nothing, and `deploy` only
starts the image. Composer runs inside the image; `vendor/` and
`web/core` are never committed or uploaded.

Docker's build cache makes a release without dependency changes take
seconds. Composer keeps its downloads in a BuildKit cache mount, and the
image copies vendor, core and contrib in separate layers before the
code, so most releases rebuild only the code layer, well under 1 MB here.

Code changes need a release. OPcache never revalidates; the code cannot
change. Add modules with composer, then release.

## Settings

Drupal's settings (`web/sites/default/settings.php`) come from the
environment set in `compose.yaml`: the database, the hash salt, and
Traefik as the reverse proxy for client IPs and HTTPS. Any host is
accepted, since Traefik routes only the environment's own. Only
Traefik's `X-Forwarded-For` and `-Proto` headers are trusted. Plain
Traefik passes a visitor's `Forwarded` on (devopsy-template-traefik
drops it), and Symfony answers a 500 when that header disagrees with the
others.

## Behind a Cloudflare Tunnel

On a server whose sites are all tunneled (devopsy-template-traefik's
[Behind a Cloudflare
Tunnel](https://github.com/hanoii/devopsy-template-traefik/blob/main/docs/tunnel.md)),
nothing changes. On a server with direct sites too, a tunneled site sets
`PROXY_ENTRYPOINTS: tunnel` in its target's `env`, with
`PROXY_CERTRESOLVER` unset, since TLS ends at Cloudflare's edge. Both
routers, the app's and `alwaysup`'s, take it. Uploads through Cloudflare
are at most 100 MB on its Free and Pro plans.

## The access log

Caddy logs each request as a JSON line, to the container's output
(`devopsy @prod logs -f app`) and to `/app/logs/access.log`. That file is
a separate mount (`.devopsy/mnt/logs`, on servers `shared/mnt/logs`),
so it outlives containers and releases and stays out of the site's files.
It is rotated at 100MiB, with old files compressed, 10 kept, for 90 days
at most (`docker/Caddyfile`).

`devopsy goaccess` opens the log in [GoAccess](https://goaccess.io), in
a separate container where the site runs (the `goaccess` service, which
`up` never starts). At a terminal it shows GoAccess's terminal view,
which follows new requests. Redirected, it writes an HTML report
(`devopsy @prod goaccess > report.html`), and `-o json` gives JSON.

It reads only the current file. `--rotated <n>`, or `all`, adds the
latest rotated files, and every run parses them again.

Its settings are in `.devopsy/goaccess.conf`: the log format, and what
counts as a static request. "Requested Files" lists pages and PHP, and
`devopsy @prod goaccess --ignore-statics=panel` shows only those.

FrankenPHP runs PHP inside Caddy, so a Drupal request's `duration` is
PHP's time.

`devopsy accesslog` prints requests as they come, one per line with
their milliseconds, from the container's output through `jq` (the `jq`
service):

- `devopsy @prod accesslog --drupal --min 500` follows the slow ones;
- `--dynamic` leaves static files out (the same extensions as GoAccess,
  from `goaccess.conf`);
- `--status 5xx`, `--path` and `--html` filter further;
- `--no-follow --since 1h` shows what already happened;
- `--history` reads the log files instead, for requests from before the
  last release: `devopsy @prod accesslog --history --since 3d --until 2d
  --status 5xx`.

## The healthcheck

The healthcheck requests `HEALTHCHECK_PATH` (`compose.yaml`, by default
`/robots.txt`, a static file that only Caddy answers), and Caddy keeps
those requests out of the access log. Change it there, or per target in
`.env`, and both follow.

## Updates

The image builds on FrankenPHP's floating `1-php8.4-trixie` tag, and
devopsy builds with `--pull`, so every release builds on that tag's
current image and PHP updates come with releases. After a PHP advisory,
release each environment (`devopsy @prod --release`, and its children)
and check `devopsy @prod --status`, which shows the PHP the app runs.
MariaDB floats on its series too ([Child environments](children.md)).

## Changing configuration

Configuration lives in `config/sync`, empty until you export it, and
every deploy imports it once it holds some. The code is read-only, so
export from a site to `/tmp`, copy it out, then commit and release it:

```sh
devopsy drush config:export --destination=/tmp/sync --yes
devopsy exec -T app sh -c 'cd /tmp/sync && tar c .' | tar x -C config/sync
```

## Rolling back

`devopsy @prod --rollback` switches to the previous release, with the
image it ran, and runs `secrets` and `deploy` there. devopsy keeps each
release's image, so nothing is rebuilt. Database updates are not undone,
and the database keeps the newest image its data ran.

## Destroying

`devopsy @prod --destroy` comes from devopsy; the project has no destroy
step. It takes down the containers, the volumes and the built image,
empties `mnt/` as root (its files belong to `app` and to MariaDB's user),
then removes the environment's directory.
