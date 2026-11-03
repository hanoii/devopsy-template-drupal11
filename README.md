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

Each environment gets a URL, `<project>-<environment>.<server's base
domain>` (its compose project name, like `drupal11-prod`), whose domain each
release imports from the server's proxy (devopsy-template-traefik), through the
`devopsy.import` label in `compose.yaml`: a release fails while no proxy
runs. The release says what it imported; `devopsy @prod --debug imports`
shows it later, and whether the proxy has changed it since. Override it,
or set it empty for none, per target: `devopsy @prod --vars set --show
PROXY_BASE_DOMAIN`.

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

## Documentation

- [Running the site](docs/running.md): build mode, settings, the access
  log, updates, configuration and rollbacks.
- [Security](docs/security.md): the image, privileges, what is read-only,
  ownership and basic auth.
- [Child environments](docs/children.md): syncs from prod, dumps,
  sanitizing, inherited settings and the data labels.
- [Visitors during stops](docs/stops.md): `alwaysup` and the hold.
