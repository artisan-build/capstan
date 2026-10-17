# Capstan Work drain

Capstan Work holds a queue. A drain is the machine-side process that claims an eligible item and starts
the command assigned to its role. The reference drain in `drain/capstan_drain.py` is deliberately small:
cron starts a new Python process each minute, and that process starts a fresh worker for each item. It is
a working recipe for an organisation to adapt, not a required runtime or a launcher framework.

Work is off by default. When the Work feature is off, its API, MCP tools, scheduled sweeps, and page are
not available; the routes return not found and the rest of Capstan keeps working. Turn it on through the
Capstan feature configuration before testing a drain.

## Configuration

The drain reads one local JSON file. It does not require environment variables. Start from one of the
checked-in files under `drain/examples`: `claude.json` runs `claude -p`, `opencode-mini.json` runs
`opencode mini`, and `generic.json` runs the included stub worker. Commands are JSON arrays, with
one argument per element. They are never shell strings.

| Key | Meaning |
| --- | --- |
| `version` | Config schema version. The current value is `1`. |
| `capstan_url` | Root URL of the Capstan installation. Use HTTPS except for local development at `localhost` or a loopback IP. Do not include credentials or a query string. |
| `team` | Team id or route key used by the team-scoped drain endpoints. |
| `actor_id` | Non-secret numeric actor id sent in `X-Capstan-Actor-ID`. It must match the bound credential. |
| `credential_file` | File containing only the `capstan.work.drain` bearer. Mode must be `0600` or stricter. |
| `max_concurrent` | Maximum local workers, from 1 through the server batch cap of 25. |
| `max_load_1m` | One-minute system load ceiling. A higher measured value blocks claims for that run. |
| `max_memory_percent` | Used-memory ceiling from greater than 0 through 100. A higher value blocks claims. |
| `capabilities` | Scalar capabilities this host declares when claiming. |
| `roles` | Scalar roles this host accepts. These keys must exactly match `role_commands`. |
| `state_file` | Private JSON containing the recoverable PID/item/fence/phase/session records. |
| `work_dir` | Parent directory for separate item-and-fence working directories. |
| `lock_file` | Non-blocking process lock used to reject overlapping cron runs. |
| `prompt_template` | Worker prompt template. |
| `orchestrator_prompt_template` | Cold-start orchestrator prompt template. |
| `request_timeout_seconds` | Timeout for one HTTP request, greater than 0 and at most 120 seconds. |
| `role_commands` | Role-to-argv map. Every value is a non-empty JSON list of strings. |

Relative paths are resolved from the config file's directory. The command placeholders are
`{item_id}`, `{task_id}`, `{brief_url}`, `{milestone_url}`, `{steers_url}`, `{orchestration_url}`,
`{orchestrate_url}`, `{prompt_file}`, `{links_file}`, `{work_dir}`, `{config_dir}`, `{session_ref}`,
`{seat_fence}`, and `{prompt}`. Unknown placeholders stop configuration loading. Prefer stdin or
`{prompt_file}` to `{prompt}` because command arguments can be visible in a process listing.

The drain always sends the rendered prompt on stdin. This lets `claude -p` and generic commands receive it
without putting signed links in argv. The OpenCode Mini example passes `@{prompt_file}` to `--prompt`, so
its argv contains only the private item-local path. The generic example receives stdin and may also use
the item-local files when a harness wants file-based input.

Check local paths and credential permissions without calling Capstan:

```sh
python3 drain/capstan_drain.py --config /absolute/path/to/config.json --check-config
```

Then make one authenticated capacity-zero request. This proves the URL, team, actor, credential binding,
and feature state without claiming an item:

```sh
python3 drain/capstan_drain.py --config /absolute/path/to/config.json --dry-claim
```

## What one run does

The process takes the configured lock without waiting. An overlapping invocation exits successfully and
makes no HTTP call. It reads the bearer only after it owns the lock and never prints the value.

Existing PID records are handled first. A live process is renewed in a batch of at most 25 items. The
renew response replaces the signed links in that item's private `links.json` and updates any returned
opaque `session_ref` or seat fence. When a PID has exited, the same renew call determines what happened:
a `lost` result means the item is already terminal, cancelled, expired, or otherwise no longer owned. The
drain stops a still-live local process with lost authority, then removes its record without releasing the
item. A `renewed` result proves that an exited worker
left a non-terminal claim, and the drain sends `released`.

After recovery, the drain calculates free slots and reads the one-minute load and memory percentage. At
or above local capacity, or over either resource ceiling, it does not call claim and logs `not this
round` with the measured value and ceiling. Failed measurement also fails closed. An empty claim response
exits immediately and starts nothing.

