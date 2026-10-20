# Settings and credentials

## Sub-features

- `settings.signin` — `/bfc/login` (no `/login`, no `/register`), sign-out via `/settings/logout` or the user menu.
- `settings.home` — `/settings` (Built for Cloud UI shell, nav to credentials, members, sessions, transitions).
- `settings.personal-credentials` — `/settings/credentials/personal`: issue/rotate/revoke bound bearers for the two purposes.
- `settings.installation-credentials` — `/settings/credentials/installation`.
- `settings.device-flow` — `/bfc/device-authorizations` → `/bfc/device` approve → `/bfc/device/token`.
- `settings.dashboard` — `/dashboard`, the post-login landing page with app navigation.

## How to get to it (user POV)

- Browser: `/bfc/login` → lands on `/dashboard` (`work-navigation`, `pages-navigation`,
  `branding-navigation`, `account-navigation`, `desktop-settings-navigation`/`mobile-settings-navigation`,
  `sidebar-menu-button`, `logout-button`).
- User menu `bfc-user-menu-button` → `bfc-user-menu` → settings / `bfc-user-menu-logout`.
- `/settings` (`ui-shell`, `ui-navigation`, `ui-nav-personal-credentials`, `ui-nav-installation-credentials`,
  `ui-nav-member-management`, `ui-nav-session-management`, `ui-nav-managed-transitions`, `ui-logout-form`).
- `/settings/credentials/personal` (`personal-credentials`, `personal-credentials-issue`,
  `personal-credentials-issue-option` ×2 — one form per purpose, `personal-credentials-list`/`-item`
  with rotate and DELETE forms).
- `/settings/credentials/installation` (`installation-credentials`, `-issue`, `-issue-option`, `-list`, `-panel`).
- CLI/agent: the device flow, approved by a signed-in human at `/bfc/device`.

## Driving it with capstan.py and Playwright

Preconditions: baseline.

- **Sign in** — `"$B/capstan.py" get /settings` → `200 http://<app host>/settings` (it signs in once,
  then reuses `<state>/owner-cookies.txt`). Raw: `POST /bfc/login` → 302 to `/dashboard`.
- **Absent routes** — `curl -s -o /dev/null -w '%{http_code}' "$U/login"` / `/register` / `/bfc/ui` → 404 each.
- **Device flow, each purpose** — `"$B/capstan.py" token capstan.machine` and
  `"$B/capstan.py" token capstan.artifact.ingest` → each prints `<state>/tokens/<purpose>`, mode 0600.
  Side effect: `select purpose, user_id from credentials` gains one row per purpose for user 1, and
  `credential_protocol_bindings` binds each credential to its API audience.
- **Token works where bound** — the shared machine token drives the drain's `--dry-claim` and the
  Postmaster poll in `work.md` and `postmaster.md`; the artifact token is exercised in `artifacts.md`.
- **Personal credentials page** — `"$B/capstan.py" get /settings/credentials/personal | grep -c 'data-testid="personal-credentials-issue-option"'`
  → 2; device-flow credentials appear as `personal-credentials-item` rows.
- **Pages at two viewports** — `"$B/web-script.sh"` pass: `/settings` (`ui-shell`),
  `/settings/credentials/installation`, `/settings/credentials/personal`, `/dashboard` (`work-navigation`)
  all 200, marker 1, `overflowX: false` at 1280 and 390.

Not driven: issuing/rotating/revoking from the credentials pages, member invites, session management,
managed transitions, sign-out, the deny path of `/bfc/device`.

## Gotchas

- **`/bfc/ui` does not exist** (0 routes). Take the CSRF token for `POST /bfc/device-authorizations` from `/settings`.
- **`GET /bfc/device?user_code=…` 404s** "This authorization is unavailable." Open it with no query
  string; it prefills from the session.
- **`/bfc/login` is rate limited**: about five sign-ins a minute, then 429. Reuse the session
  (`capstan.py` does); don't sign in per request.
- **A token issued from the credentials PAGE is rejected 401** by `/api/v1/work/*` (and the other bound
  APIs here). Only the device flow yields a token they accept.
- Credential pages show each secret once; never screenshot a freshly issued one.
- `create-admin`, `bfc:credential:mint`, `bfc:signing-root:*` all need `--local`, or they run against Cloud.
