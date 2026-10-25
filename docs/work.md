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

Use `set_task_priority` to change a root's priority. It takes `team_id`, `task_id`, `priority`, and an
`idempotency_key`. The root owner may call it as a Member; a same-team Owner or Admin may also call it.
Children inherit the change. Creating a root directly at `P0` is refused.

For `P0`, first call without `confirmation_token` or `reason`. The response has `confirmation_status:
required`, a deterministic token, and an ordered `preemptions` inventory. Each entry identifies the item,
role, machine, work owner, and latest milestone kind and time when present. Preview does not change tasks
or request releases. It uses fresh machine runtime profiles; machines without a fresh presence are absent.

Confirm with a fresh idempotency key, that token, and a reason of 1–2,000 characters without credentials or
URLs. If the plan changed, the response refuses the change and supplies the current inventory and token.
An accepted change records the reason as an orchestration decision linked to a priority-change event and
wakes the root orchestrator. Retrying the same confirmation key replays its result without another decision.

When an eligible ordinary `P0` item encounters a full machine, Capstan requests one ordinary worker's
release. It selects `P3` before `P2` before `P1`, then `claimed` before `delivered` before `routed`, then
the lowest UUIDv7 item id. Human, orchestrator, P0, lease-dead, and already-requested claims are excluded.
If the item fits more than one machine, the normal deterministic machine rank chooses one before Capstan
plans or requests a release. A machine with free capacity wins over one that would require preemption.
Repeated full-machine polls wait for the outstanding release instead of requesting more victims.
The capacity-zero poll sent by a full reference drain still performs this release check; it never claims
another item while the machine is full.

Brief and steer JSON responses include `release_requested` only while a request is live, with its request
time, deadline, and reason. Markdown briefs also show the request. Save branch/progress through the existing
`released` milestone. The worker returns to its own task's queue at its own priority, with its milestone
handoff intact. The existing drain transition `released` also clears the request. The P0 item cannot claim
the slot until it is free.

The default grace is 60 seconds, configurable through `capstan.work.preemption_grace_seconds` (bounded to
1–3,600 seconds). The liveness sweep requeues silent victims, increments their fence immediately, and clears
their claim and release state. Old writes and renewals then fail. Preemption stores one bounded database
notification for the victim root's effective escalation actor and writes the existing Work log channel.
The notification and release request commit together.

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

## Reading the Work board

The Work page shows the latest 25 root tasks from the signed-in user's teams in Queued, Running, and Finished
columns. Child tasks stay inside their root card. A child title links to its pull request when Capstan has
canonical GitHub PR metadata; otherwise the title is plain text.

A root is Finished when its root-and-direct-child tree has at least one item and every item is terminal. It is
Running when any item in that tree is claimed, delivered, or routed. All other roots are Queued, including roots
with no items and trees containing only terminal and queued items. Nested descendants are not expanded on this
board.

Each card shows the root title, owner, priority, and direct children. Its details list every running item with its
role and machine name, unanswered escalations, evidence from the root and direct children, deduplicated
dependencies, and the newest saved state of play. The board is read-only. Use the MCP tools or a separate form
for reassignment and priority changes.

## Starting work from a Page

`create_task` accepts either an inline `brief` or a `page_id`, never both. A Page brief is resolved to the
latest Page version inside the task-creation transaction. Capstan records the Page and version ids and copies
that version's content into the first Work brief revision. Publishing another Page version does not change an
existing task. The caller must own the Page or hold an active internal share.

`create_task` also accepts `parent_task_id`, `depends_on`, `priority`, `owner_actor`, `workflow_ref`, and
`workflow_sha`. A child inherits the root owner, priority, workflow reference, and workflow SHA. The workflow
reference and SHA must be supplied together; the SHA is a lowercase 40-character Git commit id. Capstan
stores the pin and never fetches or interprets the workflow repository.

`kickoff` takes a team, Page, title, optional priority, and optional workflow pin. In one idempotent database
write it creates the root task from the pinned Page version and queues one orchestrator item. A failed Page
lookup leaves neither record behind. An orchestrator can explicitly use `repin_workflow` to replace the root
pin. The new pin is copied to existing descendants, and one `workflow_repinned` event records the old and new
reference and SHA. Repeating the same pin does not add another event.

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

The `pr_opened` payload includes a `pr` object with exactly `repo`, `number`, and an HTTPS `url`. The first
accepted value becomes the task's PR metadata. A worker can replay that value but cannot replace it. An
orchestrator can use `set_pr` when the task needs a corrected or replacement PR; Capstan records that change
and wakes the next turn. Numeric strings are accepted for compatibility at each PR metadata ingress, but
Capstan stores and returns `number` as an integer.

