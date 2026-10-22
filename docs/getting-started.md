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

The install sets `CAPSTAN_ARTIFACT_RENDER_ORIGIN` to the environment's own `laravel.cloud` address.
Postmaster and Work are available immediately. Pooled Work starts off and remains an explicit team policy.

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

The check is in `app/Features/Artifacts.php`. Artifact surfaces are available only when
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

## 4. Connect this machine

Open `/postmaster` and use **Connect a local agent**. Generate the installer, copy its one-line
`curl -sSL --fail-with-body '…' | sh` command, and run it on the computer
that will poll Capstan. The command needs curl 7.76 or newer, `php`, and `crontab`. It prints the
verification address and code and opens that address when the operating system supports it. Approve the
code in the browser that generated it. The approving person becomes the machine owner. The new spoke appears
as Pending after its first poll and turns Green after its first probe passes.

The install link works once and expires with the device authorization after ten minutes. Generating another
one invalidates the previous unused link. You can expand **Show full script** before running the command.
The installer writes one `capstan.machine` token and its non-secret actor id under
`~/.config/capstan/<server-id>/`; polling and Work share that identity.

## 5. Connect your agent over MCP

An agent connected to your installation through Scalpels gets 32 Capstan tools. Eleven of them are
read-only. Capstan also serves the same MCP server itself at `/mcp` and `/mcp/write`.

| Area | Read-only tools | Tools that change data |
| --- | --- | --- |
| Work | `list_work`, `get_task`, `list_escalations`, `get_team_work_policy` | `create_task`, `revise_brief`, `queue_item`, `cancel_item`, `steer`, `cancel_task`, `take_seat`, `release_seat`, `answer_escalation`, `orchestrate`, `reassign_task`, `set_team_work_policy` |
| Postmaster | `postmaster_spokes`, `postmaster_messages` | `send_postmaster_message`, `ack_postmaster_messages` |
| Pages and artifacts | `list_pages`, `get_page` | `publish_page`, `update_page`, `create_artifact_share` |
| Brand kit | `get_brand_kit`, `list_layouts`, `preview_brand_draft` | `update_brand_draft`, `upload_brand_asset`, `apply_brand_draft` |

The Pages, artifacts and Brand kit tools stay unavailable until step 3 is done. Postmaster and Work have
no separate product flag.

`list_escalations` and `answer_escalation` are how a person handles a blocked worker without opening
Capstan. Answering one wakes the orchestrator and hands it the answer. The next turn's
context carries an `answered_escalations` list holding the question, the options and the answer text.

## 6. Prepare the installed machine identity for Work

Use the files created in step 4. Set `credential_file` to
`~/.config/capstan/<server-id>/token`. Read the adjacent `actor-id` file locally and copy that numeric value
as the quoted JSON scalar `actor_id` when preparing the drain config. Do not copy the bearer itself into the
config, an environment variable, a command line, or chat. If either installed file is missing, reconnect the
machine from `/postmaster` rather than minting a separate drain credential.

## 7. Drain your first Work item

Make a private directory for the drain config with mode `0700`. Keep using the installer's mode-`0600`
token file; do not duplicate it into the project or config directory.

For the first run use `drain/examples/generic.json`, whose only role, `builder`, runs
`drain/harness/stub_worker.py`. The stub reads its prompt, posts `started` and then `done`, and does
nothing else, so nothing on your machine or in your repositories is at risk. Copy the example into your
private directory and set `capstan_url` to your app's `https://` address, `team` to `1`, `actor_id` to the
JSON scalar copied from the installed `actor-id` file, and `credential_file` to the installed `token` path.
The example's other paths, including the
prompt templates and the stub's command, are relative to the example's own directory, so make them
absolute paths into your checkout. Every key is described in [docs/work.md](work.md#configuration).

Check the config without calling Capstan, then make one authenticated request that claims nothing:

```bash
python3 drain/capstan_drain.py --config /absolute/path/to/config.json --check-config
python3 drain/capstan_drain.py --config /absolute/path/to/config.json --dry-claim
```

A `404` from the dry claim usually means the URL or team is wrong. A `401` means the machine credential,
its binding, or the actor id is wrong. A `403`
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

The drain above runs as you, so it can claim the tasks you create. If your organisation runs its own
machines under a separate service account, that account's drain is never offered your tasks, because
each new task is addressed to the person who created it. Turn on pooled work for the team, either from
the Pooled work section of the `/work` page or by calling `set_team_work_policy` with `team_id` `1`,
`pool_work` `true` and an `idempotency_key`. New tasks can then be claimed by any of the team's machines.
Tasks that already exist keep their address. If you only want to send particular tasks to a machine,
leave the setting off and pass the service account's actor id as `user_actor_address` when you call
`create_task`. Only an Owner or Admin can change the setting. [docs/work.md](work.md#who-can-claim-a-task)
has the details.

For the real loop, change `role_commands` to the harness you use (`drain/examples/claude.json` runs
`claude -p`, `drain/examples/opencode.json` runs `opencode run`) and schedule the script once a minute.
`drain/SKILL.md` gives the cron and launchd forms. [docs/work.md](work.md) covers what each run does,
recovery and limits.

## 8. Publish your first page

With step 3 done, call `publish_page` from your MCP client. It takes
an `idempotency_key`, a `title`, a `recipe` describing how to rebuild the page, a `layout` of `report`,
`dashboard`, `brief` or `plain`, the `content`, and a `content_type` of `text/html` or
`application/xhtml+xml`. A `description` is optional.

The page is private to you until you share it. The response includes its `url` on your app host, and
the HTML itself is served from the render origin inside a sandboxed frame. Call `get_brand_kit` before
building a page if you want it to use your organisation's tokens and components. Layouts and brand CSS
are described in the README's [Page layouts and brand CSS](../README.md#page-layouts-and-brand-css).

If `publish_page` answers `Pages are unavailable.`, go back to step 3.
