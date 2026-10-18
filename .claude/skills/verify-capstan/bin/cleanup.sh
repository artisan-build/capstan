#!/usr/bin/env bash
# Tear down what THIS run started: the server's whole process tree and the private state dir
# (pid, log copy, owner creds, drain config + tokens). Never touches evidence.
# --purge-db also deletes this run's SQLite file. Exits non-zero if the port is still held.
source "$(dirname "$0")/_env.sh"

if [[ -f "${STATE}/server.pid" ]]; then
    root="$(cat "${STATE}/server.pid")"
    pids="$(descendants "${root}" | tr '\n' ' ')"
    # Snapshot the tree before killing: php -S workers reparent to init once their parent dies.
    [[ -n "${pids// /}" ]] && kill ${pids} 2>/dev/null || true
    for _ in $(seq 1 20); do
        alive=""
        for p in ${pids}; do kill -0 "${p}" 2>/dev/null && alive="${alive} ${p}"; done
        [[ -z "${alive}" ]] && break
        sleep 0.25
    done
    [[ -n "${alive}" ]] && kill -9 ${alive} 2>/dev/null || true
    echo "killed tree of ${root}: ${pids}"
fi

if [[ -d "${STATE}" ]]; then
    mkdir -p "${EVIDENCE}"
    [[ -f "${STATE}/server.log" ]] && cp "${STATE}/server.log" "${EVIDENCE}/server.log"
    rm -rf "${STATE}"
    echo "removed ${STATE}"
fi

if [[ "${1:-}" = "--purge-db" ]]; then
    rm -f "${DB}"
    echo "removed ${DB}"
fi

held="$(port_pids)"
if [[ -n "${held}" ]]; then
    echo "FAIL: port ${PORT} still held by ${held}. If those are not yours, leave them alone and report it." >&2
    exit 1
fi
echo "port ${PORT} free; evidence kept at ${EVIDENCE}"
ls "${EVIDENCE}"
