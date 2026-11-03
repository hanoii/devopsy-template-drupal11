# Visitors during stops

## alwaysup

`alwaysup` is a small Caddy (as `APP_UID`, read-only,
`.devopsy/docker/alwaysup/Caddyfile`) with the app's rule, on a router at
priority 2, below the app's. Traefik sends visitors straight to the app
while its container runs, and to `alwaysup` only while the app's router
is gone: the app stopped for a sync or a deploy, being recreated, or
failing its healthcheck.

`alwaysup` tries the app every second for up to `ALWAYSUP_WAIT` (60 s)
and streams its answer, so a visitor who arrived while the app was down
gets the real page, late. When the app does not come back in time, and
only then, `alwaysup` answers `.devopsy/docker/alwaysup/index.html` with
status 503 and `Retry-After`, for any method. The app's answers pass through
untouched, including Drupal's maintenance mode.

`devopsy @prod maintenance on` takes the site down on purpose. It stops
the app and flags the page (`mnt/maintenance/on`), which shows at once
until `maintenance off` or the next deploy. Edit the
page to brand it. None of this needs the server's Traefik; any Traefik
that routes the project routes `alwaysup` too.

## The hold

There is a gap before the router is gone. A stopping app closes its port
while Traefik still routes to it, and a request in that window gets
Traefik's 502. Both routers name `hold@file`, the hold in the server's
Traefik ([Stopped
sites](https://github.com/hanoii/devopsy-template-traefik/blob/main/docs/hold.md)),
which holds that request until a router answers, including
`alwaysup`'s, and sends the visitor on to the same URL.

The hold needs a Traefik that provides `hold@file`, so release the
server's Traefik first. To opt out, drop `hold@file` from the routers'
`middlewares` labels.
