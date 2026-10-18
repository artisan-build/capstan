#!/usr/bin/env bash
# Generate the Playwright web pass for THIS run and print its path. Run the file with the Playwright
# MCP tool (its own browser, never Ed's Chrome):
#   browser_run_code_unsafe  filename=<printed path>
# The Playwright MCP sandbox has no require/fs, so run values (URL, owner password, evidence dir) are
# baked into the generated file. It lives in the 0700 state dir and dies with cleanup.
# Optional args: extra app paths to check (e.g. a /pages/<id> or an artifact share path+query).
source "$(dirname "$0")/_env.sh"
# shellcheck disable=SC1091
source "${STATE}/owner.env"
umask 077
mkdir -p "${EVIDENCE}/web"
out="${STATE}/web-pass.js"
extra="$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1:]))' "$@")"
cat > "${out}" <<JS
async (page) => {
  const U = '${U}';
  const RENDER_HOST = '${RENDER_HOST}';
  const SHOTS = '${EVIDENCE}/web';
  // Structural marker each surface must render (data-testid, never copy).
  const surfaces = [
    ['/work', 'work-board'],
    ['/pages', 'pages-library'],
    ['/branding', 'branding-settings'],
    ['/postmaster', 'postmaster-onboarding'],
    ['/dashboard', 'work-navigation'],
    ['/settings', 'ui-shell'],
    ['/settings/credentials/installation', 'installation-credentials'],
    ['/settings/credentials/personal', 'personal-credentials'],
    ...${extra}.map((p) => [p, p.startsWith('/pages/') ? 'page-viewer' : null]),
  ];
  await page.goto(U + '/bfc/login');
  // A live session from an earlier pass redirects away from the login form; only sign in when shown.
  if (new URL(page.url()).pathname.startsWith('/bfc/login')) {
    await page.locator('input[name="email"]').fill('${OWNER_EMAIL}');
    await page.locator('input[name="password"]').fill('${OWNER_PASSWORD}');
    await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/bfc/login')), page.locator('form button[type="submit"]').first().click()]);
  }
  const results = [];
  for (const [w, h, vp] of [[1280, 800, 'desktop'], [390, 844, 'mobile']]) {
    await page.setViewportSize({ width: w, height: h });
    for (const [path, marker] of surfaces) {
      const resp = await page.goto(U + path);
      const slug = path.split('?')[0].replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'root';
      const r = { vp, path: path.split('?')[0], status: resp ? resp.status() : null };
      if (marker) r.marker = { [marker]: await page.locator('[data-testid="' + marker + '"]').count() };
      const frames = await page.locator('iframe').evaluateAll((els) => els.map((e) => ({ host: new URL(e.src).host, sandbox: e.getAttribute('sandbox') })));
      if (frames.length) r.frames = frames.map((f) => ({ ...f, renderHost: f.host.startsWith(RENDER_HOST) }));
      r.overflowX = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
      await page.screenshot({ path: SHOTS + '/' + vp + '-' + slug + '.png', fullPage: true });
      results.push(r);
    }
  }
  return results;
}
JS
echo "${out}"
