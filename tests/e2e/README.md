# End-to-end tests (local only)

`run.sh` tests the MCP server (`/mcp`, every tool) and the OAuth 2.1 server (`/oauth/*`,
`/.well-known/oauth-*`) through real HTTP against a **local** Pterodactyl v2 development panel,
with a fake Wings node and a throwaway game database. About 530 checks; about three minutes.

> [!CAUTION]
> Never run it against a real panel. It creates users, servers, schedules and OAuth connections,
> writes files through the fake node, changes server limits, creates and drops databases and database
> accounts on the test MariaDB, turns the panel's "2FA required" setting on and off and leaves its
> "users may add ports" setting on. `run.sh` refuses any `PANEL_URL` that is not `127.0.0.1` or `localhost`.

## What you need

| Thing | Notes |
|---|---|
| A Pterodactyl v2 dev panel | `2.0-develop`, with `composer install` and `npm run build` done. Its `.env` should use `MAIL_MAILER=log`, `QUEUE_CONNECTION=sync`, `RECAPTCHA_ENABLED=false` and an `APP_URL` equal to the URL you test |
| The panel served locally | e.g. `PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8181 -t public` from the panel directory (4 workers: `wait_for_console` holds one while it waits). On PHP 8.3 use Laravel's router script, or `/.well-known/...` is served as a static file |
| Its MariaDB, with a root account | the test creates database `mcp_game` and account `mcp_ro@%` (SELECT on `mcp_game` only), and account `mcp_dbhost@%`, which the panel uses as a database host (it may only create databases named `s<server id>_<name>` and their users). The panel must reach it at `127.0.0.1:MCP_SQL_PORT` |
| PHP 8.3+, Python 3 with `requests`, a JDK (`javac`, `jar`) | the JDK builds the test plugin jar |
| Port 8081 free | fake Wings |

The extension must be built (`npm run build`) before `run.sh` packs and installs it.

## Run it

```sh
PANEL_DIR=~/dev/panel PANEL_URL=http://127.0.0.1:8181 PANEL_DB=panel \
MYSQL_ROOT='mariadb -h127.0.0.1 -P3306 -uroot -psecret' tests/e2e/run.sh
```

| Variable | Default | |
|---|---|---|
| `PANEL_DIR` | (required) | the dev panel checkout |
| `PANEL_URL` | `http://127.0.0.1:8181` | must be local |
| `PANEL_DB` | `panel` | the panel's database |
| `MYSQL_ROOT` | `mariadb -h127.0.0.1 -P3306 -uroot` | command that runs the `mariadb` client as root |
| `MCP_SQL_PORT` | `3306` | port the panel uses to reach that MariaDB (for `query_database` and for the fixture database host) |
| `PANEL_MCP_TABLE_PREFIX` | `ext_panel_mcp_` | to test a custom prefix |
| `SKIP_INSTALL` | `0` | `1` = don't pack and install the extensions |
| `EXTRA_ADMIN_READ_TOOLS` | | tools other installed extensions add (admin, read scope), so the tool counts still match |
| `E2E_EXTRA_CHECKS` | | `:`-separated Python files run at the end inside `e2e.py` (for those extensions' own checks) |

`run.sh`:

1. Packs and installs this extension and `fixtures/extension` (id `pmcp-fixture`), which adds one
   tool through the `ProvidesTools` tag, plus a malformed tool and a fake `list_servers` that must
   both be ignored.
2. Loads `fixtures/sqldb.sql` and writes the `PANEL_MCP_*` settings into the panel `.env`.
3. Builds `fixtures/TestPlugin.jar` and runs `seed.php` (idempotent): users `mcpadmin` (root admin),
   `mcpstaff` (subuser) and `mcptotp` (root admin with TOTP), a node pointing at the fake Wings, an
   egg with two Docker images ("Java 21" and "Java 25", which every server's image is one of), servers
   `SMP`, `Hub-01` and `Secret`, and a schedule. `Hub-01` may keep 3 backups, have 3 databases and hold 3
   ports (the others have none of these) and has a finished and a failed backup; the node has three free
   ports; the database host `mcp-test-dbhost` is the test MariaDB itself (account `mcp_dbhost`). It also
   turns on the panel setting that lets users add ports. Databases an earlier run left on that host are
   dropped first. Rate limits cannot make a rerun flaky: the panel keeps them per server, and every run
   makes new servers.
4. Re-runs the extension's migration over the existing tables (must be a no-op).
5. Starts `fakewings.py` and runs `e2e.py`, which ends with `N passed, M failed`.

## What it covers

- Discovery documents, dynamic client registration, the consent flow (signed-out redirect to the
  sign-in page and back, also through the TOTP checkpoint), PKCE S256, exact redirect matching, the
  `resource` check, CSRF, consent replay, single-use codes, hashed token storage, refresh rotation
  and reuse detection, revocation, the Connected Apps page and the per-user connection cap.
- That machine endpoints send no cookies, need no CSRF token and carry their own rate limits, and
  that a signed-in browser cannot call `/mcp` (`route-middleware.php` asserts the middleware Laravel
  actually gathers for every route).
- The MCP protocol (initialize, batches, notifications, errors, the 401 challenge) and every tool:
  scopes, per-permission refusals for a subuser, suspended servers, the 2FA rule, one activity log
  entry per action written as the user, path traversal, the SQL statement filter, byte-exact file
  round trips, and tools added by other extensions.
- The backup, port, database and server-settings tools (`panel:manage` and the others they need): every
  tool's happy path against the real panel actions, with the real database host (the test logs in as
  the database accounts the panel made, and checks that a rotated password replaces the old one and a
  deleted database is gone); refusals for a connection without the scope, for a subuser without the panel
  permission (locked backups need `backup.delete`; passwords need `database.view_password`), for unknown
  backups, ports and databases, the primary port, duplicate database names, bad names and addresses,
  images the type does not offer, servers that are installing or restoring, and a node that refuses; the
  panel's per-server limits (2 new backups per 10 minutes, 3 restores per 15 minutes, 2 new ports or
  databases a minute), which the tools share with the panel's own routes; one activity log entry per action, written as the user; and that a
  connection made before `panel:manage` existed does not gain it, also when refreshed.

`fakewings.py` also answers the backup (create, restore, delete) and reinstall requests, as Wings does
with 202/204, and records them for `e2e.py`: `/_calls?server=<uuid>` lists what the panel asked for and
`/_fail?kind=backup|restore|delete|reinstall` makes the next such request fail. It also answers
`/_say?server=<uuid>&line=...` (append a console line), `/_rotate?server=<uuid>` (empty the log like a
restart) and `/_reset`. Wings' own reports back to the panel (a backup finished, an install ended) are
done by the test in the panel's database.

For the "this panel does not let users create databases" check, `e2e.py` rewrites
`PTERODACTYL_CLIENT_DATABASES_ENABLED` in the panel's `.env` for the length of one call and then puts the
file back as it was.
