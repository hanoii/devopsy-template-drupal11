# Child environments

`dev` and `pr-*` (`.devopsy/config.yaml`) are children of `prod`: copies of
its site, each with its own containers, URL and `shared/.env`.

```sh
devopsy @dev --release           # the first release syncs prod, then runs deploy
devopsy @pr-12 --release         # one per pull request, the same way
devopsy @dev --sync             # copy prod again (asks for the name; --yes skips)
```

- **The first release syncs.** After the new release goes live and before
  `deploy`, devopsy copies prod's data into it, so `deploy` (database
  updates, config import, caches) runs on prod's data and no empty site is
  ever served. Later releases keep the child's data; `--sync` copies prod
  again and runs `deploy` afterwards. A copy that did not complete (cut
  halfway, a lost connection) leaves the child's app and database down,
  and devopsy refuses releases that do not sync, and rollbacks, until a
  sync completes (`--sync` or `--release-sync`).
- **What is copied:** `storage` (public and private files), the `app`
  service's mount (not `logs`, its access log: `devopsy.data.exclude`
  keeps each environment's own), and the database's datadir, rsynced by
  devopsy from prod's server with the database stopped on both sides
  (`own`) and the app with it, depending on it (the app first: its
  requests in flight finish, then the database): files and database from
  the same moment, and prod is down for as long as the copy takes (the
  first time everything, later only what changed), its visitors waiting
  on `alwaysup` meanwhile ([Visitors during stops](stops.md)). On a server whose data is on XFS
  (devopsy-server's `cloud-init.yaml`), prod is down for a reflink clone
  only, seconds, and the copy is made from the clone; and a child on
  prod's server clones `storage` from prod's rather than copying it
  (`devopsy.data.copy.reflink`): the files share prod's blocks until
  either side writes, so a child of a large site takes next to no room.
  Never the database, whose pages written over shared blocks would
  fragment prod's files. The datadir carries prod's users and
  password hashes, and the image's root password is random, so children
  use prod's `DB_PASSWORD` (inherited). `DRUPAL_HASH_SALT` stays the
  child's own. A raw copy needs a MariaDB no older than the data
  (older cannot read newer): `compose.yaml` floats it on the 11.8 LTS
  series (`mariadb:${MARIADB_VERSION:-11.8}`, never `mariadb:11`, which
  a plain release would carry across series), every release pulls its
  fixes, and the same variable is the data's version
  (`devopsy.data.version`): devopsy refuses to run the data on a lower
  one, a rollback to an older series or a child below its parent before
  a sync. Each side runs its own image on the copy. Moving to the next
  series is a higher `MARIADB_VERSION` (`devopsy @prod --vars set
  MARIADB_VERSION <series>`, or its default in `compose.yaml`), which a
  release takes; the release snapshot taken before it is the way back.
- **A dump instead:** `DATA_DATABASE=custom` on prod (`devopsy @prod
  --vars set --show DATA_DATABASE custom`; children inherit it, so both
  sides agree) streams the database instead of copying its datadir:
  devopsy runs `.devopsy/capabilities/data/export database` on prod,
  where `mariadb-dump --single-transaction` reads it in one transaction,
  and, only once the whole dump arrived, `import database` on the child,
  which replaces its database with it (devopsy has it up first). The
  database keeps running on both sides (the `env` capability sets
  `DATA_DATABASE_STOP` to `never` then), and so does prod's app, its
  files rsynced as they are; the child's app is down while its database
  loads (`devopsy.data.stop=import`). Slower for big databases, no
  downtime on prod. The scripts are also the example of a
  `devopsy.data.copy=custom` service for your own services.
- **Exports and imports.** `--export` and `--import` move one service's
  data the way its copy label says, outside a sync. With
  `DATA_DATABASE=custom` the database's are the same `export` and
  `import` scripts, so `devopsy @prod --export database > dump.sql` is
  the way to get a dump of prod (the site running), and `devopsy
  --import database < dump.sql` loads it locally (with
  `DATA_DATABASE=custom` in `.devopsy/.env` too: each side reads its own
  label), or into a child (`devopsy @dev --import database < dump.sql`,
  which asks for the environment's full name, `<project>/dev`). An import
  stops the app for the load and starts it after; one that fails keeps it
  down, and nothing runs on the database until an import completes, or
  `devopsy @dev --import database --resolved` accepts it as it is (an
  error near the end of a dump, fixed by hand). With the default `volumes`,
  `--export database` is instead a tar of the datadir, the database and
  the app stopped around it, and `devopsy @prod --export app > files.tar`
  is always a tar of the site's files, the site running.
- **Sanitized and closed.** After the copy, before `deploy`, the data
  capability's `finish` runs on the child (`.devopsy/capabilities/data/finish`):
  `drush sql:sanitize` scrubs every user's email and password, so no
  production password opens a child and no mail from it reaches anyone
  (`devopsy @dev drush uli` logs you in); if it fails, the app is stopped
  and the sync fails, so prod's data is never served unsanitized, and the
  next sync's `finish` starts the app again to sanitize. A child
  with no `SITE_BASIC_AUTH`, inherited or its own, gets one generated into
  its `shared/.env`, user `preview`, the password printed that once. To open
  a child on purpose, set the variable empty and reload:
  `SITE_BASIC_AUTH= devopsy @dev --vars set SITE_BASIC_AUTH`, then
  `devopsy @dev --reload`; set but empty stays open through later syncs,
  unset would be closed again.
- **Inherited settings.** prod's `inherit:` names variables every child
  gets from prod's server into its own `shared/inherit.env`, on its first
  release, every sync and `devopsy @dev --inherit`: `DATA_DATABASE`
  (above), `DB_PASSWORD` (a raw datadir carries prod's password, a dump
  is imported into a database created with it: the same either way, so
  prod can switch between them under existing children), and
  `SITE_BASIC_AUTH`, so copies of a closed site stay closed, with prod's
  login. A child's own `shared/.env` wins over the inherited file:
  `devopsy @dev --vars set` a value to keep it from following prod,
  `unset` it to follow again. Never add `PROXY_DOMAINS`: a child would
  route prod's own hosts beside it.
- **The labels** in `compose.yaml` tell devopsy what to do with each
  service during a copy, read on each side from its own release (the two
  sides must agree, hence the inherited `DATA_DATABASE`; devopsy refuses
  to copy a datadir under a child's running database). `devopsy.data=true`
  says a service has data, on `app` and `database`; `devopsy.data.copy`
  says how a copy carries it: the default `volumes` (its entries of
  `mnt` as files) on `app`, `${DATA_DATABASE:-volumes}` on `database`.
  `devopsy.data.version` says each one's data's version: `any` on `app`
  (its files do not depend on its image: nothing checked),
  `${MARIADB_VERSION:-11.8}` on `database`, the variable its image's tag
  reads too. `devopsy.data.stop` says when it is stopped: `import` on
  `app` (while any data is imported), `${DATA_DATABASE_STOP:-own}` on
  `database` (while its own data moves), which the `env` capability
  turns to `never` with `DATA_DATABASE=custom`; and a service that
  `depends_on` a stopped one stops with it, the app with the database.
  Set it yourself to choose otherwise, but never copy a running datadir:
  with `volumes` on the database, keep it `own`.
