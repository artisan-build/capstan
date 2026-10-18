# Artifacts

## Sub-features

- `artifacts.ingest-api` — `POST /api/v1/artifacts` with a `capstan.artifact.ingest` token.
- `artifacts.ingest-mcp` — `create_artifact_share` on the write door.
- `artifacts.share` — `/artifacts/{id}/share?expires&signature` on the APP host: viewer page with a sandboxed iframe.
- `artifacts.content` — `/artifacts/{id}/content?…` on the RENDER host: the stored HTML, cookieless, strict CSP.
- `artifacts.visibility` — `signed_url` (anyone with the link) vs `org_auth` (signed-in members only; the default).
- `artifacts.isolation` — the content URL is 404 on the app host.

## How to get to it (user POV)

- An agent posts HTML and gets back `share_url`; a human opens that link in a browser.
- An MCP client calls `create_artifact_share` and gets `artifact.share_url`.
- There is no artifacts index page; the share link is the only human entry point.

## Driving it with capstan.py

Preconditions: baseline; `"$B/capstan.py" token capstan.artifact.ingest`; `"$B/mcp-token.sh"` for MCP.

- **Ingest, both visibilities** — `"$B/capstan.py" api capstan.artifact.ingest POST /api/v1/artifacts '{"content":"<h1>v</h1><script>document.title=1</script>","content_type":"text/html","visibility":"signed_url"}'`
  → `201`, `artifact.id`, `share_url` on the app host. Repeat with `"org_auth"` → `201`.
- **Ingest over MCP** — `"$B/capstan.py" mcp write create_artifact_share '{"idempotency_key":"a1","content":"<p>x</p>","content_type":"text/html","visibility":"signed_url"}'`
  → `isError: false`, `artifact.share_url`.
- **Share page, anonymous** — `"$B/capstan.py" fetch '<share_url>'` → `200` for `signed_url`,
  `401` for `org_auth`.
- **Share page, signed in** — `"$B/capstan.py" frames '<share path+query>'` →
  `{"src": "http://<render host>/artifacts/<id>/content?expires=…&signature=…", "sandbox": "allow-scripts"}` for both visibilities.
- **Content on the render host, cookieless** — `"$B/capstan.py" fetch '<src>'` → `200`, body is the
  posted HTML, `Set-Cookie: <absent>`, `X-Robots-Tag: noindex, nofollow, noarchive`,
  `Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; font-src 'none'; img-src data:; connect-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors http://<app host>:<port>`.
- **Isolation** — the same path+query on the app host: `"$B/capstan.py" fetch "${SRC/<render host>/<app host>}"` → `404`.
- **Viewer at two viewports** — pass the share path to `"$B/web-script.sh"`: 200, one iframe with
  `renderHost: true`, `sandbox: "allow-scripts"`, `overflowX: false`.

Not driven: `expires_at` expiry, the size cap (`CAPSTAN_ARTIFACT_MAX_CONTENT_BYTES`), other
`content_type`s, the Cloud bucket disk (local lane stores on the `local` disk).

## Gotchas

- **The iframe `src` in the HTML is HTML-escaped.** Fetching it raw sends `&amp;signature=`, which
  breaks the signature and returns 403/401 — your bug, not the product's. `capstan.py frames` unescapes.
- The content signature is minted per page view and is short-lived; fetch it right after `frames`.
- One-host setups make the whole surface look broken (ingest 404 / feature off). Doctor checks the
  two origins.
- The render CSP allows inline script (`script-src 'unsafe-inline'`) by design; isolation is the
  sandbox + cookieless separate origin + `connect-src 'none'`, not sanitization.
