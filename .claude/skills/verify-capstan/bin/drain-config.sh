#!/usr/bin/env bash
# Write a private reference-drain config (stub worker, role "builder", team 1) under the state dir,
# outside every repo. Needs ${STATE}/tokens/capstan.machine (bin/capstan.py token capstan.machine).
# Prints the config path.
source "$(dirname "$0")/_env.sh"
# shellcheck disable=SC1091
source "${STATE}/owner.env"
tok="${STATE}/tokens/capstan.machine"
[[ -s "${tok}" ]] || die "no ${tok}; run: bin/capstan.py token capstan.machine"
umask 077
dir="${STATE}/drain"
mkdir -p "${dir}/var"
python3 - "${W}" "${U}" "${OWNER_ID}" "${tok}" "${dir}" <<'PY'
import json, sys
w, url, actor, tok, d = sys.argv[1:6]
cfg = json.load(open(f"{w}/drain/examples/generic.json"))
cfg.update({
    # The drain accepts plain http only for "localhost" or a loopback IP, not *.localhost hosts.
    "capstan_url": f"http://127.0.0.1:{url.rsplit(":", 1)[1]}", "team": 1, "actor_id": actor, "credential_file": tok,
    "state_file": f"{d}/var/state.json", "work_dir": f"{d}/var/work", "lock_file": f"{d}/var/drain.lock",
    "prompt_template": f"{w}/drain/worker-prompt.md",
    "orchestrator_prompt_template": f"{w}/drain/orchestrator-prompt.md",
    # A dev Mac running several agents sits far above the example's load ceiling of 6, and the drain
    # then logs "not this round" forever. Capacity gating is not what a verify run is proving.
    "max_load_1m": 1000.0, "max_memory_percent": 100.0,
    "role_commands": {"builder": ["python3", f"{w}/drain/harness/stub_worker.py"]},
})
json.dump(cfg, open(f"{d}/generic.json", "w"), indent=2)
PY
echo "${dir}/generic.json"