### Amp orb workers

Amp orbs connect to the dedicated MCP endpoint at `/mcp/orb`. It has four tools: `work_brief`,
`work_started`, `work_progress`, and `work_finished`. It does not expose the product MCP tools,
orchestration actions, evidence uploads, steers, verdict actions, or machine APIs. The existing `/mcp`
and `/mcp/write` endpoints keep their Built for Cloud authentication and tool lists.

The orb sends Amp's workload identity token as a bearer. Capstan verifies the token's signature using the
issuer's discovered OpenID metadata and JWKS, then checks the issuer, the exact `/mcp/orb` audience for the
installation, expiry and other time claims, token use, verified email, Amp user, project, thread, and subject.
Discovery and signing keys are cached briefly. A new key id causes one refresh before the request is refused.

The token is only the first half of authorization. At each tool call, Capstan finds exactly one live claim
whose delivered Amp session reference has the same signed project and thread. It also checks the current
claim fence and launch nonce, the claimant spoke and actor, the local user, and team membership. Tool arguments
cannot select an item, fence, nonce, team, or user. Claim rotation, cancellation, lease expiry, spoke retirement,
account deactivation, or removal from the team stops later calls.

An Amp session reference has this wire form:

```text
amp:v1:<base64url-without-padding of UTF-8 JSON>
```

The JSON object contains exactly `project_id`, `thread_id`, and `thread_url`. The URL must use HTTPS on
`ampcode.com` and name the same thread in its path. Capstan keeps the encoded value opaque in the claim; public
Work representations return only the validated `thread_url` for an Amp claim.

On first use, a verified Amp email may link the stable Amp `user_id` to one active, verified Capstan account
with the same normalized email. The link is one-to-one and remains authoritative. A later token cannot move an
Amp id to another local account, and another Amp id cannot take an account that is already linked. The account
must still belong to the claim's team on every call.

`work_brief` uses the guarded worker brief path and records the existing fetch fields. A stall directive may
be settled as `resolved_by_fetch`, but the fetch does not clear `stalled_at` or move the work deadline.
`work_started` adds one idempotent `started` milestone and sets the first deadline from the task's
`timebox_minutes`. Repeating it does not move that deadline or clear an existing stall. `work_progress`
requires a short non-empty summary, records the existing progress milestone, moves the deadline from the call
time, clears the current stall and first-miss marker, and settles pending deadline or stall repair work as
`resolved_by_progress`.

`work_finished` requires a bounded non-empty writeup and a PR object containing exactly `repo`, `number`, and
the matching canonical `https://github.com/<repo>/pull/<number>` URL. The first valid PR may populate the child
task's PR metadata; a conflicting value is refused. The item moves to `awaiting_verification` with its claim,
fence, launch nonce, and Amp thread unchanged. It is not complete, and the orb cannot start or post progress
while it waits. An exact finish replay returns the same record, while a different writeup or PR is refused.

The finish wakes the root orchestrator with one `child_awaiting_verification` event. The event contains the
bounded writeup, PR, finish id, child task, item, and fence. The current fenced orchestrator seat or turn reviews
it with `review_finish`. `accept` records the verdict, stores the writeup as the item's final progress and `done`
milestone, moves the item to `acted`, and allows dependent work to be claimed. `corrections` requires bounded
text, creates one item steer, returns the same claim to `routed`, and opens a fresh work deadline. The Amp thread
stays attached so the drain can continue it rather than launch a replacement session.

Declaring a finish clears the orb's work deadline and pending deadline or stall nudge. Deadline responsibility
then belongs to the orchestrator event, turn receipt, and renewable orchestrator lease. The waiting orb is not
nudged. Only a current orchestrator authority can record a verdict; the Amp workload bearer is not accepted by
the product MCP or signed orchestrator action ingress.

Capstan stores the stable Amp user id, its local user link, the opaque session reference, and the declared PR
identity. It does not store the workload identity token, an Amp secret, or a GitHub credential. GitHub access
stays in the local orchestrator environment that judges the PR; Capstan receives only its fenced verdict.

### Launch receipts and repair directives

Each claim has one `launch_nonce`. Every signed worker link for that claim carries the same nonce. A Solo-aware
launcher sends the nonce and its opaque `session_ref` with the `delivered` transition. Capstan records those
values and `handed_off_at` without adding another item state, so the existing
`claimed → delivered → routed` transition contract remains valid. The legacy Python drain may continue to send
`delivered` without launch metadata. Newly minted links expire at the claim's current `lease_until` plus the
bounded link grace. Renewals and repair directives return fresh links; they do not extend links minted earlier.

