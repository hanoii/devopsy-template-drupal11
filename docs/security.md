# Security

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
  `APP_UID` is also the image's build argument, so changing it takes a
  release: `--reload` refuses it, naming it, since the image would keep
  the old user's files.
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
- **Ownership.** devopsy owns the mounts: `devopsy.mnt.owner=user` on a
  service (`compose.yaml`) makes it create that service's entries of
  `mnt` and give them to the service's `user:` at every release, whatever
  owner their files arrived with (a sync, an import, a copy by hand):
  `storage` and `logs` to `APP_UID`, `database` to 999. So nothing starts
  as root to chown, and this needs a devopsy that takes `user` as the
  label's value, on the server too (`devopsy @<server> --upgrade`).
  `drush`, `compose exec` and `devopsy --shell` (bash, the `app`
  service's `devopsy.shell` label) run as the service's user too;
  `devopsy --shell database` for another service.
- **Basic auth.** Set `SITE_BASIC_AUTH=user:password` in `.devopsy/.env`
  (on servers `devopsy @prod --vars set SITE_BASIC_AUTH`, then `devopsy
  @prod --reload` or a release) and Caddy asks for it on every request. The
  entrypoint hashes the password and drops the plaintext before PHP starts;
  the healthcheck, from inside the container, skips it. Unset or empty,
  the site is open (children are closed by default: [Child environments](children.md)).