Each claimed item gets a directory named from its item id and fence. The drain writes `prompt.md` and
`links.json` there with mode `0600`, launches the configured argv with that directory as cwd, stdin set to
the prompt, output disconnected, `shell=False`, and a new process session. A small Python gate holds the
command until launch succeeds, the minimum recovery record is durable, and Capstan accepts `delivered`
followed by `routed`. The drain then writes `launch.ready`, and the gate replaces itself with the configured
argv. The phase is saved after each accepted transition so a later cron run can finish routing and open the
gate after a partial API failure.

The global state file never contains the bearer or signed URLs. Signed URLs exist only in the private
item prompt and link file needed by that worker, and those two files are removed when the drain forgets a
terminal or released item. Worker stdout and stderr are not collected by the reference script. Adapted
drains that collect output must redact signed links before writing logs.

## Worker contract

The worker prompt tells a worker to fetch the latest brief through its signed link, post `started`, post
`blocked` if it cannot continue, post `pr_opened` after opening a pull request, and finish with `done` or
`failed`. It checks steers at each milestone. Branches use `capstan/<item-id>-<slug>`, which makes two
workers for the same item collide instead of quietly creating parallel pull requests.

An orchestrator gets a separate prompt. Every process begins cold: it fetches the current brief plus the
orchestration context, reads the saved state of play, decisions, and event range, performs scoped actions,
then explicitly sends the `finish` action with state `acted` or `failed`. A successful finish includes the
rewritten state of play for the next turn. This cold fresh-process path is the reference behaviour and the
recovery path for every implementation.

## Optional session reuse

An adapted drain may keep an orchestrator session alive between turns. It keeps the parent seat renewed,
persists Capstan's opaque `session_ref`, and wakes the session when another turn arrives. If the session is
gone or stops completing turns, the drain replaces it and cold-starts from Capstan's records. The
reference script records returned session metadata but always starts a fresh process; it does not provide
a session adapter.

For Solo, an adapter can store the Solo process id as `session_ref` and wake it through the Solo MCP. It
still needs a liveness check and a turn timeout before deciding the session is frozen. For Amp, route the
coordination turn through Amp's Puck agent and keep Puck's reference opaque to Capstan. These are wiring
notes only. Capstan does not depend on Solo or Amp, and this repository ships neither launcher.

An interactive MCP session can take the parent seat. While it holds and renews that seat, drain claims for
the parent's orchestrator turns are excluded and events accumulate. Releasing the seat hands the next
turn back to an eligible drain. A replacement holder receives the records needed for a cold start; stale
item or seat fences remain invalid.

## Scheduling and recovery

Run the script once a minute. The onboarding skill at `drain/SKILL.md` shows cron and launchd forms. Keep
the config, credential, state, lock, and work paths under a private directory. Use absolute paths in the
scheduler because cron has a small working environment.

If the state file is malformed, the drain leaves it unchanged and makes no claim. Repair it from the
item directories and Capstan's Work page rather than deleting it blindly. If a transition or renew call
fails, unrelated item records remain available for the next run. A claim that cannot launch is released
when the API is reachable. If that release also fails, the server lease expires and the normal sweep
returns the item to the queue.

Removing a stale PID entry by hand can let local concurrency exceed its intended limit or abandon a live
claim. Check the PID, item state, and fence first. Revoking or replacing the bound credential stops future
drain calls but does not kill already launched processes; stop those processes separately, then let their
leases expire or release the items with a valid replacement credential.

## Coordinator-driven verification

`drain/harness/stub_worker.py` reads the normal prompt from stdin and posts `started`, then `done`. It has
no credential and uses only the item-scoped milestone link. `drain/harness/run_loop.py` starts a fresh
drain process for each iteration, which matches cron more closely than calling Python functions in a test.

For AC10, run `php artisan serve` against a disposable local database, create the team, actor, bound drain
credential, task, and item as test records, point a copy of `generic.json` at them, and run:

```sh
python3 drain/harness/run_loop.py --config /absolute/path/to/generic.json --iterations 6 --interval 1
```

The coordinator then repeats the driven path with a `claude -p` role for AC11. AC15 uses an orchestrator
role and at least three separate loop invocations, confirming that each fresh Claude process continues
only from the brief, state of play, decisions, and events returned by Capstan. These driven checks create
their own records, contain no checked-in credential, install no scheduler, and do not touch another
repository.

## Limits and failure boundaries

Claim and renew batches are capped at 25, matching the server. Role and capability values are distinct,
non-empty strings of at most 64 characters. The client makes one bounded claim call per invocation and
one renew call per 25 tracked items. HTTP and launch failures are reported with item ids and safe status
codes; response bodies, bearer values, command prompts, and signed URLs are not logged.

The lock protects one config on one host. It is not the distributed claim lock; Capstan's fenced claim
API provides that protection across drain hosts. Likewise, the PID check is local process liveness, not
proof that a worker is making progress. Capstan's lease, timebox, milestone, stall, seat, and circuit
breaker rules remain authoritative.
