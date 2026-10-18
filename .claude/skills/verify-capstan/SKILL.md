---
name: verify-capstan
description: Launch an isolated local Capstan (Laravel, Built for Cloud auth) and drive it the way a user and an agent client do — web UI via Playwright, the bound-credential HTTP APIs, the MCP read/write doors, and the reference Work drain — capturing evidence that survives teardown. Use to reach `live-verified` for any Capstan change to Work, Pages, Artifacts, Brand kit, Postmaster, MCP tools, or the settings/credential surfaces.
---

# verify-capstan

Drives a **local, disposable** Capstan. Production needs Scalpels managed sign-in, which an agent
cannot complete, so every recipe here runs against an instance this run starts and owns.

Read `features/README.md` next: it holds the baseline preconditions, driving conventions and the
feature index. Each `features/<feature>.md` maps every user entry point and says what is NOT driven.

All helpers live in `bin/` and take the same environment:

```sh
export CAPSTAN_VERIFY_WORKTREE=/abs/path/of/YOUR/worktree   # required; the main checkout is refused
export CAPSTAN_VERIFY_PORT=8412                             # default 8412
export CAPSTAN_VERIFY_APP_HOST=app2.localhost               # default app2.localhost
export CAPSTAN_VERIFY_RENDER_HOST=render2.localhost         # default render2.localhost
B="$CAPSTAN_VERIFY_WORKTREE/.claude/skills/verify-capstan/bin"
```

## Launch

**Isolate first.** One port serves one instance; two instances cannot share a port, and Ed often runs
his own local Capstan on **8411** with `app.localhost` / `render.localhost` plus a worktree of his own.
Never touch those. Pick a port nobody holds (`lsof -nP -iTCP:<port> -sTCP:LISTEN` prints nothing) and
a matching host pair (`app2.localhost`/`render2.localhost` on 8412, `app3.`/`render3.` on 8413, …).
Use your OWN worktree (`git worktree add`), with `.env` COPIED from the main checkout, never generated.
Each run gets its own SQLite file at `<worktree>/database/verify.sqlite`.

```sh
"$B/launch.sh"
```

What it does, in order (each step fails loudly):
1. Refuses the main checkout, a held port, or a leftover state dir from an unclean earlier run.
2. Reads back `DB_CONNECTION`/`DB_HOST` from the copied `.env` before any write (a copied `.env` can
   point at production), then writes into the WORKTREE `.env`: `DB_CONNECTION=sqlite`,
   `DB_DATABASE=<worktree>/database/verify.sqlite`, `APP_URL=http://<app host>:<port>`,
   `CAPSTAN_ARTIFACT_RENDER_ORIGIN=http://<render host>:<port>`, and
   `CAPSTAN_FEATURE_{ARTIFACTS,POSTMASTER,WORK}=true`. Exports would be useless: `artisan serve`
   forwards only a whitelist of variables to its `php -S` worker.
3. `composer install`; `npm ci && npm run build` when `public/build/manifest.json` is missing (without
   it every authenticated page 500s "Vite manifest not found").
4. Fresh DB: `touch`, `key:generate --force`; then `migrate --force` and
   `bfc:signing-root:ensure --local`.
5. `php artisan serve --host=127.0.0.1 --port=<port> --no-reload` in the background; pid in the state
   dir. **Ready** when `GET http://<app host>:<port>/up` returns 200 (it waits up to 20 s).
6. First account: `create-admin --local --execute` with a random password. The first account is the
   Owner (user id 1, team 1 is seeded). Credentials go to `<state>/owner.env`, mode 0600.

It prints `READY app=… render=… db=…`, the state dir and the evidence dir.

Paths: state dir `~/Library/Caches/capstan-verify/state-<port>/` (0700: pid, server log, owner creds,
cookie jar, tokens, drain config). Evidence dir `~/Library/Caches/capstan-verify/evidence/<run-id>/`.
Both are outside every repo.

Two hosts are not optional. Artifacts, Pages and Brand need the flag AND a render origin whose host
differs from `APP_URL`'s; with one host, `list_pages` answers "Pages are unavailable." and
`list_layouts` "Branding is unavailable." `*.localhost` resolves to 127.0.0.1 on macOS. Do not use
`127.0.0.2`: it is not bindable on macOS without `sudo ifconfig lo0 alias`.

There is no `/register` and no `/login` (both 404). Sign-in is `/bfc/login`.

## Doctor

Run it first, and again whenever anything surprises you. Read-only apart from one anonymous session row.

```sh
"$B/doctor.sh"      # exits non-zero on the first failure; writes <evidence>/doctor.txt
```

It proves: the recorded server pid is alive; every listener on the port is in THAT pid's process tree;
`/up` is 200; `route:list` resolves; **database identity behaviourally** (an anonymous
`GET /bfc/login` adds a `sessions` row to THIS run's SQLite file — if not, the server is on some
other database and nothing you drive means anything); both origins and all three flags are in the
worktree `.env`; the Vite manifest exists; and which credential NAMES (never values) are set in the
worktree `.env` and in your shell. It does not use `ps eww`: on macOS that cannot read another
process's environment, so such a check can never fail.

Nothing in the default lane reaches a paid third party. If you change the `.env` mail or AI settings,
re-read the credential-names line before driving anything that sends.

## Drive

Three harnesses, one per surface. Feature files give the exact calls.

**HTTP + MCP — `bin/capstan.py`** (Python stdlib only; run with no args for usage):

