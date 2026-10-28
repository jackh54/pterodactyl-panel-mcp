#!/usr/bin/env bash
# Runs the MCP + OAuth end-to-end test against a LOCAL Pterodactyl v2 dev instance. See README.md.
#   PANEL_DIR=/path/to/panel PANEL_URL=http://127.0.0.1:8181 PANEL_DB=panel tests/e2e/run.sh
# Never point it at production: it creates users, servers and connections and changes settings.
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
repo=$(cd "$here/../.." && pwd)
: "${PANEL_DIR:?set PANEL_DIR to the dev panel checkout}"
export PANEL_DIR
export PANEL_URL=${PANEL_URL:-http://127.0.0.1:8181}
export PANEL_DB=${PANEL_DB:-panel}
export MYSQL_ROOT=${MYSQL_ROOT:-mariadb -h127.0.0.1 -P3306 -uroot}
export MYSQL_CMD="$MYSQL_ROOT $PANEL_DB"
export PHP=${PHP:-php}
export MCP_SQL_PORT=${MCP_SQL_PORT:-3306}
export PANEL_MCP_TABLE_PREFIX=${PANEL_MCP_TABLE_PREFIX:-ext_panel_mcp_}
case "$PANEL_URL" in http://127.0.0.1:*|http://localhost:*) ;; *) echo "refusing: PANEL_URL must be a local instance" >&2; exit 2;; esac
art() { (cd "$PANEL_DIR" && $PHP -d error_reporting=0 artisan "$@"); }

# 1. The extension under test and the fixture extension (adds one tool through ProvidesTools).
if [ "${SKIP_INSTALL:-0}" != 1 ]; then
    pack=$(mktemp -d)
    replace=; art p:extension:install --help 2>/dev/null | grep -q -- '--replace' && replace=--replace
    art p:extension:pack "$repo" --output="$pack/panel-mcp.pteroext" --force >/dev/null
    art p:extension:install "$pack/panel-mcp.pteroext" --enable --no-interaction ${replace:+"$replace"} >/dev/null
    art p:extension:pack "$here/fixtures/extension" --output="$pack/pmcp-fixture.pteroext" --force >/dev/null
    art p:extension:install "$pack/pmcp-fixture.pteroext" --enable --no-interaction ${replace:+"$replace"} >/dev/null
    rm -rf "$pack"
fi

# 2. Test database for query_database, and the instance settings the MCP tools read.
$MYSQL_ROOT < "$here/fixtures/sqldb.sql"
env_set() { grep -q "^$1=" "$PANEL_DIR/.env" && sed -i.bak "s#^$1=.*#$1=$2#" "$PANEL_DIR/.env" || echo "$1=$2" >> "$PANEL_DIR/.env"; rm -f "$PANEL_DIR/.env.bak"; }
env_set PANEL_MCP_SQL_HOST 127.0.0.1
env_set PANEL_MCP_SQL_PORT "$MCP_SQL_PORT"
env_set PANEL_MCP_SQL_USERNAME mcp_ro
env_set PANEL_MCP_SQL_PASSWORD mcp-ro-test-only
env_set PANEL_MCP_SQL_DATABASES mcp_game
env_set PANEL_MCP_OPS_GUIDE_PATH "$here/fixtures/ops-guide.md"
env_set PANEL_MCP_TABLE_PREFIX "$PANEL_MCP_TABLE_PREFIX"
art config:clear >/dev/null

# 3. Fixtures: test jar, users/node/servers.
"$here/fixtures/build-jar.sh" >/dev/null
art tinker --execute="require '$here/seed.php';" | grep '^SEED_JSON=' | sed 's/^SEED_JSON=//' > "$here/.state.json"
test -s "$here/.state.json" || { echo "seeding failed" >&2; exit 1; }

# 4. The migration must be a no-op when its tables already exist.
migration=2026_10_18_000000_create_panel_mcp_oauth_tables
$MYSQL_ROOT "$PANEL_DB" -e "DELETE FROM migrations WHERE migration = '$migration'"
art migrate --force 2>&1 | grep -q "$migration" || { echo "migration did not run again" >&2; exit 1; }
echo "migration re-run over existing tables: ok"

# 5. Fake Wings on 8081.
pkill -f "$here/fakewings.py" 2>/dev/null || true
python3 "$here/fakewings.py" $(python3 -c "import json; print(' '.join(s['uuid'] for s in json.load(open('$here/.state.json'))['servers'].values()))") &
wings=$!
trap 'kill $wings 2>/dev/null || true' EXIT
sleep 1

# 6. The test itself.
python3 "$here/e2e.py"
