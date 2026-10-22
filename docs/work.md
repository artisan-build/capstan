# Capstan Work drain

Capstan Work holds a queue. A drain is the machine-side process that claims an eligible item and starts
the command assigned to its role. The reference drain in `drain/capstan_drain.py` is deliberately small:
cron starts a new Python process each minute, and that process starts a fresh worker for each item. It is
a working recipe for an organisation to adapt, not a required runtime or a launcher framework.

Work is available on every installation. Pooled Work is off by default for each team, so new tasks stay
routed to their owners unless an Owner or Admin explicitly changes that team policy. A root task's owner
defaults to its creator. Ownership remains separate from the creator and from any explicit routing override.

## The drain credential

The drain uses the same `capstan.machine` identity as the installed poller. In the browser, open
`/postmaster`, generate the Connect-this-machine installer, run it on the target machine, and approve the
request. The approving person becomes the machine owner. The installer writes the bearer to
`~/.config/capstan/<server-id>/token` and the non-secret owner id to the adjacent `actor-id` file, both with
mode `0600`.

Set `credential_file` to that installed token path. Read `actor-id` locally and copy its numeric contents
as the quoted JSON scalar `actor_id` when preparing the config. Do not duplicate or paste the bearer, put it
in an environment variable, or mint a separate Work credential.

## Who can claim a task

A drain is offered work according to the task's owner, the team policy, and any explicit address. When
`user_actor_address` is set, only that actor can claim the task. Without an override, a team with pooled
work off when the task is created routes it to `owner_actor`; a task created while pooled work is on can go
to an eligible team machine. The creator is recorded separately and does not control routing after another
owner has been assigned.

Pooled work changes the no-override route for one team. In a pooled team, a caller who passes
`user_actor_address` gets exactly that address. In a non-pooled team, an orchestrator-created child is pinned
to the root owner; moving it to another person requires `reassign_task`. Descendants inherit the root task's
owner and priority, including grandchildren. Changing the immediate child's input does not create a second
ownership boundary.

Changing the pool policy does not affect existing tasks. A task created while pooled work was off stays
addressed to its creator after it is turned on, and only tasks created after the change follow the new
setting.

## Reassigning a task

`reassign_task` accepts a root `task_id`, the numeric `to_actor`, a mode, an optional note of up to 2,000
characters, and an `idempotency_key`. The current root owner may call it even when they are a Member. An
Owner or Admin who belongs to the task's team may also call it. The target must be an active, admitted member
of that same team. Child ids, people outside the team, inactive accounts, and offboarded accounts are
refused. A request from outside the team does not reveal whether its task id exists there.

Work ownership and escalation addressing are separate. `owner_actor` controls which person's machines run
the work. `escalation_actor` controls who receives questions and Work alerts; when it has not been set,
Capstan uses the owner.

The `move` mode assigns both roles to the target across the root and every descendant. It updates future
person routing and clears any device pin owned by someone else, while preserving a pin already owned by the
target. The old orchestrator seat is released, its fence increments, and old orchestrator writes fail their
fence checks. Ordinary items already running keep their claimant, lease, and fences, so their original
machine may finish them.

The `escalations` mode changes only `escalation_actor`. Ownership, person and device routing, the current
seat, session, lease, fences, active turn, and ordinary claims stay unchanged. Existing and newly queued
items continue on the old owner's machines while questions and Work alerts go to the target.

Both modes readdress every unanswered escalation in the subtree, along with its queued human item. Answers
already recorded remain attached to their original addressee. Each accepted reassignment adds one waking
event to the root with the old and new work owner, old and new escalation actor, mode, and optional note. A
repeated call with the same idempotency key replays the accepted result without adding another event or
notification.

An orchestrator may omit `addressed_actor` when creating an escalation. Capstan then uses the root's
effective escalation actor. Passing the id of another active person remains supported.