```sh
"$B/capstan.py" token capstan.work.drain        # device flow → <state>/tokens/<purpose> (0600); prints path
"$B/capstan.py" token capstan.artifact.ingest
"$B/capstan.py" token capstan.postmaster.poll
"$B/mcp-token.sh"                               # unbound `mcp` bearer → <state>/tokens/mcp
"$B/capstan.py" mcp-list read                   # 10 tools
"$B/capstan.py" mcp-list write                  # 28 tools; marks destructiveHint ones
"$B/capstan.py" mcp write publish_page '{"idempotency_key":"k1",...}'
"$B/capstan.py" api capstan.artifact.ingest POST /api/v1/artifacts '{...}'
"$B/capstan.py" get /pages                      # as the owner (session cookie reused)
"$B/capstan.py" frames /pages/<id>              # iframe src (UNESCAPED) + sandbox
"$B/capstan.py" fetch '<url>'                   # cookieless; prints CSP / Set-Cookie / X-Robots-Tag
```

The device flow it automates: `GET /bfc/login` → POST credentials; CSRF from `/settings`;
`POST /bfc/device-authorizations {"app_purpose": …}` → 201 `device_code`/`user_code`;
`GET /bfc/device` with NO query string (`?user_code=` 404s "This authorization is unavailable."),
submit its `action=approve` form; `POST /bfc/device/token {"device_code": …}` → 200 `access_token`.

**Web UI — Playwright MCP tools** (their own browser; never Claude-in-Chrome, which is Ed's Chrome):

```sh
"$B/web-script.sh" [/pages/<id>] ['/artifacts/<id>/share?expires=…&signature=…']   # prints a .js path
```

The Playwright MCP refuses `filename=` outside its allowed roots (the main checkout), so Read the
printed file and pass its whole content as `code` to `browser_run_code_unsafe`. It signs in, visits
`/work /pages /branding /postmaster /dashboard /settings /settings/credentials/installation
/settings/credentials/personal` plus your extra paths at 1280×800 and 390×844, and returns per page:
HTTP status, the surface's `data-testid` count, iframe host + sandbox, and `overflowX`
(`scrollWidth > innerWidth`). Full-page screenshots land in `<evidence>/web/<vp>-<slug>.png`. The
generated file embeds the run's disposable owner password; it lives in the 0700 state dir and dies
with cleanup.

**Work drain — the repo's own reference drain**:

```sh
C=$("$B/drain-config.sh")                       # private config in <state>/drain, stub worker, role builder
python3 "$CAPSTAN_VERIFY_WORKTREE/drain/capstan_drain.py" --config "$C" --check-config
python3 "$CAPSTAN_VERIFY_WORKTREE/drain/capstan_drain.py" --config "$C" --dry-claim
python3 "$CAPSTAN_VERIFY_WORKTREE/drain/harness/run_loop.py" --config "$C" --iterations 6 --interval 1
```

## Evidence

Everything goes under `~/Library/Caches/capstan-verify/evidence/<run-id>/` (printed by launch;
`doctor.txt`, `web/*.png` and `server.log` land there automatically). Save every drive's output there
too, e.g. `"$B/capstan.py" … | tee "$EVIDENCE/<feature>-<step>.txt"`. Cleanup never deletes it.

Proof standards:
- **Real user path.** Web UI through the browser, agent clients through MCP or the bound API with a
  device-flow token, the drain through `capstan_drain.py`. No tinker, no factories, no internal setters.
- **Action AND resulting state.** Pair each call's response with the read that shows the change
  (`list_work` groups, `/work` markers, `get_page` versions).
- **Side effects.** Check the row in `database/verify.sqlite` (`sqlite3 … "select …"`): transitions in
  `work_item_transitions`, milestones in `work_milestones`, `messages.status`, `brand_versions` count.
- **Structure, not copy.** Assert `data-testid` markers and JSON keys. Never assert template text, and
  never leave a step that asserts a block's absence in the map.
- **Screenshots over computed style**, at both viewports, plus `overflowX`. `elementFromPoint` is not
  paint: it cannot prove an occlusion fix.
- **Origin isolation is proven by three observations together**: share page on the app host holds an
  `allow-scripts` iframe whose src is on the render host; that src fetched cookieless on the render
  host is 200 with the strict CSP and no `Set-Cookie`; the same path and query on the app host is 404.
- **Substrate.** This lane runs SQLite, the `database` queue and `local` disk. Production runs
  Postgres and the Cloud bucket. A green local drive says nothing about Postgres-only SQL or S3 paths;
  name that gap in your verdict.
- **Nothing plays audio.** No surface here does; keep it that way.
- Stamp the verdict `live-verified (verify-capstan, local SQLite lane)` and list which entry points of
  the feature file you drove. A drive of one entry point when the map lists three covers one.
- No mutation step. This skill reaches up the ladder by driving the real surface; it never asks for
  mutation testing.

## Cleanup

```sh
"$B/cleanup.sh"              # kill the server tree, copy server.log to evidence, rm the state dir
"$B/cleanup.sh" --purge-db   # also delete <worktree>/database/verify.sqlite
```

It snapshots the server pid's whole descendant tree before killing (`php -S` workers reparent to init
when their parent dies and keep the port, so the NEXT run gets answered by THIS run's app), escalates
to `kill -9` after 5 s, deletes the state dir (owner password, cookies, tokens, drain config), keeps
evidence, and **exits non-zero if the port is still held**. It never kills by process name. Then
confirm `ls "$EVIDENCE"` still lists your files. Run it after every failed attempt too.

Not undone by cleanup: the worktree `.env` edits (re-copy from the main checkout if you need the
original), and the Playwright MCP's own console logs, which it writes to `.playwright-mcp/` under its
allowed root (often the MAIN checkout). After a web pass, `git -C <that root> status --short`; remove
the `.playwright-mcp/` it created so the main checkout stays untouched. Close the browser
(`browser_close`) so no session outlives the run.
