# Getting started with Capstan via Scalpels

This page takes a team that has never run Capstan from a Scalpels install to its first drained Work item
and its first published page. It assumes you are comfortable with Laravel Cloud, a terminal and an MCP
client. The [README](../README.md) is the full reference; this page links into it rather than repeating
it.

## 1. Install through Scalpels

Start at <https://scalpels.app/products/capstan>. Scalpels forks Capstan into your GitHub organisation,
provisions a Postgres database, a managed queue, object storage and the scheduler on your Laravel Cloud
account, and deploys the fork. After each deploy it runs `php artisan migrate --force` and
`php artisan bfc:signing-root:ensure --local`, so the schema and Postmaster's signing key are in place
without any work from you.

The install sets two variables. `CAPSTAN_FEATURE_ARTIFACTS` is `true`, and
`CAPSTAN_ARTIFACT_RENDER_ORIGIN` holds the environment's own `laravel.cloud` address. Everything else
starts at its default, which means Postmaster and Work are off.

## 2. Create the first account

There is no `/register` page and no `/login` page. Sign-in belongs to Built for Cloud, at
`/bfc/login`, and the first account is created from the command line. Clone your fork, then follow the
README's [deploy preflight](../README.md#6-deploy-to-laravel-cloud) so `.cloud/config.json` points at
your application rather than the upstream one. Then create the Owner on the deployed environment:

```bash
php artisan create-admin --environment=<env> --email=you@example.com --name="Your Name"
```

You are prompted for the password, and only its hash is sent. The first account is always the Owner,
whatever the command's output calls it. A default team with id `1` already exists; the migrations seed
it.

