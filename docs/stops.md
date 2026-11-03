# Visitors during stops

## alwaysup

`alwaysup` is a small Caddy (as `APP_UID`, read-only,
`.devopsy/docker/alwaysup/Caddyfile`) with the app's rule, on a router at
priority 2, below the app's. Traefik sends visitors straight to the app
while its container runs, and to `alwaysup` only while the app's router
is gone: the app stopped for a sync or a deploy, being recreated, or
failing its healthcheck.

`alwaysup` then tries the app every second for up to `ALWAYSUP_WAIT` (60
s) and streams its answer, so a visitor who arrived meanwhile gets the
real page, late. Only when the app does not come back in time does it
answer `.devopsy/docker/alwaysup/index.html` with status 503 and
`Retry-After`, for any method. The app's own answers pass through
untouched, Drupal's maintenance mode included.

`devopsy @prod maintenance on` is the deliberate outage. It stops the app
and flags the page (`mnt/maintenance/on`), which then shows at once,
without waiting, until `maintenance off` or the next deploy. Edit the
page to brand it. None of this needs the server's Traefik; any Traefik
that routes the project routes `alwaysup` too.

## The hold

Before the router is gone there is a gap: a stopping app closes its port
while Traefik still routes to it, and a request then gets Traefik's 502.
Both routers name `hold@file`, the server's Traefik's hold ([Stopped
sites](https://github.com/hanoii/devopsy-template-traefik/blob/main/docs/hold.md)),
which holds that request until a router answers, `alwaysup` included,
and sends the visitor on to the same URL.

The hold needs a Traefik that has it, so release the server's Traefik
first. To opt out, drop `hold@file` from the routers' `middlewares`
labels.
