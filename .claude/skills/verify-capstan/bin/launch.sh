#!/usr/bin/env bash
# Launch an isolated local Capstan with its own SQLite file and two hosts on one port.
# Prints the owner credentials file path when ready. Exits non-zero on any failure.
# Usage: CAPSTAN_VERIFY_WORKTREE=/abs/worktree [CAPSTAN_VERIFY_PORT=8412 ...] bin/launch.sh
source "$(dirname "$0")/_env.sh"

[[ -z "$(port_pids)" ]] || die "port ${PORT} is already held (pid $(port_pids | tr '\n' ' ')). Pick another CAPSTAN_VERIFY_PORT; never drive an instance you did not start."
[[ ! -f "${STATE}/server.pid" ]] || die "${STATE}/server.pid exists from an earlier run; run bin/cleanup.sh first"

ENV_FILE="${W}/.env"
[[ -f "${ENV_FILE}" ]] || die "${ENV_FILE} missing. Copy it from the main checkout (cp <main>/.env ${ENV_FILE}); never generate it."

# SO 36: a copied .env may point at production. Read back the DB settings before any write.
DB_CONN="$(grep -E '^DB_CONNECTION=' "${ENV_FILE}" | cut -d= -f2- || true)"
DB_HOST_V="$(grep -E '^DB_HOST=' "${ENV_FILE}" | cut -d= -f2- || true)"
echo "copied .env: DB_CONNECTION=${DB_CONN:-<unset>} DB_HOST=${DB_HOST_V:-<unset>}"

set_env() {
    python3 - "${ENV_FILE}" "$1" "$2" <<'PY'
import re, sys
path, key, value = sys.argv[1:4]
text = open(path).read()
# Drop every live KEY= line (comments untouched), then append exactly one.
text = re.sub(rf"^{re.escape(key)}=.*\n?", "", text, flags=re.M)
open(path, "w").write(text.rstrip("\n") + f"\n{key}={value}\n")
PY
}

# artisan serve forwards only a whitelist of env vars to its php -S workers, so exporting these
# does nothing. They must be written into the WORKTREE .env.
set_env DB_CONNECTION sqlite
set_env DB_DATABASE "${DB}"
set_env APP_ENV local
set_env APP_URL "${U}"
set_env CAPSTAN_ARTIFACT_RENDER_ORIGIN "${R}"
set_env CAPSTAN_FEATURE_ARTIFACTS true
grep -qE '^DB_CONNECTION=sqlite$' "${ENV_FILE}" && grep -qE "^DB_DATABASE=${DB}$" "${ENV_FILE}" \
    || die "worktree .env did not take the SQLite settings"

mkdir -p "${STATE}" "${EVIDENCE}"
chmod 700 "${STATE}"
echo "${RUN_ID}" > "${STATE}/run-id"

composer -d "${W}" install --no-interaction --quiet
# Without the Vite manifest every authenticated page 500s ("Vite manifest not found").
if [[ ! -f "${W}/public/build/manifest.json" ]]; then
    npm --prefix "${W}" ci --silent
    npm --prefix "${W}" run build >/dev/null
fi
[[ -f "${W}/public/build/manifest.json" ]] || die "Vite build produced no manifest"

FRESH=0
if [[ ! -f "${DB}" ]]; then
    touch "${DB}"
    FRESH=1
fi
art config:clear >/dev/null
[[ "${FRESH}" = 1 ]] && art key:generate --force >/dev/null
art migrate --force >/dev/null
# Without a signing root, MCP send_postmaster_message returns an opaque {"outcome":"outcome_unknown"}.
art bfc:signing-root:ensure --local --no-interaction >/dev/null

# --no-reload keeps one long-lived parent; PHP_CLI_SERVER_WORKERS forks children that cleanup must reap.
nohup php "${W}/artisan" serve --host=127.0.0.1 --port="${PORT}" --no-reload \
    > "${STATE}/server.log" 2>&1 &
echo $! > "${STATE}/server.pid"

for _ in $(seq 1 40); do
    code="$(curl -s -o /dev/null -w '%{http_code}' "${U}/up" || true)"
    [[ "${code}" = 200 ]] && break
    sleep 0.5
done
[[ "${code}" = 200 ]] || die "GET ${U}/up returned ${code}; see ${STATE}/server.log"

# The FIRST account is the Owner; team 1 is seeded by migrations. --local keeps it off Cloud (SO 20).
CREDS="${STATE}/owner.env"
if [[ ! -f "${CREDS}" ]]; then
    users="$(sqlite3 "${DB}" 'select count(*) from users;')"
    [[ "${users}" = 0 ]] || die "${DB} already has ${users} users but no ${CREDS}; run bin/cleanup.sh --purge-db"
    pw="$(python3 -c 'import secrets; print(secrets.token_urlsafe(18))')"
    art create-admin --local --execute --no-interaction \
        --email="owner@verify.test" --name="Verify Owner" --password="${pw}" >/dev/null
    umask 077
    printf 'OWNER_EMAIL=owner@verify.test\nOWNER_PASSWORD=%s\nOWNER_ID=%s\n' "${pw}" \
        "$(sqlite3 "${DB}" "select id from users where email='owner@verify.test';")" > "${CREDS}"
fi

echo "READY app=${U} render=${R} db=${DB}"
echo "state=${STATE} (owner creds: ${STATE}/owner.env, mode 0600)"
echo "evidence=${EVIDENCE}"
