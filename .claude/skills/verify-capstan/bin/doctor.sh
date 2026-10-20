#!/usr/bin/env bash
# Read-only health check: is THIS run's instance worth driving? Exits non-zero on the first failure.
# Writes nothing except one anonymous session row (the database-identity probe) and doctor.txt evidence.
source "$(dirname "$0")/_env.sh"

out="${EVIDENCE}/doctor.txt"
mkdir -p "${EVIDENCE}"
: > "${out}"
ok() { echo "ok   $*" | tee -a "${out}"; }
bad() { echo "FAIL $*" | tee -a "${out}"; exit 1; }

[[ -f "${STATE}/server.pid" ]] || bad "no ${STATE}/server.pid: this run started no server (run bin/launch.sh)"
root="$(cat "${STATE}/server.pid")"
kill -0 "${root}" 2>/dev/null || bad "server pid ${root} is not running"
ok "server pid ${root} alive"

tree=" $(descendants "${root}" | tr '\n' ' ') "
holders="$(port_pids)"
[[ -n "${holders}" ]] || bad "nothing listens on ${PORT}"
for p in ${holders}; do
    [[ "${tree}" == *" ${p} "* ]] || bad "port ${PORT} is held by pid ${p}, which this run did not start"
done
ok "port ${PORT} owned by this run's tree ($(echo ${holders}))"

code="$(curl -s -o /dev/null -w '%{http_code}' "${U}/up")"
[[ "${code}" = 200 ]] || bad "GET ${U}/up -> ${code}"
ok "GET ${U}/up -> 200"

art route:list --path=bfc/login >/dev/null 2>&1 || bad "route:list does not resolve"
ok "route:list resolves"

# artisan serve can silently come up on the checkout's own .env. Prove identity by behaviour:
# an anonymous page load must add a session row to THIS run's SQLite file.
before="$(sqlite3 "${DB}" 'select count(*) from sessions;')"
curl -s -o /dev/null "${U}/bfc/login"
after="$(sqlite3 "${DB}" 'select count(*) from sessions;')"
[[ "${after}" -gt "${before}" ]] || bad "anonymous GET did not add a session to ${DB} (${before} -> ${after}): the server is on another database"
ok "db identity: sessions ${before} -> ${after} in ${DB}"

# Artifacts/Pages/Brand need the Artifacts flag and a render origin that differs from the app origin.
url_line="$(grep -E '^APP_URL=' "${W}/.env" | cut -d= -f2-)"
render_line="$(grep -E '^CAPSTAN_ARTIFACT_RENDER_ORIGIN=' "${W}/.env" | cut -d= -f2-)"
[[ "${url_line}" = "${U}" && "${render_line}" = "${R}" ]] || bad "worktree .env has APP_URL=${url_line} RENDER=${render_line}, expected ${U} / ${R}"
grep -qE '^CAPSTAN_FEATURE_ARTIFACTS=true$' "${W}/.env" || bad "CAPSTAN_FEATURE_ARTIFACTS is not true in the worktree .env"
ok "two origins + Artifacts flag set in worktree .env"

[[ -f "${W}/public/build/manifest.json" ]] || bad "no Vite manifest: authenticated pages will 500"
ok "Vite manifest present"

# Credential NAMES only, never values. ps eww cannot read another process's env on macOS, so read
# the worktree .env (what the server loads) and this shell's exports.
names_env="$(grep -E '^[A-Z0-9_]*(KEY|SECRET|TOKEN|PASSWORD|DSN)[A-Z0-9_]*=.+' "${W}/.env" | cut -d= -f1 | sort | tr '\n' ' ')"
names_sh="$(env | grep -E '^[A-Z0-9_]*(KEY|SECRET|TOKEN|PASSWORD|DSN)[A-Z0-9_]*=.+' | cut -d= -f1 | sort | tr '\n' ' ')"
ok "credential names set in worktree .env: ${names_env:-none}"
ok "credential names exported in this shell: ${names_sh:-none}"

echo "DOCTOR PASS -> ${out}"
