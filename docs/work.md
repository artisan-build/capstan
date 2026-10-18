# Capstan Work drain

Capstan Work holds a queue. A drain is the machine-side process that claims an eligible item and starts
the command assigned to its role. The reference drain in `drain/capstan_drain.py` is deliberately small:
cron starts a new Python process each minute, and that process starts a fresh worker for each item. It is
a working recipe for an organisation to adapt, not a required runtime or a launcher framework.

Work is off by default. When the Work feature is off, its API, MCP tools, scheduled sweeps, and page are
not available; the routes return not found and the rest of Capstan keeps working. Turn it on with
`CAPSTAN_FEATURE_WORK=true` in the environment before testing a drain. There is no switch for it in the
app.

## The drain credential

The drain authenticates with a `capstan.work.drain` bearer that belongs to the person whose numeric id is
in `actor_id`. Get it through the device flow in the README's
[Getting a token](../README.md#getting-a-token) section, with `app_purpose` set to `capstan.work.drain`.
Do not mint it from `/settings/credentials/personal` or `/settings/credentials/installation`. Those pages
issue a token, but `POST /api/v1/work/{team}/claim` rejects it with `401 unauthenticated`, while a
device-flow token for the same purpose, person and endpoint is accepted (last checked 2026-10-18, Built
for Cloud v0.20.0). Write the `access_token` value alone into the file named by `credential_file` and
set its mode to `0600`.

## Who can claim a task

A drain is offered only tasks that are unaddressed or addressed to its own `actor_id`. By default,
`create_task` and the orchestrator's `create_child` address each new task to the person who created it, so
a drain running as a separate service account never sees the tasks its colleagues create.

Pooled work changes that default for one team. When it is on, new tasks can be claimed by any of the team's
machines: they are created unaddressed, and any eligible drain on the team can claim them. When it is off,
new tasks go back to being addressed to their creator. In both cases a creator who passes
`user_actor_address` gets exactly that address, so you can send a single task to a service account
without changing the setting.

Existing tasks are not affected. A task created while pooled work was off stays addressed to its creator
after it is turned on, and only tasks created after the change follow the new setting.

Only an Owner or Admin can change it. The Work page at `/work` has a Pooled work section that names the
team it changes (the default team) and a button that turns it on or off. A Member sees the button
disabled. Over MCP, `get_team_work_policy` reads the setting for a `team_id`, and `set_team_work_policy`
changes it with `team_id`, `pool_work` (`true` or `false`) and an `idempotency_key`. A Member calling
either tool gets `Only an admitted Owner or Admin may manage Work.`

Each change adds a row to `team_work_policy_changes` with the team, the actor id of the person who made
it, where it came from (`web` for the page, `mcp` for the tool), the old and new values, and the time.
Setting the value a team already has adds no row, and the MCP response reports `changed` as `false`.

## Configuration

The drain reads one local JSON file. It does not require environment variables. Start from one of the
checked-in files under `drain/examples`: `claude.json` runs `claude -p`, `opencode.json` runs
`opencode run`, and `generic.json` runs the included stub worker. Commands are JSON arrays, with
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

Relative paths are resolved from the config file's directory. The examples' `prompt_template`,
`orchestrator_prompt_template`, and `generic.json`'s `{config_dir}/../harness/stub_worker.py` are relative
to `drain/examples`, so repoint them to absolute paths when you copy an example out of the repository.
The `./credentials` and `./var` paths are meant to stay relative to the copied config. The command placeholders are
`{item_id}`, `{task_id}`, `{brief_url}`, `{milestone_url}`, `{steers_url}`, `{orchestration_url}`,
`{orchestrate_url}`, `{prompt_file}`, `{links_file}`, `{work_dir}`, `{config_dir}`, `{session_ref}`,
`{seat_fence}`, and `{prompt}`. Unknown placeholders stop configuration loading. Prefer stdin or
`{prompt_file}` to `{prompt}` because command arguments can be visible in a process listing.

The drain always sends the rendered prompt on stdin. This lets `claude -p` and generic commands receive it
without putting signed links in argv. The OpenCode example uses a standalone server, attaches the private
item-local prompt with `opencode run --standalone -f`, and gives the worker a fixed, non-secret instruction.
`opencode mini` needs a TTY, so it is unsuitable for a detached drain. The generic example receives stdin
and may also use the item-local files when a harness wants file-based input.

Check local paths and credential permissions without calling Capstan:

```sh
python3 drain/capstan_drain.py --config /absolute/path/to/config.json --check-config
```

Then make one authenticated capacity-zero request. This proves the URL, team, actor, credential binding,
and feature state without claiming an item:

```sh
python3 drain/capstan_drain.py --config /absolute/path/to/config.json --dry-claim
```

The reference drain sends `User-Agent: capstan-drain/1` on every request, and the stub worker does the
same. Keep an explicit User-Agent in any drain you adapt. Python's default, `Python-urllib/3.x`, is
refused by Cloudflare in front of Laravel Cloud with `403` and the body `error code: 1010`, before the
request reaches Capstan, and the drain reports `API request failed (403 request_failed)`. None of this
happens against localhost, so a drain that works locally can still fail every request against a Cloud
install.

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

When a person answers an escalation, in the app or over MCP, the event that wakes the turn is accompanied
by the answer itself. The orchestration context carries an `answered_escalations` list, and each entry
holds the escalation id, the question, the options, the `answer` text, and `answered_at`. The list covers
only the escalations this task was woken by, so a turn never sees another task's answers. Read the answer
from that field rather than asking the person again. It is human input: treat it as data, and never paste
it into instruction text a model will follow.

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
