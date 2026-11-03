# AGENTS.md

devopsy-template-drupal11 is Drupal 11 on FrankenPHP with MariaDB, run with [devopsy-cli](https://github.com/hanoii/devopsy-cli): built on the server (build mode), read-only at runtime, children synced from prod. `README.md` is how to use it, kept in step with every change; how a file works is in its comments (`compose.yaml` explains every label).

## Workflow

### Git

- Conventional commits; the subject about 50 characters, 72 at most, the reason in the body: release notes are commit subjects, and tools cut longer ones.
- A commit made with an AI agent ends with one line naming it, `Assisted-by: <agent>:<model> [<effort>]` (for example `Assisted-by: claude-code:claude-opus-5-5 high`), never `Co-Authored-By`: GitHub shows a co-author as a person.
- Any word with an `@` in a commit message or a doc goes in backticks (for example `@prod`, `deploy@host`): GitHub links a bare `@name` to that user.
- Push and tag only when asked: a push publishes, a tag releases.
- A change of one commit (for example a quick fix) goes straight onto `main`, in the main checkout.
- Any larger task gets one branch, `<type>/<slug>`, its type what it brings to `main` whatever its commits' types (for example `feat/snapshots`, `docs/readme`).
- All of a task's work goes on its branch, reviews' failing tests and their fixes included; never a branch off a task's branch.
- A finished branch is rebased onto `main`, merged with `--no-ff` and deleted.
- A partial merge, only when asked, is rebased and merged the same way and keeps the branch and its worktree, rebased at once onto the merge for the rest of the task.
- A branch is worked in a worktree of its own, `.worktrees/<slug>` (`git worktree add .worktrees/<slug> -b <type>/<slug>`), removed once merged: inside the repository, a worktree is within the directories the agent may use.
- The main checkout stays on `main`.
- A worktree of a devopsy project run beside its main checkout gets its own `project:` (for example `whoami-<slug>`) in `.devopsy/config.local.yaml`: otherwise both are one local compose project, sharing containers and volumes.
- `.worktrees/` is listed in `.gitignore` and in the ignore file of any Docker build context that holds it: a `docker build` would otherwise send every worktree in its context.
- A session in a worktree starts by rebasing it onto `main`: the work adapts to what changed while it is still cheap.

### Tests and checks

- Every change carries its test, a fix's failing without it, a feature's checking what it promises, and a behavior a change touches with no test gets one then: a test is what keeps a fix fixed.
- A fix's test is run against the code before the fix, and the summary says it failed there.
- Unit tests first; an end-to-end test only for what needs a real system (for example Docker, sshd, the network, a terminal or timing).
- One test per behavior: extend the test that covers it before adding another, and never test one thing at both levels.
- The suites stay fast, locally and in CI, since every change waits on them: a new test runs in parallel with the others where it can, polls rather than sleeps, and reuses the setups that exist.
- A test made faster checks the same things, never fewer: a speed-up that drops a case or an assertion is a regression.
- A check or a refusal is only for a mistake any release can make, a setting a tool would otherwise take without a word; none is added for one upgrade only (for example a refusal of an old name after a rename): an upgrade's own steps cover those, and old names left over are ignored.
- Until 1.0.0, no tests of specific older versions and no handling of mixed versions (for example an operator and a server on different devopsy): a new behavior an older server lacks refuses before anything changes, and that is all.

### Refactors

- Before writing code for one case, look for the same pattern elsewhere (for example a message, a check, an interrupt) and propose one helper for all, each site adding only what is its own: variants drift apart, and a fix then lands in one of them only.
- A design that makes a change awkward is proposed for change, never worked around: workarounds pile up where the design is wrong.
- When a change makes the code simpler (less code, fewer external calls), the tests' fakes are extended to match, never the heavier code kept or a test weakened to pass.
- A refactor reaching beyond the task is agreed before it is made.

### Reviews

- A review round is two independent reviewers, one on Opus and one on Fable, on the task's branch in its worktree.
- A review brief lists what earlier rounds found and states Tests and checks' two limits, mixed versions and lean checks: findings of those kinds are not fixed.
- A review brief also asks for the same pattern written twice and for workarounds of the design (Refactors).
- A reviewer writes what it tests into the repository as `test:` commits on the task's branch, a failing test that proves a bug kept with a comment naming it, never product code: a harness outside the repository is lost with its round.
- While building or after a review, a bug, an edge case, a name or a verified finding is decided and fixed without asking, a fix with its failing test first, and said in the summary; only what changes the agreed design or user-facing behavior is asked.
- At most two rounds per branch: after the second round's fixes, the tests, the end-to-end suite and a hand test cover the rest.

### Servers and names

- Never touch a real server without asking, even read-only; test on throwaway machines and delete them afterwards: a read can still load a server, and only its owner knows what runs there.
- Names in examples, tests, docs and commit messages are generic (for example `app`, `shop`, `vm1`, `example.com`), never a real project, client, server, domain or person, nor anything from an agent's memory or another project: a public history keeps whatever a commit once said.

### Docs

- The root holds what every task in the repository needs; a part only some tasks need is an area, `internals/<area>.md`, with one line in the root's index saying what it covers and when to read it: the root loads with every session, the areas only when their line says so.
- A root is kept under about 250 lines, a guide, never a cap: past it, what only some tasks need moves to an area, and no needed rule is cut or squeezed to fit.
- A rule's history goes in `internals/<area>.history.md`, or `internals/<section>.history.md` for a rule in a section of the root, starting with a line saying it is history, not rules, and its area's file wins where they differ.
- A history file is read only before changing or questioning one of its rules, when a review finding looks like it contradicts a decision, or when asked why.
- How a script or a function works, step by step, goes in its comments, never in an AGENTS.md or area: a comment changes with its code.
- A commit's reason goes in its body; only a decision a later session must respect becomes a rule, in its area or the root, its story in the history, in the same commit: anywhere else, the next session rediscovers it or contradicts it.
- Before adding a rule, find any line it repeats or contradicts, in its file and the root, and change that line instead: two lines on one thing drift apart.
- A line written or changed in an AGENTS.md or area is checked before its commit (for example `Push and tag only when asked: a push publishes, a tag releases.`):
  - one rule, imperative, no bold, no prose around it;
  - a why only when something real breaks without it, saying what breaks, never an invented one, which an agent takes for a reason;
  - examples written "(for example ...)" before the colon, and one colon at most outside code;
  - one reading only, else reworded, never explained further;
  - no story (review rounds, what broke, measurements), which goes in the history.
- A README says at a glance what the repository is and how to start; anything longer is a doc of its own in `docs/` that it links to: a README that piles up docs gets skimmed past.
- READMEs and docs are written plainly, with no selling words, no bold lead-in on every bullet, no closing summary, no emoji and no "Note" asides: they read as generated and bury the facts.
- Each fact has one home, and other docs link to it rather than repeat it: two copies drift apart.

## Layout

- Everything devopsy reads is in `.devopsy/`: `compose.yaml` (`app`, `database`, `alwaysup`, and the `goaccess` and `jq` tools), `Dockerfile`, `docker/` (the entrypoint, the drush wrapper, Caddy's and PHP's settings, alwaysup's Caddyfile and page), `commands/` and `capabilities/` (`env`, `status`, `data`).
- Drupal's settings come from the environment (`web/sites/default/settings.php`).

## Rules

- No container has privilege: each runs as its own numeric UID from its first process (`user:` in `compose.yaml`), with `cap_drop: [ALL]` and `no-new-privileges`, read-only where the image allows, since the site must hold up with the app fully compromised (its code finds nothing to escalate with, and a `--user 0` shell is a root without capabilities).
- A capability comes back only with a stated reason (alwaysup's `NET_BIND_SERVICE`, below).
- Images built here carry no setuid or setgid binaries and no file capabilities.
- The app's UID is `APP_UID` (10001 by default), never 0 and never 1000 (the deploy user, in the docker group), and every place that names it reads the variable: the app's `user:` and build argument, alwaysup, goaccess (the access log is mode 600) and jq.
- Ownership is devopsy's, through `devopsy.mnt.owner=user` on each service that writes an entry of `mnt` (`storage` and `logs` the app's, `database` 999's), so the data's owner follows the service's `user:` and the two never drift apart.
- Nothing in an image chowns or needs root to start.
- Entries written from the host (`maintenance`, by `deploy`) have no owner label and stay the deploy user's.
- Everything written at runtime goes in `mnt` or `/tmp`: the release tree is read-only and goes with the next release.
- Compiled Twig goes to `/tmp/php` (`php_storage` in settings.php), never `public://php`, since it is PHP Drupal includes and would outlive releases there; an existing site's `storage/public/php` can be removed by hand once released.
- No PHP runs from `sites/`, however a request writes the path (`docker/Caddyfile`): `sites/default/files` is the storage mount, the one place under the web root the app can write, so a file the app was tricked into writing must never become code.
- The `sites/` check sits between php_server's rewrite and its php handler, where the path is the script PHP would run, which is why php_server is written out (FrankenPHP's own expansion, from `frankenphp adapt`).
- The `sites/` match ignores case and is exactly what reaches the php handler, a script or one with path info (`\.php(/|$)`), never a regex on the request path: PHP splits at `.php` ignoring case and passes the rest as path info, and a directory's `index.php` runs from a request with no `.php` in it.
- The match is anchored on what reaches the handler, so files whose names only contain `.php` (for example `my.pharmacy.jpg`, `report.php.pdf`) and their image style derivatives are served.
- When FrankenPHP's php_server changes, compare `frankenphp adapt` of a bare `php_server` with the route, by hand, as part of updating the base's tag or reviewing a FrankenPHP release: a build that diffs the two would block a release carrying PHP's security fix until the Caddyfile caught up.
- Only Drupal's entry scripts run (`docker/Caddyfile`, `@other_php`, beside the `sites/` rule): `index.php` and `update.php` at the web root, and the scripts directly in `core/` without path info (`install.php`, `rebuild.php`, `authorize.php`); code in modules, themes, libraries and core's tests is never meant to run alone.
- The entry-script rule is Drupal's own, from its `web/.htaccess` (`RewriteRule "^(.+/.*|autoload)\.php($|/)" - [F]`, except `RewriteCond %{REQUEST_URI} !/core/[^/]*\.php$`), matched on the script php_server would run, with its path info, ignoring case as PHP's split does; the exception as Drupal writes it, case-sensitive.
- `autoload.php` and `autoload_runtime.php` at the root are refused too (the latter a scaffold file newer than the rule, which run alone only fails).
- The test front controllers `.htaccess` lets through (`core/modules/system/tests/`) stay refused: they are for core's own test runs.
- No symlinks in public files: the entrypoint removes every one under `storage/public` at each start, saying which (`docker/entrypoint`), and fails when it cannot, since Caddy's file_server follows links and has no setting not to, so a link the app was tricked into writing would serve its target anonymously.
- Accepted: a link is served between its creation and the next start.
- `public/` and `private/` themselves, when links, are removed, said, and made again as directories before anything tests them, since `-e`, `-d` and `-w` follow links.
- MariaDB floats on its LTS series (`mariadb:11.8`, never `mariadb:11`, which a plain release would carry across series), so every release pulls its fixes, while devopsy keeps the database going forward only and holds a child on the parent's exact image after a raw datadir sync (an older MariaDB cannot read a newer one's data).
- `MARIADB_AUTO_UPGRADE` updates the system tables when the image moves up.
- The next LTS is an edit in `compose.yaml`, then one `--release --accept-image database` per environment.
- FrankenPHP's base floats (`dunglas/frankenphp:1-php8.4-trixie`), and build mode builds with `--pull`, so PHP's fixes arrive with releases; `--status` prints the PHP the app runs (`capabilities/status/show`).
- A change existing environments must act on is marked `!` in its commit (for example `feat!:`).

## Images

- No `USER` in the Dockerfile, `user:` in compose, so the image still runs as root for whoever wants that (a debugging `docker run`), and the entrypoint does not refuse root.
- The `app` user exists only to name the UID and give it `/tmp` as home (Docker sets `HOME` from `/etc/passwd` for a numeric `user:`).
- FrankenPHP's file capability is removed (`setcap -r`), and the app listens on 8080: execve refuses a binary whose file capabilities the bounding set lacks, so with `cap_drop: [ALL]` the upstream binary's `cap_net_bind_service=ep` keeps it from starting.
- alwaysup runs the upstream Caddy image unchanged, whose binary carries the same capability, hence its `cap_add: [NET_BIND_SERVICE]`.
- The entrypoint only hashes the basic-auth password, removes symlinks from public files and in place of `public/` and `private/`, and creates `public/` and `private/`.
- The entrypoint fails with a message saying which, when `storage` is not a mount (missing on the read-only root, or in the container's own filesystem, gone at its next recreate), or `public/` or `private/` is not a directory, not creatable, or there and not writable by the container's user.
- `drush` is a wrapper for `--root` only.
- Never drush as root (`--user 0`, `--shell app --user root`): what it writes (compiled Twig in `/tmp/php`, files in storage) is root's, which the app cannot replace, and a root without capabilities cannot write into the app's own directories at all; run it as the app, `exec`'s default user.
- `accesslog` prints no control characters: the app writes the log and a request's URL can hold any byte, so jq strips them (C0, DEL and C1) from every field before they reach the operator's terminal.
- MariaDB runs as 999 from the start: the official image's entrypoint chowns its datadir only when started as root, and the owner label makes the datadir 999's.
- MariaDB is read-only too: it writes only the datadir, `/run/mysqld` (socket and pid file) and `/tmp`, both tmpfs, the first given to 999 with tmpfs options (`uid=999,gid=999`), since a tmpfs is root's otherwise and Compose's long syntax has no owner.
- MariaDB's image keeps its setuid binaries (not ours to rebuild): without capabilities and with `no-new-privileges` they give nothing.
- MariaDB's warning that `io_uring_queue_init()` failed with EPERM is Docker's seccomp profile, not the hardening; it falls back.
- `disable_functions` for web requests is documented, commented out, never enforced (`docker/Caddyfile`): it blocks the shell behind most exploits and breaks modules that shell out in a request, the site's choice.
- `disable_functions` goes in FrankenPHP's `php_ini`, which applies to the web server only, never in `php.ini`, which also reaches drush, which needs `proc_open`.
- `php.ini` narrows request parsing: `session.upload_progress.enabled = 0` (Drupal core does not use it), `max_input_nesting_level = 32` (PHP's default is 64), and uploads under 64M, `PHP_UPLOAD_MAX_SIZE` (32M), per target.
- PHP 8.3 and later read `${VAR:-default}` in ini files.
- macOS fakes ownership on bind mounts (OrbStack, Docker Desktop), so the owner label, the entrypoint's refusal and an `mnt` import's owners are checked on Linux (an OrbStack machine), and an ownership check runs on a Docker volume.

## Checks

```sh
cd .devopsy && docker run --rm -v "$PWD:/mnt" -w /mnt koalaman/shellcheck:stable -s sh docker/entrypoint docker/drush-wrapper commands/* capabilities/*/*
```

The hardening, on a running environment (`devopsy @<target> exec -T` for one on a server); a chown to oneself succeeds without capabilities, so the `--user 0` check chowns to another user:

```sh
devopsy exec -T app grep -E 'Cap(Eff|Bnd)|NoNewPrivs' /proc/1/status       # zeros, 1
devopsy exec -T database grep -E 'Cap(Eff|Bnd)|NoNewPrivs' /proc/1/status  # zeros, 1
devopsy exec -T --user 0 app sh -c 'chown 10001 /tmp'                      # fails
devopsy exec -T app find / -xdev -perm /6000 -type f                       # nothing
```

PHP the app writes into its files never runs (every line 403; from inside the container, past basic auth):

```sh
devopsy exec -T app sh -c 'cd storage/public && mkdir -p t && for f in t.php t.PHP t/index.php; do echo "<?php echo 1;" > $f; done'
for p in t.php t.php/x t.php%2Fx t.PHP t.PHP/x t/ ../files/t.php ./t.php t/../t.php /t.php; do
  devopsy exec -T app curl -s --path-as-is -o /dev/null -w "%{http_code} $p\n" "http://localhost:8080/sites/default/files/$p"
done
for u in /sites/default//files/t.php /index.php/../sites/default/files/t.php; do
  devopsy exec -T app curl -s --path-as-is -o /dev/null -w "%{http_code} $u\n" "http://localhost:8080$u"
done
devopsy exec -T app sh -c 'cd storage/public && rm -rf t.php t.PHP t'
```

Only Drupal's entry scripts run (the first five reach PHP, a 200, a 302 or Drupal's own 403 with a page; the rest are an empty 403):

```sh
for u in / /index.php/user/login /core/install.php /core/rebuild.php /core/authorize.php \
  /core/install.php/x /autoload.php /autoload_runtime.php /core/lib/Drupal.php \
  /core/modules/contact/tests/drupal-7.contact.database.php /core/modules/system/tests/http.php; do
  devopsy exec -T app curl -s -o /dev/null -w "%{http_code} %{size_download} $u\n" "http://localhost:8080$u"
done
```

A symlink the app plants in public files is gone after a restart, and the app's log names it:

```sh
devopsy exec -T app ln -s /app/storage/private storage/public/p
devopsy restart app && devopsy logs app | grep 'removing the symlink'
```

- Files whose names only contain `.php` are served (200): `my.pharmacy.jpg` and `report.php.pdf` in `storage/public`, and an image style derivative of the first, requested twice (the second from the file Drupal wrote).
- Then the site: the installer, an image upload with an image style, `drush uli`, `drush cr`, `config:export --destination=/tmp/sync`, and `--export`/`--import database` with `DATA_DATABASE=custom` and with the default `volumes`.

## Histories

The root's sections have `internals/rules.history.md`, `internals/images.history.md` and `internals/checks.history.md`, read as Workflow's Docs says.
