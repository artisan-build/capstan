# Pages

## Sub-features

- `pages.publish` — `publish_page`: a private page with immutable version 1, a refresh `recipe`, one of four layouts.
- `pages.layouts` — `list_layouts`: `plain`, `report`, `dashboard`, `brief` plus the `capstan-*` component classes.
- `pages.update` — `update_page`: appends a new version.
- `pages.list-get` — `list_pages`, `get_page` (with versions).
- `pages.library` — `/pages`: my pages and pages shared with me.
- `pages.viewer` — `/pages/{id}` and `/pages/{id}/versions/{version}`: sandboxed iframe of the render-host content.
- `pages.share` — `POST /pages/{id}/shares`, public `/page-shares/{share}` on the render host.
- `pages.export` / `pages.delete` — `/pages/{id}/export`, `DELETE /pages/{id}`.

## How to get to it (user POV)

- Agent: MCP `publish_page`, `update_page`, `list_pages`, `get_page`, `list_layouts` (write door has all;
  read door has `list_pages`, `get_page`, `list_layouts`).
- Web: sidebar `pages-navigation` → `/pages` (`pages-library`, `pages-my-section`/`pages-my-row`,
  `pages-shared-section`/`pages-shared-row`/`pages-shared-empty`) → a row → `/pages/{id}`
  (`page-viewer`, `page-owner-metadata`, `page-refresh-recipe`, `page-version-history`/`page-version-record`,
  `page-sharing`/`page-shares`, `page-export`, `page-delete`).
- Anyone holding a page-share link: `/page-shares/{share}` on the render host.

## Driving it with capstan.py and Playwright

Preconditions: baseline; `"$B/mcp-token.sh"`.

- **Layouts** — `"$B/capstan.py" mcp read list_layouts` → `layouts[].name` = plain, report, dashboard, brief.
- **Publish in each layout** — for L in plain report dashboard brief:
  `"$B/capstan.py" mcp write publish_page '{"idempotency_key":"p-L","title":"Verify L","recipe":"regenerate by hand","layout":"L","content":"<h1>v</h1><p class=capstan-card>x</p>","content_type":"text/html"}'`
  → `page.id`, `page.layout: "L"`, `latest_version.number: 1`.
- **Update** — `… mcp write update_page '{"idempotency_key":"u1","page_id":"P","content":"<h1>v2</h1>","content_type":"text/html"}'`
  → `page.latest_version.number: 2`.
- **List** — `… mcp read list_pages` → `structuredContent.items[]` with `title`, `layout`, `version_count`.
- **Get** — `… mcp read get_page '{"page_id":"P"}'` → `structuredContent.page`.
- **Library + viewer markup** — `"$B/capstan.py" get /pages | grep -c 'data-testid="pages-my-row"'` → number
  of pages published; `get /pages/P` has `page-viewer` and two `page-version-record` after the update.
- **Rendered content on the render host** — `SRC=$("$B/capstan.py" frames /pages/P | python3 -c 'import json,sys;print(json.loads(sys.stdin.readline())["src"])')`;
  `"$B/capstan.py" fetch "$SRC"` → `200`, body starts with `<style data-capstan-brand="applied">` and
  ends with the v2 HTML, strict CSP, no `Set-Cookie`. Same URL on the app host → `404`.
- **Viewer at two viewports** — `"$B/web-script.sh" /pages/P` → `/pages` and `/pages/P` 200,
  `page-viewer` 1, iframe `renderHost: true`, `overflowX: false` at 1280 and 390.

Not driven: page shares (`POST /pages/{id}/shares`, `/page-shares/*`), export, delete, version pages
`/pages/{id}/versions/{v}`, `pages-shared-row` (needs a second user), layout visual differences.

## Gotchas

- Pages need the Artifacts flag AND two origins; with one host, `list_pages` returns "Pages are unavailable."
- `update_page` requires `content` and `content_type` every time, even for a title change.
- The viewer's iframe `src` is HTML-escaped and short-lived; use `capstan.py frames` and fetch at once.
- `list_pages` returns `items`, not `pages`.