Sign in at `https://<your-app-host>/bfc/login`. To invite colleagues, open `/bfc/members` (see
[Inviting the rest of your team](../README.md#inviting-the-rest-of-your-team)).

## 3. Attach a custom domain before you expect artifacts to work

Artifacts, Pages and the Brand kit all need a second hostname. Capstan keeps the rich HTML your agents
produce safe by serving it from a separate origin that never sees your login cookie, not by sanitizing
it, so without a second host there is nowhere safe to serve it from.

The check is in `app/Features/Artifacts.php`. The feature is on only when the flag is on and
`ArtifactRenderOrigin::isConfigured()` finds a render origin whose host differs from `APP_URL`'s. On
Laravel Cloud, `APP_URL` is the environment's `laravel.cloud` address until a custom domain is attached.
That is the same address Scalpels put in `CAPSTAN_ARTIFACT_RENDER_ORIGIN`, so a fresh install cannot pass
the check. Until it does, the MCP tool `list_pages` answers `Pages are unavailable.` and `list_layouts`
answers `Branding is unavailable.`. That looks like a broken feature. It is the expected state of an
install with no custom domain.

Attach your app hostname to the environment and place the DNS records Cloud returns, as in README
[deploy step 5](../README.md#6-deploy-to-laravel-cloud). Once the domain is verified, `APP_URL` follows it
on the next deploy. The render origin stays on the `laravel.cloud` address, the two hosts now differ, and
artifacts, pages and branding start working. If you would rather serve artifacts from a hostname of your
own, attach that one too and set `CAPSTAN_ARTIFACT_RENDER_ORIGIN` to it. Whatever it names, it must be a
different host from `APP_URL`.

Leave `SESSION_DOMAIN` unset. A session cookie shared across both hostnames hands your login to the
artifact origin and undoes the isolation.

## 4. Turn on the features you want

The three feature flags are environment variables read through `config()`. There is no switch for them
in the app.

| Variable | Scalpels default | Turns on |
| --- | --- | --- |
| `CAPSTAN_FEATURE_ARTIFACTS` | `true` | Artifacts, Pages and the Brand kit, once step 3 is done |
| `CAPSTAN_FEATURE_POSTMASTER` | `false` | The signed agent message bus |
| `CAPSTAN_FEATURE_WORK` | `false` | The Work queue and its drain API |

When a feature is off, its routes return `404` and its MCP tools refuse with a message such as
`Work is unavailable.` The rest of Capstan keeps working. For this guide you need Work, so set it on
the environment and redeploy:

```bash
cloud environment:variables --json -n --action=set --key=CAPSTAN_FEATURE_WORK --value=true
cloud deploy -n
```

## 5. Connect your agent over MCP

An agent connected to your installation through Scalpels gets 28 Capstan tools. Ten of them are
read-only. Capstan also serves the same MCP server itself at `/mcp` and `/mcp/write`.

| Area | Read-only tools | Tools that change data |
| --- | --- | --- |
| Work | `list_work`, `get_task`, `list_escalations` | `create_task`, `revise_brief`, `queue_item`, `cancel_item`, `steer`, `cancel_task`, `take_seat`, `release_seat`, `answer_escalation`, `orchestrate` |
| Postmaster | `postmaster_spokes`, `postmaster_messages` | `send_postmaster_message`, `ack_postmaster_messages` |
| Pages and artifacts | `list_pages`, `get_page` | `publish_page`, `update_page`, `create_artifact_share` |
| Brand kit | `get_brand_kit`, `list_layouts`, `preview_brand_draft` | `update_brand_draft`, `upload_brand_asset`, `apply_brand_draft` |

The Pages, artifacts and Brand kit tools stay unavailable until step 3 is done. Each area's tools also
need that area's flag from step 4.

## 6. Mint a drain token with the device flow

A drain authenticates with a `capstan.work.drain` bearer. Get it through the device flow. Do not use
`/settings/credentials/personal` or `/settings/credentials/installation`: both offer to issue a token, but
`POST /api/v1/work/{team}/claim` rejects a token minted there with `401 unauthenticated`. The same
purpose, person and endpoint return `200` with a device-flow token. This was last checked on 2026-10-18
against Built for Cloud v0.20.0, and it applies to all three of Capstan's purposes.

Read your actor id first. The drain sends it in `X-Capstan-Actor-ID`, and it must belong to the person
who approves the token.

```bash
cloud tinker <env> --code='echo ArtisanBuild\BuiltForCloud\User::query()->where("email","you@example.com")->value("id");'
```

Then run the flow. The cookie jar holds a live session for your account, so keep it out of any repository
and let the `trap` delete it.

```bash
CAPSTAN_URL=https://<your-app-host>
JAR=$(mktemp -t capstan-cookies)
trap 'rm -f "$JAR"' EXIT

# 1. Sign in.
CSRF=$(curl -s -c "$JAR" "$CAPSTAN_URL/bfc/login" \
  | grep -o 'name="_token" value="[^"]*"' | cut -d'"' -f4)
curl -s -b "$JAR" -c "$JAR" -X POST "$CAPSTAN_URL/bfc/login" \
  -d "_token=$CSRF" -d "email=you@example.com" -d "password=<your password>"

# 2. Ask for a token. Prints device_code, user_code and verification_uri.
CSRF=$(curl -s -b "$JAR" -c "$JAR" "$CAPSTAN_URL/settings" \
  | grep -o 'name="_token" value="[^"]*"' | cut -d'"' -f4)
curl -s -b "$JAR" -c "$JAR" -X POST "$CAPSTAN_URL/bfc/device-authorizations" \
  -H "Content-Type: application/json" -H "Accept: application/json" -H "X-CSRF-TOKEN: $CSRF" \
  -d '{"app_purpose":"capstan.work.drain","label":"drain on this machine"}'

# 3. Open the verification_uri in a browser signed in as the same person and press Approve.

# 4. Collect the token. Repeat until it stops returning authorization_pending.
curl -s -X POST "$CAPSTAN_URL/bfc/device/token" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"device_code":"<device_code from step 2>"}'

rm -f "$JAR"
```

Step 4 returns `{"access_token":"tok_…","token_type":"Bearer",…}`. The authorization expires ten minutes
after step 2, and a device code can be exchanged once. More detail is in the README's
[Getting a token](../README.md#getting-a-token).

## 7. Drain your first Work item

Make a private directory for the drain, with mode `0700`, and write the `access_token` value alone into
a file in it with mode `0600`. Never put the bearer in the repository, a command line or an environment
variable.

For the first run use `drain/examples/generic.json`, whose only role, `builder`, runs
`drain/harness/stub_worker.py`. The stub reads its prompt, posts `started` and then `done`, and does
nothing else, so nothing on your machine or in your repositories is at risk. Copy the example into your
private directory and set `capstan_url` to your app's `https://` address, `team` to `1`, `actor_id` to the
id you read in step 6, and `credential_file` to the token file. The example's other paths, including the
prompt templates and the stub's command, are relative to the example's own directory, so make them
absolute paths into your checkout. Every key is described in [docs/work.md](work.md#configuration).

Check the config without calling Capstan, then make one authenticated request that claims nothing:

```bash
python3 drain/capstan_drain.py --config /absolute/path/to/config.json --check-config
python3 drain/capstan_drain.py --config /absolute/path/to/config.json --dry-claim
```

A `404` from the dry claim usually means Work is off or the URL or team is wrong. A `401` means the
credential, its purpose or the actor id is wrong, and a token minted from a credentials page gets one
too. A `403`
with `error code: 1010` comes from Cloudflare in front of Laravel Cloud and means the drain sent
Python's default User-Agent. The reference drain sends its own, so this only affects a drain you have
adapted, and it never shows up against localhost.

Now give the drain something to do. From your MCP client, call `create_task` with `team_id` `1`, an
`idempotency_key`, a `title` and a `brief`, then `queue_item` with the same `team_id`, a new `idempotency_key`, the
returned `task_id` and the role `builder`. Run the drain once:

```bash
python3 drain/capstan_drain.py --config /absolute/path/to/config.json
```

It claims the item and starts the stub, which reports `started` and then `done`. `list_work` or the
`/work` page shows the result.

For the real loop, change `role_commands` to the harness you use (`drain/examples/claude.json` runs
`claude -p`, `drain/examples/opencode.json` runs `opencode run`) and schedule the script once a minute.
`drain/SKILL.md` gives the cron and launchd forms. [docs/work.md](work.md) covers what each run does,
recovery and limits.

## 8. Publish your first page

With step 3 done and `CAPSTAN_FEATURE_ARTIFACTS` on, call `publish_page` from your MCP client. It takes
an `idempotency_key`, a `title`, a `recipe` describing how to rebuild the page, a `layout` of `report`,
`dashboard`, `brief` or `plain`, the `content`, and a `content_type` of `text/html` or
`application/xhtml+xml`. A `description` is optional.

The page is private to you until you share it. The response includes its `url` on your app host, and
the HTML itself is served from the render origin inside a sandboxed frame. Call `get_brand_kit` before
building a page if you want it to use your organisation's tokens and components. Layouts and brand CSS
are described in the README's [Page layouts and brand CSS](../README.md#page-layouts-and-brand-css).

If `publish_page` answers `Pages are unavailable.`, go back to step 3.
