# Brand kit

## Sub-features

- `brand.get` — `get_brand_kit`: defaults, current, draft, versions.
- `brand.draft` — `update_brand_draft` (Owner/Admin): merge partial values into the installation draft.
- `brand.preview` — `preview_brand_draft`: signed render-host preview of the draft in all four layouts.
- `brand.assets` — `upload_brand_asset`, `POST /branding/assets`.
- `brand.apply` — `apply_brand_draft`, `POST /branding/apply`: makes the draft the live brand.
- `brand.revert` — `POST /branding/versions/{version}/revert`.
- `brand.page` — `/branding` settings page.

## How to get to it (user POV)

- Web: sidebar `branding-navigation` → `/branding` (`branding-settings`, `branding-current`,
  `branding-draft-form` with `branding-colours-light`/`-dark`, `branding-fonts`, `branding-spacing`,
  `branding-radius`, `branding-assets`/`branding-asset-selections`, `branding-preview` iframe,
  `branding-apply`, `branding-history`/`branding-history-row`/`branding-history-empty`, `branding-status`,
  `branding-errors`).
- Agent: MCP `get_brand_kit`, `preview_brand_draft`, `list_layouts` (both doors); `update_brand_draft`,
  `upload_brand_asset`, `apply_brand_draft` (write door).

## Driving it with capstan.py and Playwright

Preconditions: baseline; `"$B/mcp-token.sh"`.

- **Read the kit** — `"$B/capstan.py" mcp read get_brand_kit` → keys `defaults current draft current_version versions …`.
- **Change the draft** — `"$B/capstan.py" mcp write update_brand_draft '{"idempotency_key":"b1","changes":{"display_name":"Verify Brand","colours":{"light":{"primary":"#123456"}}}}'`
  → `draft.display_name: "Verify Brand"`, `draft.colours.light.primary: "#123456"`.
- **Preview** — `"$B/capstan.py" mcp read preview_brand_draft` → `draft_fingerprint`, `preview_url` on
  the render host, `layouts` = four. `"$B/capstan.py" fetch '<preview_url>'` → `200`, strict CSP,
  no `Set-Cookie`, and the body contains `#123456`.
- **Draft not applied** — `sqlite3 … "select count(*) from brand_versions"` → unchanged (0 on a fresh DB).
- **Settings page** — `"$B/web-script.sh"` pass after the draft change: `/branding` 200,
  `branding-settings` 1, one iframe on the render host with `sandbox: "allow-scripts"`,
  `overflowX: false` at both viewports. On a fresh DB with no draft there is no preview iframe.

Not driven, deliberately: `apply_brand_draft` / `POST /branding/apply` and revert. The standing order
for this skill is to never call them — on a shared or production instance they change the live brand
everyone sees. Also not driven: asset upload, the `/branding` draft form submit (`PATCH /branding/draft`).

## Gotchas

- `update_brand_draft` is Owner/Admin only; a member's call fails.
- The preview URL expires in 300 s (`expires_in_seconds`); fetch it promptly.
- Brand CSS is injected into every page render (`<style data-capstan-brand="applied">`), so a draft
  change must NOT show up in `/pages` content until applied; the draft only shows in the preview.
