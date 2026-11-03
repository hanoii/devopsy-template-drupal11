# Child environments

`dev` and `pr-*` (`.devopsy/config.yaml`) are children of `prod`: copies
of its site, each with its own containers, URL and `shared/.env`.

```sh
devopsy @dev --release           # the first release syncs prod, then runs deploy
devopsy @pr-12 --release         # one per pull request, the same way
devopsy @dev --sync             # copy prod again (asks for the name; --yes skips)
```

## The first release syncs

After a child's first release goes live and before `deploy`, devopsy
copies prod's data into it, so `deploy` (database updates, config
import, caches) runs on prod's data and no empty site is served. Later
releases keep the child's data; `--sync` copies prod again and runs
`deploy` afterwards.

A copy that did not complete (cut halfway, a lost connection) leaves the
child's app and database down. Until a sync completes (`--sync` or
`--release-sync`), devopsy refuses rollbacks and releases that do not
sync.

## What is copied

A sync copies `storage`, the `app` service's mount (public and private
files), and the database's datadir. `logs`, the access log, is left out
(`devopsy.data.exclude`), so each environment keeps a separate one.

devopsy rsyncs them from prod's server with the database stopped on both
sides (`own`), and the app with it ([The labels](#the-labels)). The app
stops first, so its requests in flight finish before the database stops.
Files and database come from the same moment. Prod is down for as long
as the copy takes. The first copy is everything; later ones copy only
what changed. Prod's visitors wait on `alwaysup` during the copy
([Visitors during stops](stops.md)).

On a server whose data is on XFS (devopsy-server's `cloud-init.yaml`),
prod is down only for a reflink clone, which takes seconds, and the copy
is made from the clone. A child on prod's server also clones `storage`
from prod's instead of copying it (`devopsy.data.copy.reflink`). The
files share prod's blocks until either side writes, so a child of a large
site takes next to no room. The database is never cloned this way, since
its pages written over shared blocks would fragment prod's files.

The datadir carries prod's users and password hashes, and the image's
root password is random, so children use prod's `DB_PASSWORD`, which they
inherit. `DRUPAL_HASH_SALT` stays the child's.

## MariaDB's version

A raw copy needs a MariaDB no older than the data. `compose.yaml` floats
MariaDB on the 11.8 LTS series (`mariadb:${MARIADB_VERSION:-11.8}`;
`mariadb:11` would let a plain release cross series), and every release
pulls its fixes. The same variable is the data's version
(`devopsy.data.version`), so devopsy refuses to run the data on a lower
one. It refuses a rollback to an older series, and a child below its
parent before a sync. Each side runs its image on the copy.

To move to the next series, set a higher `MARIADB_VERSION` (`devopsy
@prod --vars set MARIADB_VERSION <series>`, or its default in
`compose.yaml`), which a release takes. The release snapshot taken before
that release is the way back.

## A dump instead

With `DATA_DATABASE=custom` on prod (`devopsy @prod --vars set --show
DATA_DATABASE custom`), a sync streams the database instead of copying
its datadir, and children inherit the variable ([Inherited
settings](#inherited-settings)). devopsy runs
`.devopsy/capabilities/data/export database` on prod, where
`mariadb-dump --single-transaction` reads the database in one
transaction. After the whole dump arrives, devopsy brings up the child's
database and runs `import database`, which replaces it with the dump.

The database keeps running on both sides (with `DATA_DATABASE=custom`,
the `env` capability sets `DATA_DATABASE_STOP` to `never`), and so does
prod's app, whose
files are rsynced as they are. The child's app is down while its
database loads (`devopsy.data.stop=import`). This is slower for big
databases, with no downtime on prod. The scripts are also an example of
a `devopsy.data.copy=custom` service for your own services.

## Exports and imports

`--export` and `--import` move one service's data, the way its copy
label says, outside a sync. With `DATA_DATABASE=custom`, the database's
are the same `export` and `import` scripts:

- `devopsy @prod --export database > dump.sql` gets a dump of prod while
  the site runs;
- `devopsy --import database < dump.sql` loads it locally, with
  `DATA_DATABASE=custom` in `.devopsy/.env` too ([The
  labels](#the-labels));
- `devopsy @dev --import database < dump.sql` loads it into a child, and
  asks for the environment's full name, `<project>/dev`.

An import stops the app for the load and starts it again after. If it
fails, the app stays down, and nothing runs on the database until an
import completes or `devopsy @dev --import database --resolved` accepts
the data as it is (for an error near the end of a dump, fixed by hand).

With the default `volumes`, `--export database` is instead a tar of the
datadir, with the database and the app stopped around it. `devopsy @prod
--export app > files.tar` is always a tar of the site's files, taken
while the site runs.

## Sanitized and closed

After the copy and before `deploy`, the data capability's `finish` runs
on the child (`.devopsy/capabilities/data/finish`). `drush sql:sanitize`
scrubs every user's email and password, so no production password opens
a child and no mail from it reaches anyone; `devopsy @dev drush uli` logs
you in. If sanitizing fails, the app is stopped and the sync fails, so
prod's data is never served unsanitized, and the next sync's `finish`
starts the app again to sanitize.

A child with no `SITE_BASIC_AUTH`, inherited or its own, gets one
generated into its `shared/.env`, user `preview`, and the password is
printed once. To open a child on purpose, set the variable empty and
reload: `SITE_BASIC_AUTH= devopsy @dev --vars set SITE_BASIC_AUTH`, then
`devopsy @dev --reload`. Set but empty, it stays open through later
syncs; unset, it would be closed again.

## Inherited settings

prod's `inherit:` names the variables every child gets from prod's server
into its own `shared/inherit.env`, on its first release, every sync and
`devopsy @dev --inherit`:

- `DATA_DATABASE` ([A dump instead](#a-dump-instead));
- `DB_PASSWORD`: a raw datadir carries prod's password, and a dump is
  imported into a database created with it. It is the same either way,
  so prod can switch between the two under existing children;
- `SITE_BASIC_AUTH`, so copies of a closed site stay closed, with prod's
  login.

A child's own `shared/.env` wins over the inherited file. `devopsy @dev
--vars set` a value to keep it from following prod, and `unset` it to
follow again. Never add `PROXY_DOMAINS`, or a child would route prod's
hosts beside it.

## The labels

The labels in `compose.yaml` tell devopsy what to do with each service
during a copy. Each side reads them from its own release, so both sides
must agree; that is why `DATA_DATABASE` is inherited. devopsy refuses to
copy a datadir under a child's running database.

- `devopsy.data=true` says a service has data, on `app` and `database`.
- `devopsy.data.copy` says how a copy carries it: the default `volumes`
  (its entries of `mnt`, as files) on `app`, and
  `${DATA_DATABASE:-volumes}` on `database`.
- `devopsy.data.version` gives each one's data version: `any` on `app`,
  whose files do not depend on its image, so nothing is checked; and
  `${MARIADB_VERSION:-11.8}` on `database`, the variable its image's tag
  reads too.
- `devopsy.data.stop` says when it is stopped: `import` on `app` (while
  any data is imported), and `${DATA_DATABASE_STOP:-own}` on `database`
  (while its own data moves), which the `env` capability turns to `never`
  with `DATA_DATABASE=custom`. A service that `depends_on` a stopped one
  stops with it, the app with the database.

Set `DATA_DATABASE_STOP` yourself to choose otherwise, but with `volumes`
on the database keep it `own`, so a running datadir is never copied.
