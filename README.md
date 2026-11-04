# devopsy-template-drupal11

Drupal 11 on a [devopsy](https://github.com/hanoii/devopsy-cli) server.
The image is built on the server from this project and its code is
read-only at runtime; MariaDB 11 keeps the data. Use it to try
build-mode releases, or as a starting point for a Drupal site.

## Using this template

This template is meant to be copied and changed. Start a project from it
on GitHub ("Use this template"), or clone it and keep this repository as a
remote (`upstream`) to pull its changes when you choose. Nothing from
this repository reaches your copy or your servers until you pull its
changes and release them.

## Try it

Point the `prod` target at your server in `.devopsy/.env` (gitignored),
or in the environment:

```sh
echo DEVOPSY_SERVER=devopsy@203.0.113.10 >> .devopsy/.env
```

Each environment gets a URL, `<project>-<environment>.<server's base
domain>`, named after its compose project (`drupal11-prod`). Every
release imports the base domain from the server's proxy
([devopsy-template-traefik](https://github.com/hanoii/devopsy-template-traefik))
through the `devopsy.import` label in `compose.yaml`, and fails while no
proxy runs. The release prints what it imported. `devopsy @prod --debug
imports` shows it later, and whether the proxy has changed it since.
Release the server's Traefik first, since an older one does not join
this project's network.
Override it per target, or set it empty for no wildcard URL, with
`devopsy @prod --vars set --show PROXY_BASE_DOMAIN`.

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
devopsy @prod --status         # the release, its containers, the URLs, maintenance mode, Drupal's version
devopsy @prod maintenance on   # take the site down on purpose and show its page; off brings it back
devopsy @prod --destroy        # remove it all: containers, database, files
```

On the first release, the `secrets` prepare step generates `DB_PASSWORD`
and `DRUPAL_HASH_SALT` into the server's `shared/.env`, before the
release goes live. `deploy` starts an empty site and never installs
Drupal.
Browse to the site for Drupal's installer, which skips the database step
because the settings come from the environment, or install with drush.

Later deploys update an installed site. Once `config/sync` holds
exported configuration, they run `drush deploy` (database updates,
config import, cache rebuild and deploy hooks); until then, only the
database updates.

## Locally

With a local devopsy-template-traefik, the site is at
`https://drupal11.localhost` (the config's project name):

```sh
devopsy --release
devopsy drush site:install --yes
devopsy drush uli
```

`devopsy --release` runs the release steps in place, as a server does:
`secrets`, the image build, then `deploy`. Run it again after changing
the code; `devopsy up -d --build` only rebuilds and restarts.

`.devopsy/mnt` holds the local database and files. Its files belong to
the app's and MariaDB's users, so you cannot delete them yourself:
`devopsy --destroy` removes them with the containers, and `devopsy
--release` starts over.

## Documentation

- [Running the site](docs/running.md): build mode, settings, the access
  log, updates, configuration and rollbacks.
- [Security](docs/security.md): the image, privileges, what is read-only,
  ownership and basic auth.
- [Child environments](docs/children.md): syncs from prod, dumps,
  sanitizing, inherited settings and the data labels.
- [Visitors during stops](docs/stops.md): `alwaysup` and the hold.
