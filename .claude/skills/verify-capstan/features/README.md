# Capstan feature map

## Baseline preconditions

Every recipe assumes, in this order:
1. `bin/launch.sh` printed `READY` and `bin/doctor.sh` printed `DOCTOR PASS` for THIS run's port.
2. `B` and the `CAPSTAN_VERIFY_*` variables from `SKILL.md` are exported in your shell.
3. Tokens you need are minted: `"$B/capstan.py" token <purpose>` for the bound APIs,
   `"$B/mcp-token.sh"` for the MCP doors.
4. The owner is user id 1 on team 1 (`source <state>/owner.env` gives `OWNER_ID`).
5. The server id (for Postmaster addresses) is
   `sqlite3 "$CAPSTAN_VERIFY_WORKTREE/database/verify.sqlite" "select server_id from server_identity"`.

## Driving conventions

- MCP write tools take an `idempotency_key`. Use a fresh one per intended effect; re-using a key
  replays the stored first answer, which looks like success and proves nothing new.
- MCP results: read `result.structuredContent`; a tool-level failure is `result.isError: true` with
  the reason in `result.content[0].text`. A door refusal is an HTTP error (`capstan.py` prints
  `FAIL: /mcp <status>: …`).
- Bound APIs need the matching device-flow token AND `X-Capstan-Actor-ID: <owner id>`; `capstan.py api`
  adds both.
- Web pages: assert `data-testid` markers, never copy. Signed URLs inside HTML are HTML-escaped; read
  them with `capstan.py frames` (it unescapes) or `html.unescape`.
- Save outputs under the evidence dir as you go (`| tee "$EVIDENCE/<feature>-<step>.txt"`).
- Never call `apply_brand_draft` or `POST /branding/apply` (see `brand-kit.md`).
- Never run the Postmaster onboarding installer script (it rewrites the user's crontab).

## Index

| Feature | File | Primary surfaces |
| --- | --- | --- |
| Work | [work.md](work.md) | `/work`, MCP Work tools, `/api/v1/work/{team}/*`, reference drain |
| Delegated MCP | [delegated-mcp.md](delegated-mcp.md) | `/mcp` (read door), `/mcp/write` (write door) |
| Artifacts | [artifacts.md](artifacts.md) | `POST /api/v1/artifacts`, `create_artifact_share`, share + render content |
| Pages | [pages.md](pages.md) | `publish_page`/`update_page`/`list_pages`/`get_page`, `/pages`, render content |
| Brand kit | [brand-kit.md](brand-kit.md) | `get_brand_kit`/`update_brand_draft`/`preview_brand_draft`, `/branding` |
| Postmaster | [postmaster.md](postmaster.md) | `POST /api/v1/poll`, MCP postmaster tools, `/postmaster` |
| Settings and credentials | [settings-and-credentials.md](settings-and-credentials.md) | `/settings*`, `/dashboard`, device flow |