Work alerts are stored as Laravel database notifications as well as written to the existing logs. An
escalation goes to its addressee. Reassignment, stalled-item, pinned-host, and seat-waiting alerts go to the
effective escalation actor; a machine heartbeat alert goes to that machine's owner. Notification payloads
carry only bounded ids and state. The state change and notification insert share one database transaction.
If the notification insert fails before an effect is accepted, neither change is committed and the same MCP
idempotency key can be retried. Ambiguous outcomes remain unavailable for retry.

## Priority, dependencies, and expiry

Root tasks default to priority `P2`. The four priorities are `P0`, `P1`, `P2`, and `P3`, in that order.
Claims take the highest eligible priority first, then use the UUIDv7 item id as FIFO within a priority.
An owner can have only one uncancelled `P0` root. An orchestrator can lower its root's priority but cannot
raise it; the lower priority is applied to the existing descendants as well.

`depends_on` is a list of task ids. Each dependency must exist in the same team and cannot be the task
itself, one of its descendants, or an edge that closes a cycle. A dependent item stays queued until every
dependency is successful. Success means the dependency is not cancelled, has at least one item, and every
item is `acted`. A dependency completed before its deadline remains successful after the deadline passes.
Failed, cancelled, and expired dependencies do not satisfy the gate. They wake a live root orchestrator,
or create one owner-addressed signal when no live seat exists. A stopped seat uses the owner signal instead
of dropping the event. The dependent task is not cancelled automatically.

A task may set `expires_at`. Optional `pr` metadata is an object with a repository name, positive pull-request
number, and HTTPS URL. A descendant's effective expiry is the earliest expiry on itself or any ancestor. An
elapsed expiry blocks new claims immediately. The expiration sweep moves queued items to the terminal
`expired` state and leaves claimed, delivered, or routed items running. Completed tasks are not expired.
Each durable expiration row records the task, owner, priority, waited seconds, deadline provenance (`own` or
`ancestor`), and a separate never-ran cause. PR1 records `dependency_unmet` when a dependency blocked the
task and `unclaimed` otherwise. `no_eligible_machine` is reserved for evidence that proves that condition;
`preempted` is reserved for PR3. Repeated sweeps do not duplicate the record or transition.

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
| `actor_id` | Non-secret numeric actor id copied as a quoted JSON scalar from `~/.config/capstan/<server-id>/actor-id`. It must match the machine credential. |
| `credential_file` | Path to `~/.config/capstan/<server-id>/token`, containing only the installed `capstan.machine` bearer. Mode must be `0600` or stricter. |
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
The examples already point `credential_file` at the installer directory; replace `<server-id>` with the
directory created on this machine. The `./var` paths are meant to stay relative to the copied config. The command placeholders are
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

Then make one authenticated capacity-zero request. This proves the URL, team, actor, and credential binding
without claiming an item:

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
it into instruction text a model will follow. The reader is whoever holds that task's orchestrator seat
when the turn runs, which need not be the person who answered.

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
the config, state, lock, and work paths under a private directory, and keep the credential in the installer's
mode-protected server directory. Use absolute paths in the scheduler because cron has a small working environment.

If the state file is malformed, the drain leaves it unchanged and makes no claim. Repair it from the
item directories and Capstan's Work page rather than deleting it blindly. If a transition or renew call
fails, unrelated item records remain available for the next run. A claim that cannot launch is released
when the API is reachable. If that release also fails, the server lease expires and the normal sweep
returns the item to the queue.

Removing a stale PID entry by hand can let local concurrency exceed its intended limit or abandon a live
claim. Check the PID, item state, and fence first. Revoking or replacing the bound credential stops future
drain calls but does not kill already launched processes; stop those processes separately, then let their
leases expire or release the items with a valid replacement credential.

The server runs liveness, failover, orchestration, and expiration sweeps once a minute. Drains do not need
to send the new task fields, and the existing claim request and response remain valid.

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
