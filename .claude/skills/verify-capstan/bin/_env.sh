# Sourced by every verify-capstan helper. Not executable on its own.
# Inputs (export before calling a helper):
#   CAPSTAN_VERIFY_WORKTREE  absolute path of YOUR worktree (required; never the main checkout)
#   CAPSTAN_VERIFY_PORT      port for this instance (default 8412)
#   CAPSTAN_VERIFY_APP_HOST  app host (default app2.localhost)
#   CAPSTAN_VERIFY_RENDER_HOST render host (default render2.localhost; must differ from the app host)
#   CAPSTAN_VERIFY_RUN_ID    evidence folder name (default: launch time, persisted in the state dir)
set -euo pipefail

W="${CAPSTAN_VERIFY_WORKTREE:?export CAPSTAN_VERIFY_WORKTREE=<absolute path of your own worktree>}"
PORT="${CAPSTAN_VERIFY_PORT:-8412}"
APP_HOST="${CAPSTAN_VERIFY_APP_HOST:-app2.localhost}"
RENDER_HOST="${CAPSTAN_VERIFY_RENDER_HOST:-render2.localhost}"
U="http://${APP_HOST}:${PORT}"
R="http://${RENDER_HOST}:${PORT}"
DB="${W}/database/verify.sqlite"

# Run state (pid, server log, private drain config with tokens) lives OUTSIDE every repo and is
# removed by cleanup. Evidence lives beside it and is never removed by cleanup.
VERIFY_ROOT="${HOME}/Library/Caches/capstan-verify"
STATE="${VERIFY_ROOT}/state-${PORT}"
if [[ -z "${CAPSTAN_VERIFY_RUN_ID:-}" && -f "${STATE}/run-id" ]]; then
    CAPSTAN_VERIFY_RUN_ID="$(cat "${STATE}/run-id")"
fi
RUN_ID="${CAPSTAN_VERIFY_RUN_ID:-$(date +%Y%m%d-%H%M%S)-${PORT}}"
EVIDENCE="${VERIFY_ROOT}/evidence/${RUN_ID}"

# capstan requires PHP ^8.5; Herd's default php is 8.4.
mkdir -p /tmp/php85shim
ln -sf "${HOME}/Library/Application Support/Herd/bin/php85" /tmp/php85shim/php
export PATH="/tmp/php85shim:${PATH}"

die() { echo "FAIL: $*" >&2; exit 1; }

[[ "${W}" = /* ]] || die "CAPSTAN_VERIFY_WORKTREE must be absolute"
[[ -f "${W}/artisan" ]] || die "${W} is not a capstan checkout"
MAIN="$(git -C "${W}" worktree list --porcelain | awk '/^worktree /{print $2; exit}')"
[[ "$(cd "${W}" && pwd -P)" != "$(cd "${MAIN}" && pwd -P)" ]] \
    || die "${W} is the MAIN checkout. Make your own worktree; the main checkout is read-only."
[[ "${APP_HOST}" != "${RENDER_HOST}" ]] || die "app and render hosts must differ (origin isolation)"

art() { php "${W}/artisan" "$@"; }

# Every process descended from $1 (inclusive), deepest first.
descendants() {
    local pid="$1" child
    for child in $(pgrep -P "${pid}" 2>/dev/null); do
        descendants "${child}"
    done
    echo "${pid}"
}

port_pids() { lsof -nP -t -iTCP:"${PORT}" -sTCP:LISTEN 2>/dev/null || true; }
