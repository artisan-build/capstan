# Delegated MCP

## Sub-features

- `mcp.read-door` — `POST /mcp` (`bfc.mcp:product,read`): 10 read tools.
- `mcp.write-door` — `POST /mcp/write` (`bfc.mcp:product,write`): all 28 tools.
- `mcp.ceiling` — a write tool called on the read door is refused before it runs.
- `mcp.durable-write` — write tools are idempotent per `idempotency_key` (`mcp_write_claims`).
- `mcp.delegated-principal` — a Scalpels-relayed caller (console-signed assertion) acting as a `bfc-console:` actor.

## How to get to it (user POV)

- An agent client configured with Capstan's MCP URL `/mcp` or `/mcp/write` and a bearer.
- Locally: a direct `mcp`-purpose credential from `bfc:credential:mint --local` (`bin/mcp-token.sh`).
- In production: Scalpels relays the caller with a signed assertion (`Authorization: Bearer <assertion header>…`).
- There is no third, destructive door. Four write-door tools carry `destructiveHint: true`:
  `cancel_item`, `cancel_task`, `release_seat`, `orchestrate`.

## Driving it with capstan.py

Preconditions: baseline; `"$B/mcp-token.sh"`.

- **List read door** — `"$B/capstan.py" mcp-list read` → 10 names: `list_work get_task list_escalations
  postmaster_spokes postmaster_messages list_pages get_page get_brand_kit list_layouts preview_brand_draft`,
  none marked destructive.
- **List write door** — `"$B/capstan.py" mcp-list write | wc -l` → 28; `| grep -c destructive` → 4.
- **Read tool on read door** — `"$B/capstan.py" mcp read list_layouts` → `isError: false`, four layouts.
- **Write tool on read door is refused** — `"$B/capstan.py" mcp read create_task '{"team_id":1,"idempotency_key":"rd","title":"x","brief":"x"}'`
  → `FAIL: /mcp 400: {… "error":{"code":-32000,"message":"effect_above_ceiling"}}`; side effect:
  `select count(*) from work_tasks` unchanged.
- **Bound token on the MCP door is refused** — put a device-flow `capstan.machine` token in
  `<state>/tokens/mcp` and list → `FAIL: /mcp 401: {"message":"Unauthenticated."}` (bound credentials
  only open their own API audience). Re-run `mcp-token.sh` afterwards.
- **Idempotency** — repeat any write call with the same key → same `structuredContent`, and
  `select count(*) from mcp_write_claims where idempotency_key='<key>'` stays 1.

Not driven: `mcp.delegated-principal`. A relayed assertion must be countersigned by a filed Scalpels
console key; an agent cannot mint one locally, so every MCP drive here is the DIRECT-credential
principal. Say so in any verdict about delegated behaviour, and lean on
`McpDelegatedEnforcement` in the test suite for that path.

## Gotchas

- Neither device-flow purpose opens `/mcp` — both are bound to `/api/v1/*` audiences and
  get 401 there. Use `mcp-token.sh`.
- `bfc:credential:mint` without `--local` runs against a REMOTE Cloud environment. Always `--local`.
- The doors answer JSON or SSE; `capstan.py` handles both. It needs `Accept: application/json, text/event-stream`.
- A durable write that throws inside the coordinator returns `{"outcome":"outcome_unknown"}` with
  `isError: false` and no detail. Treat that as a failure and read `storage/logs/laravel.log` and
  `select state, terminal_response from mcp_write_claims order by created_at desc limit 3`.
