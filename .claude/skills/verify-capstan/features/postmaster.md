# Postmaster

## Sub-features

- `postmaster.poll` — `POST /api/v1/poll`: presence (claims inboxes), outbound, acks, cursor, probe response.
- `postmaster.probe` — liveness challenge in a poll response; spoke answers with sha256(nonce).
- `postmaster.spokes` — `postmaster_spokes`: spoke map with status and probe status.
- `postmaster.send` — `send_postmaster_message` (write door): server-signed envelope from an owned inbox.
- `postmaster.read` — `postmaster_messages` (inbox history with status) and poll `inbound`.
- `postmaster.ack` — `ack_postmaster_messages` or poll `acks`.
- `postmaster.connect` — `/postmaster` "generate onboarding snippet": a shell installer that runs the device flow and installs a cron poller.

## How to get to it (user POV)

- A spoke (CLI install) polls with the shared `capstan.machine` device-flow token.
- An MCP client uses `postmaster_spokes`, `postmaster_messages` (both doors), `send_postmaster_message`,
  `ack_postmaster_messages` (write door).
- Web: `/postmaster` → `postmaster-onboarding` section → its button → `postmaster-installer` `<pre>`.

## Driving it with capstan.py and Playwright

Preconditions: baseline; `"$B/capstan.py" token capstan.machine`; `"$B/mcp-token.sh"`;
`SID=$(sqlite3 … "select server_id from server_identity")`. Launch has already run
`bfc:signing-root:ensure --local`.

- **Claim an inbox** — `"$B/capstan.py" api capstan.machine POST /api/v1/poll '{"presence":{"ready_inboxes":["verify"]}}'`
  → `200`, `inbound: []`, and on a new spoke a `probe_challenge {probe_id, nonce, algorithm:"sha256"}`.
- **Spoke map** — `"$B/capstan.py" mcp read postmaster_spokes` → `items[0].inboxes: ["verify"]`, `status: "pending"`.
- **Answer the probe** — poll again with `"probe_response":{"probe_id":"…","digest":"<sha256 hex of nonce>"}`
  → `200`; `postmaster_spokes` → `probe_status: "green"`, `status: "green"`.
- **Send** — `"$B/capstan.py" mcp write send_postmaster_message '{"idempotency_key":"m1","from":"verify@SID","to":"verify@SID","type":"generic","version":1,"message_id":"<uuid>","body":{"n":1}}'`
  → `message.status: "pending"`, `message.signature` (64 hex), `outcome: "accepted"`.
- **Read** — `… mcp read postmaster_messages '{"inbox":"verify"}'` → the message, `status: "pending"`;
  the next poll returns it in `inbound` with its `signature`.
- **Ack** — `… mcp write ack_postmaster_messages '{"idempotency_key":"a1","message_ids":["<message_id>"]}'`
  → `acknowledged_count: 1`. Side effect: `select status, acked_at from messages` → `acked`, set.
  `postmaster_messages` keeps listing it with `status: "acked"` (it is history, not a queue).
- **Connect snippet** — Playwright on `/postmaster`: `postmaster-installer` count 0; click the button in
  `[data-testid="postmaster-onboarding"]`; wait for `[data-testid="postmaster-installer"]` → present,
  text starts `#!/bin/sh` and contains `/api/v1/poll`. Assert structure only; do not screenshot it.
- **Page at two viewports** — `"$B/web-script.sh"` pass: `/postmaster` 200, `postmaster-onboarding` 1, `overflowX: false`.

Not driven: RUNNING the onboarding installer (it rewrites the user's crontab under
`$HOME/.config/capstan/<server id>` — never run it on a dev machine); cross-user `inbox_claimed`;
`postmaster:probe-sweep` failure path; foreign-server relay.

## Gotchas

- `type` must be one of `handoff`, `kb_nomination`, `solicitation`, `generic`; anything else →
  `isError: true` "The selected type is invalid."
- **Without an installation signing root, `send_postmaster_message` returns `{"outcome":"outcome_unknown"}`
  with `isError: false`** and stores nothing. Launch runs `bfc:signing-root:ensure --local`; if you
  see `outcome_unknown`, run it and retry with a NEW idempotency key (the old key replays the unknown).
- Sending requires the `from` inbox to have been advertised by one of your spokes at least once.
- The installer snippet embeds a live device code for 10 minutes. Don't screenshot or save it.
- Ack takes the envelope `message_id`, not the row `id`.
