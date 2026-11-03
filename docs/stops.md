# Visitors during stops

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
- **The gap before it: `hold@file`.** A stopping app closes its port at
  once and finishes what is in flight, and Traefik keeps its router until
  Docker reports the container gone or unhealthy: a request meanwhile
  meets the closed port, Traefik's 502. Both routers name `hold@file`,
  the server's Traefik's hold ([Stopped sites](https://github.com/hanoii/devopsy-template-traefik/blob/main/docs/hold.md)): it
  holds that request until a router answers, alwaysup included, and
  sends the visitor on to the same URL. It needs a Traefik that has it:
  release the server's Traefik first. Drop it from the routers'
  `middlewares` labels to opt out.
