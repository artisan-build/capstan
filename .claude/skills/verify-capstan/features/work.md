# Work

## Sub-features

- `work.task` — create a task with its first brief revision (`create_task`), revise it (`revise_brief`).
- `work.queue` — queue one item with one role (`queue_item`); cancel an item or task.
- `work.steer` — attach a steer to a task or item (`steer`).
- `work.drain` — a host claims, launches, renews and transitions items (`/api/v1/work/{team}/claim|transition|renew`).
- `work.milestones` — the worker posts `started`/`done` etc. through its signed milestone link.
- `work.seat` — an interactive MCP session takes/releases the orchestrator seat (`take_seat`/`release_seat`).
- `work.orchestrate` — seat holder acts: `create_child`, `revise_brief`, `queue_item`, `cancel_item`, `steer`, `decide`, `request_wake`, `escalate`, `finish`.
- `work.escalations` — list and answer pending escalations (`list_escalations`/`answer_escalation`, or the `/work` form).
- `work.page` — the `/work` board.

## How to get to it (user POV)

- Web: sidebar `work-navigation` → `/work`. Board `work-board`; tasks `work-task` with `work-task-brief`,
  `work-task-steers`/`work-steer`; items `work-item` with `work-item-state`, `work-item-claimant`,
  `work-item-milestone`, `work-item-stalled`, `work-item-waiting`; escalations `work-escalations` →
  `work-escalation` (textarea + submit).
- MCP write door: `create_task`, `revise_brief`, `queue_item`, `cancel_item`, `cancel_task`, `steer`,
  `take_seat`, `release_seat`, `orchestrate`, `answer_escalation`, `list_work`, `get_task`, `list_escalations`.
- MCP read door: `list_work`, `get_task`, `list_escalations`.
- Drain host: `drain/capstan_drain.py` (cron-style, one process per minute) with a `capstan.work.drain` token.
- Worker: the signed brief/milestone/steers/orchestration links the drain writes into the item dir
  (`/api/v1/work/items/{item}/…`).

## Driving it with capstan.py, the reference drain and Playwright

Preconditions: baseline; `"$B/capstan.py" token capstan.work.drain`; `"$B/mcp-token.sh"`;
`C=$("$B/drain-config.sh")`. For seat/escalation: a Postmaster spoke exists (one `POST /api/v1/poll`,
see `postmaster.md`); spoke id 1 on a fresh DB.

- **Create task** — `"$B/capstan.py" mcp write create_task '{"team_id":1,"idempotency_key":"t1","title":"Verify task","brief":"Stub worker: post started then done."}'`
  → `structuredContent.task.id` (T) and `revision.sequence: 1`.
- **Queue item** — `… mcp write queue_item '{"team_id":1,"idempotency_key":"i1","task_id":"T","role":"builder"}'`
  → `item.state: "queued"`, `fence: 0`.
- **Steer** — `… mcp write steer '{"team_id":1,"idempotency_key":"s1","task_id":"T","content":"verify steer"}'` → `steer.id`.
- **Drain config check** — `python3 drain/capstan_drain.py --config "$C" --check-config` →
  `configuration is valid; no HTTP request was made`, exit 0.
- **Authenticated capacity-zero call** — `… --dry-claim` → `dry claim succeeded with capacity=0; no item was claimed`, exit 0.
- **Drain end to end** — `python3 drain/harness/run_loop.py --config "$C" --iterations 6 --interval 1`
  → first iteration `launched item_id=… role=builder pid=…`, then `queue empty`.
  State: `… mcp read list_work '{"team_id":1,"task_id":"T"}'` → the item sits in `groups.acted`.
  Side effects: `select from_state,to_state from work_item_transitions` → queued→claimed→delivered→routed→acted;
  `select kind from work_milestones` → `started`, `done`.
- **Take seat** — `… mcp write take_seat '{"team_id":1,"task_id":"T2","spoke_id":1,"session_ref":"verify-session","idempotency_key":"seat1"}'`
  on a second task T2 → `seat.seat_fence: 1`, `context.brief`.
- **Escalate** — `… mcp write orchestrate '{"team_id":1,"task_id":"T2","spoke_id":1,"seat_fence":1,"session_ref":"verify-session","action":"escalate","input":{"key":"e1","addressed_actor":"1","question":"Ship it?","options":["yes","no"],"recommendation":"yes"},"idempotency_key":"o1"}'`
  → `status: "completed"`, `outcome: "accepted"`; `… mcp read list_escalations '{"team_id":1}'` lists it.
- **Answer in the UI** — Playwright on `/work`: count `[data-testid="work-escalation"]` (1), fill its
  `textarea`, click its `button[type="submit"]`, wait ~1.5 s → count 0. Side effect:
  `select answer, answered_at from work_escalations` is set.
- **Cancel task** — `… mcp write cancel_task '{"team_id":1,"idempotency_key":"c1","task_id":"T"}'` → `task.cancelled_at` set.
- **Board at two viewports** — `"$B/web-script.sh"` pass: `/work` 200, `work-board` 1, `overflowX: false`
  at 1280 and 390; screenshots `desktop-work.png`, `mobile-work.png`.

Not driven yet (no recipe here until someone drives it): `revise_brief`, `cancel_item`, `release_seat`,
`orchestrate` actions other than `escalate`, `answer_escalation` over MCP, the worker `orchestration`/
`orchestrate` links, the `claude -p`/`opencode` role commands, `work:liveness-sweep`/`work:failover-sweep`.

## Gotchas

- **A token minted on `/settings/credentials/personal` or `/installation` is rejected 401 by the
  Work API.** Only a device-flow token works. This is what blocked the production drain.
- **Cloudflare blocks Python's default `Python-urllib/*` User-Agent on Laravel Cloud** (HTTP 403
  `error code: 1010`). #83 made the drain and stub worker send an explicit User-Agent. The edge is
  INVISIBLE on localhost: a local pass proves nothing about it, so any drain change that touches HTTP
  needs a production `--dry-claim` before you call it working.
- The drain accepts plain `http` only for `localhost` or a loopback IP, not `app2.localhost`;
  `drain-config.sh` points it at `http://127.0.0.1:<port>`.
- The example config's `max_load_1m: 6.0` blocks every claim on a busy dev Mac ("not this round:
  load_1m=25.04 ceiling=6.00"). `drain-config.sh` raises it to 1000; capacity gating is not under test.
- `actor_id` in the drain config must equal the credential's user id, or the API refuses.
- Seat and escalation need a spoke id; on a fresh DB that means one Postmaster poll first.
- The stub worker finishes in under a second; run `run_loop.py` rather than calling the drain once,
  or you never see the renew/forget pass.