The first brief request with the claim's nonce records `first_fetched_at`, the exact brief revision and Page
version served, and the optional agent-reported `model` query value. Every signed worker and orchestrator
request must carry the claim's current nonce. A respawn rotates that nonce, so links held by the replaced
session fail without reading or changing Work data. Page-backed tasks serve the immutable Work brief copied
from the Page version selected at creation.

The ordinary receipt deadline is 180 seconds. Orchestrator turns use a 60-second deadline. A machine may
override these values through application configuration. After a missed deadline, the next machine tick
returns one pending directive in this order: `send_enter`, `resend_pointer`, first `respawn`, then second
`respawn`. Each respawn carries a distinct new nonce and fresh signed links. A pending directive is replayed
unchanged until its outcome is recorded or a matching brief fetch proves it succeeded. The next repair cannot
be issued until another deadline has elapsed. After the second respawn completes and its deadline elapses,
exhausting the sequence creates one owner-addressed escalation for that item, fence, and failure reason.
Each later stall episode has its own escalation identity, so exhausting it notifies the owner again without
replacing the earlier directive or escalation history.

Running items marked stalled use the same directive records and outcome rules. Directives are durable rows,
separate from item transitions. Their item, fence, machine, sequence, reason, payload, status, timestamps, and
bounded outcome make poll replay idempotent and keep policy on the server.

The worker timebox is separate from the renewable infrastructure lease. Missing the first work deadline emits
one channel-neutral `nudge` telling the worker to post progress or finish. It does not mark the item stalled or
requeue the claim. If no real progress arrives for one more full task timebox, the second miss marks the item
stalled once and hands it to the existing stall repair ladder. Lease expiry still recovers an abandoned claim,
but a deadline sweep does not use lease expiry as a substitute for either work-deadline stage.

The signed milestone link follows the same clock. Its idempotent `started` milestone opens the first deadline
without clearing an existing stall. Each later non-terminal progress milestone moves the deadline by a full
timebox, clears the first-miss marker and stall, and settles the current deadline or stall repair. Terminal and
`released` milestones clear deadline state without opening another deadline.

A matching brief fetch settles each pending directive according to its reason. A receipt repair records a
successful receipt. A stall repair records `resolved_by_fetch` without a receipt marker. Fetching the brief
does not clear `stalled_at`; without a later milestone, the next stall repair may be issued after its deadline.

A newly accepted real-progress milestone from the current claim holder clears `stalled_at` and settles a
pending stall directive as `resolved_by_progress`. The milestone, item update, and directive settlement commit together. An
idempotent replay that returns an existing milestone does not clear a later stall. If the same claim holder
stalls again after making progress, the repair order starts again at `send_enter`. Directive sequence numbers
remain unique and continue across episodes so the earlier history is not replaced.

The extended claim request accepts `held_items`. Each entry has an item id and fence and may report a directive
id with a `succeeded` or `failed` outcome. A successful respawn also reports its new nonce and session reference.
The response always has `spoke_id`, `items`, and `directives`. `spoke_id` is the machine identity resolved
from the bound credential, not a caller-supplied value. A Solo launcher uses it in the authoritative session
reference instead of guessing the machine identity. An old drain can ignore the added key.

Leaf and orchestrator concurrency are separate when `orchestrator_capacity` is present. `capacity` admits only
ordinary leaf roles, while `orchestrator_capacity` admits only the reserved orchestrator role. A full leaf pool
therefore cannot block an orchestrator wake, and an unused orchestrator slot cannot admit a leaf. Requests that
omit `orchestrator_capacity` keep the legacy combined-capacity behavior.

### Evidence uploads

Each claimed item gets an `evidence` URL alongside its other signed links. Send one file in a multipart
`file` field. Interactive sessions use `upload_work_evidence` with the team, task and item ids, a filename,
canonical base64 bytes, and an idempotency key. Both routes accept PNG, JPEG, WebP, GIF, MP4, text, and JSON,
up to 10 MiB per file and 20 files per item by default.

The response contains the same evidence object on either route, including a permanent public-by-link URL.
Anyone with that unguessable URL can fetch the file without signing in. Workflow evidence must therefore
come only from local or seeded environments. Never upload customer production data.

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
still needs a liveness check and a turn timeout before deciding the session is frozen. Amp claims use the
session reference and workload identity contract above. Capstan does not store a credential for either
launcher.

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
