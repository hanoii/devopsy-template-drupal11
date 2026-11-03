# Security

## The image

The image (`.devopsy/Dockerfile`) is FrankenPHP, Caddy with PHP 8.4. The
code is owned by root and only readable. The image has no setuid or
setgid binaries and no file capabilities. FrankenPHP's capability for
ports below 1024 is removed, and the app listens on 8080.

## No privilege

Every container runs as a user of its own from its first process, never
as root. The app, alwaysup and the tools run as `APP_UID`, 10001 by
default (never 1000, the deploy user on devopsy servers), and MariaDB as
its image's user, 999. Each drops every capability and sets
`no-new-privileges`; alwaysup keeps the one capability its upstream Caddy
binary needs to start. A compromised app finds no way to escalate:
even `devopsy @prod --shell app --user root` is a root without
capabilities, which cannot read the app's mode-600 files, chown anything
or install packages.

Never run drush as root. It leaves root's files in `/tmp/php` and in
storage, which the app cannot replace. `devopsy @prod drush` runs as the
app by default ([Ownership](#ownership)).

`APP_UID` is also the image's build argument, so changing it takes a
release. `--reload` refuses the change and names the variable, since the
image would keep the old user's files.

## What is read-only

Every container's root filesystem is read-only; MariaDB writes its
datadir, and its socket in a tmpfs. The app can write only to:

- `/tmp`, a tmpfs, which also holds Caddy's state;
- `/app/storage`, a bind mount of `.devopsy/mnt/storage` (on servers
  `shared/mnt/storage`), holding public files (`sites/default/files`
  links there) and private files;
- `/app/logs` (`mnt/logs`), the access log.

Caddy never runs PHP from `sites/`, however a request writes the path
(path info, any case, a directory's `index.php`), so what the app writes
there is only served as a file (`.devopsy/docker/Caddyfile`).

Caddy runs only Drupal's entry points, as Drupal's own `web/.htaccess`
allows: `index.php` and `update.php` at the web root, and the scripts
directly in `core/` (`install.php`, `rebuild.php`, `authorize.php`).
`autoload.php`, and any PHP in modules, themes, libraries or core's
tests, gets a 403.

Symbolic links in public files are removed. Caddy follows links, so one
written into public files would serve its target (private files, the
access log) to anyone, across releases. Drupal never creates them. At
every start the app removes any link under `storage/public`, saying so
in its log, and turns `storage/public` and `storage/private` back into
directories when they are links.

Compiled Twig, which is PHP that Drupal includes, lives in `/tmp/php`
instead of Drupal's default `storage/public/php`, so it cannot outlive
the container; `drush cr` clears it. A site installed before can remove
`storage/public/php` by hand once released.

## PHP's limits

Requests are kept small for PHP's parser (`.devopsy/docker/php.ini`):
uploads up to `PHP_UPLOAD_MAX_SIZE`, 32M by default (per target in
`.env`), no upload progress, and nesting up to 32.
`.devopsy/docker/Caddyfile` has `disable_functions` for web requests
ready, commented out. It blocks the shell behind most exploits, and it
breaks modules that shell out.

## Ownership

devopsy sets the mounts' owners. `devopsy.mnt.owner=user` on a service
(`compose.yaml`) makes it create that service's entries of `mnt` and give
them to the service's `user:` at every release, regardless of the owner
their files arrived with (a sync, an import, a copy by hand): `storage` and
`logs` to `APP_UID`, `database` to 999. Nothing starts as root to chown.
This needs a devopsy that takes `user` as the label's value, on the
server too (`devopsy @<server> --upgrade`).

`drush`, `compose exec` and `devopsy --shell` (bash, from the `app`
service's `devopsy.shell` label) run as the service's user too; `devopsy
--shell database` opens another service.

## Basic auth

Set `SITE_BASIC_AUTH=user:password` in `.devopsy/.env`, or on servers
with `devopsy @prod --vars set SITE_BASIC_AUTH` followed by `devopsy
@prod --reload` or a release, and Caddy asks for it on every request.
The entrypoint hashes the password and drops the plaintext before PHP
starts. The healthcheck, from inside the container, skips it. Unset or
empty, the site is open; children are closed by default ([Child
environments](children.md)).
