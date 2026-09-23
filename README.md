<p align="center">
  <img src="art/icon.png" alt="capstan icon" width="128">
</p>

# Capstan

Capstan is a Laravel application your organisation forks, deploys to its own infrastructure, and runs
for itself. It is not a SaaS product and it is not multi-tenant: one deployment belongs to one
organisation, and you own the server, the database, and the data.

## What it does

Capstan ships two capabilities today. Each one is off until you switch it on.

**Artifact hosting.** Your tools `POST` a page of HTML to Capstan and get back a signed, expiring link
they can share. The HTML is stored privately and served from a second hostname that never receives
your login cookie, inside a sandboxed iframe under a strict content security policy. That is how
untrusted AI-generated HTML is made safe here — by isolating it, not by stripping tags out of it.

**Postmaster.** A signed message bus. Command-line agents on your machines poll Capstan once a minute,
announce which inboxes they are ready to receive on, and hand work to each other through addresses
like `build-agent@<server-id>`. Capstan signs every message it delivers and tracks which agents are
alive.

Together they replace the per-seat link-sharing and agent-messaging tools you would otherwise pay for
per person.

## The easy way: Scalpels

[Scalpels](https://scalpels.app/products/capstan) does the setup for you: it forks Capstan, provisions
what it needs on your Laravel Cloud account, and deploys it. The repository and the infrastructure end
up yours either way — the difference is who spends the afternoon on the steps below.

Running it yourself is free of licence cost; Capstan is MIT licensed. You still pay for the
infrastructure it runs on, and you do the provisioning, deployment and upgrade work described here.

Scalpels: **<https://scalpels.app/products/capstan>**

## Run it yourself

### What you need

| | |
| --- | --- |
| PHP | **8.5 or a later 8.x release.** `composer.json` requires `^8.5`, which excludes PHP 9. |
| Composer | 2.x |
| Node.js | 20.19+ or 22.12+ (required by Vite 8) |
| A database | SQLite for local development; PostgreSQL or MySQL in production |
| For deploying | A [Laravel Cloud](https://laravel.com/cloud) account, the `cloud` CLI, and one or two hostnames you control |

Install and authenticate the Laravel Cloud CLI before the deploy section:

```bash
composer global require laravel/cloud-cli
cloud auth          # opens a browser; writes a token to ~/.config/cloud/config.json
```

**If you want artifact hosting you need a *second* hostname.** Capstan serves the app on one and
artifact HTML on another. Using one hostname for both is not a shortcut: Capstan refuses to serve
artifacts at all rather than give up the separation. Postmaster on its own needs only the app
hostname. Locally you do not need DNS for this — see step 5.

### 1. Fork it, then install it

**Fork <https://github.com/artisan-build/capstan> into your own GitHub organisation first**, and clone
the fork. This is not optional if you intend to deploy: Laravel Cloud builds your application from
whatever `origin` points at, so cloning the upstream repository directly would deploy *our* code from
*our* repository and leave you unable to push a change to it.

```bash
git clone https://github.com/<your-org>/capstan.git
cd capstan
git remote get-url origin     # must print YOUR organisation, not artisan-build
composer setup
```

`composer setup` is the whole local install: it installs the PHP packages, copies `.env.example` to
`.env` if you do not have one, generates an app key, creates a SQLite database and migrates it, then
installs and builds the frontend.

**Run it on a fresh clone only.** It keeps an existing `.env` file, but it always regenerates
`APP_KEY` — so running it in an installation you already use invalidates that installation's
encrypted values and signs everyone out. On an existing checkout run the pieces you need by hand
(`composer install`, `php artisan migrate`, `npm install && npm run build`) and leave `key:generate`
alone.

The migration step prints a long list of tables ending in `DONE`. Most of them belong to Built for
Cloud, the package that provides Capstan's accounts, sign-in and API credentials.

If PHP is too old, it stops with `Root composer.json requires php ^8.5 but your php version (...) does
not satisfy that requirement`. Install PHP 8.5 and try again.

### 2. Run the local checks

```bash
composer ready
```

This formats the code with Pint, type-checks it with PHPStan, then runs the Pest suite. A clean
checkout finishes with every tool reporting `passed` and no PHPStan errors.

`composer ready` is your local preflight, not the full CI gate. CI additionally runs `composer audit`,
checks Pint in `--test` mode (which fails instead of rewriting), migrates a fresh database, and runs
the whole suite a second time against **PostgreSQL**. And the suite deliberately swaps in an in-memory
SQLite database plus `array` cache, `array` session, `sync` queue and `array` mail, so a green run says
nothing about the drivers your deployment actually uses. Check user-visible behaviour in the running
app as well.

### 3. Create the first account

Nobody can sign in yet, and there is no public sign-up page. Create the first account from the command
line:

```bash
php artisan create-admin --local --email=you@example.com --name="Your Name"
```

It asks for a password twice, then prints `Admin user you@example.com created.` Ignore the wording —
the *first* account is always the **Owner**, the one account that can promote and remove admins.
Afterwards the command refuses to run again (`An Owner already exists`) unless you add `--force`, which
creates an Admin.

> ### What `--local` does, command by command
>
> Some Built for Cloud commands can act on your **deployed Laravel Cloud environment** instead of your
> machine. They behave differently, so it is worth knowing which is which:
>
> - **`bfc:credential:*` and `bfc:signing-root:provision` refuse to run without `--local`.** They print
>   an error and exit. You cannot reach production by forgetting the flag.
> - **`create-admin` asks.** With `--local` it acts locally, with `--environment=<env>` it acts on that
>   Cloud environment, and with neither it shows a chooser that defaults to this machine.
> - **`bfc:ownership:mint-claim` and `bfc:ownership:remint-owner-token` do not ask.** Without `--local`
>   they go straight to the Cloud application this checkout is bound to, picking the environment
>   automatically when there is only one.
>
> The habit that keeps you safe is the same either way: **pass `--local` unless you mean production.**

### 4. Start the app

```bash
php artisan dev
```

This runs the web server, the queue worker, the Vite dev server and a log tail together, and prints
`Server running on [http://127.0.0.1:8000]`. Open <http://localhost:8000>, sign in with the account
from step 3, and you land on the dashboard.

Keep this terminal visible. With the default `MAIL_MAILER=log`, mail Capstan sends — invitations in
particular — is written to the log rather than delivered, and the `logs` pane is where you read it.

### 5. Turn on the capability you want

Both capabilities are off in a fresh `.env`. Each is one boolean.

**Artifacts** need the flag plus a second hostname. Locally, use a `.localhost` subdomain: browsers and
macOS resolve anything under `.localhost` to `127.0.0.1`, so this needs no DNS and no `/etc/hosts`
edit. Both names reach the same dev server; Capstan tells them apart by the `Host` header.

```dotenv
APP_URL=http://localhost:8000
CAPSTAN_FEATURE_ARTIFACTS=true
CAPSTAN_ARTIFACT_RENDER_ORIGIN=http://artifacts.localhost:8000
```

`.env.example` ships a placeholder render origin. Replace it — do not leave it.

**Postmaster** needs the flag plus the key Capstan signs messages with:

```dotenv
CAPSTAN_FEATURE_POSTMASTER=true
```

```bash
php artisan bfc:signing-root:provision --local
```

That prints `Provisioned installation signing root <id>. No secret was exported.` The key never leaves
the server and cannot be read back, which is the point. Without it Capstan refuses to deliver messages.

Restart the app after changing `.env`. A **Postmaster** link appears in the sidebar once that flag is on.

### 6. Deploy to Laravel Cloud

> #### Preflight: this clone is already bound to someone else's application
>
> `.cloud/config.json` is committed, and in a fresh clone it holds the **upstream** organisation and
> application IDs. The `cloud` CLI and Capstan's own Cloud-capable artisan commands both read it. If
> you deploy or run one of those commands before rebinding, you are aiming at the upstream
> application, not yours.
>
> ```bash
> cat .cloud/config.json     # whose application is this?
> ```
>
> The binding stays in place through `cloud ship` — `ship` prints the ids it creates but does not write
> this file. **`cloud repo:config`, immediately after, is the command that replaces it.** Until you have
> run that and checked the file, run no other Cloud command from this directory.

The `cloud` CLI is pre-1.0 and its flags move between releases. Confirm any command below with
`cloud <command> -h` before running it.

1. **Create the application and database.**

   ```bash
   cloud ship
   ```

   Answer the prompts: region, application name, this repository, and **let it create and attach a
   PostgreSQL database** — that path attaches reliably, and attaching by CLI flag afterwards does not
   work. Enable the scheduler when it offers: Postmaster's liveness sweep runs every minute.

   `ship` takes the application's repository from this checkout's `origin`, which is why step 1 had you
   clone your fork. Then rebind the local config, because `ship` does not:

   ```bash
   cloud repo:config           # THIS is what rewrites .cloud/config.json
   cat .cloud/config.json      # required checkpoint: the IDs must now be yours
   ```

2. **Create the object storage bucket** (artifact hosting only). Every flag here is required:

   ```bash
   cloud bucket:create --name capstan-production --region <your-app-region> \
     --visibility private --key-name capstan-production --key-permission read_write \
     --allowed-origins "https://<app-host>,https://<render-host>" -n --json
   ```

   `--visibility private` matters: artifacts are served by the app, never from a public bucket URL.
   **Then attach it in the Laravel Cloud dashboard** (Environment → Storage → attach). There is no CLI
   flag for attaching a bucket.

3. **Create the managed queue.**

   ```bash
   cloud managed-queue:create <env> --name capstan --size <size> -n --json
   ```

   Note the `id` in that response — `set-default` takes the **queue instance id** as its only
   argument, not the environment and not the name:

   ```bash
   cloud managed-queue:set-default <instance-id> -n
   ```

   Use a managed queue rather than a worker instance.

4. **Set your environment variables — and only yours.**

   > ⚠️ **Never set an environment variable for something Laravel Cloud provisioned for you.** When
   > you attach a database, cache, queue or bucket, Cloud writes all of its settings — the passwords
   > *and* the connection names like `DB_CONNECTION`, `QUEUE_CONNECTION`, `CACHE_STORE`,
   > `SESSION_DRIVER`, `FILESYSTEM_DISK` and the `AWS_*` keys — into a managed file the app reads. If
   > you set any of them yourself, your value silently replaces Cloud's and the resource stops
   > working. Provision, attach, deploy, and let Cloud fill them in.

   ```bash
   cloud environment:variables --json -n --action=set --key=APP_ENV   --value=production
   cloud environment:variables --json -n --action=set --key=APP_DEBUG --value=false
   cloud environment:variables --json -n --action=set --key=APP_NAME  --value=Capstan
   cloud environment:variables --json -n --action=set --key=APP_URL   --value=https://<app-host>
   # plus the CAPSTAN_* keys from the Configuration table
   ```

   Let Cloud generate `APP_KEY`.

   **Set `APP_URL` yourself, to your app hostname, exactly as above.** It is app configuration, not a
   provisioned resource, so the rule above does not cover it. Get it wrong and both capabilities break
   quietly while the deployment stays green: the artifact policy names the wrong page as allowed to
   frame artifacts, and Postmaster hands agents a `http://localhost` poll address. Cloud may also
   derive this value from the environment's *primary* domain — which is a separate step from creating
   a domain — so confirm the final value in step 7 whatever you do here.

5. **Add your hostnames and point DNS at Cloud.**

   ```bash
   cloud domain:create <env> --name <app-host> --wildcard-enabled=false -n --json
   cloud domain:create <env> --name <render-host> --wildcard-enabled=false -n --json   # artifacts only
   ```

   Each response carries an `id` and a `dnsRecords` array. **Place exactly the records Cloud returns** —
   that is its statement of what it wants for that hostname right now — then verify each domain by the
   **id from its own `domain:create` response**, which is the only argument this command takes:

   ```bash
   cloud domain:verify <domain-id> -n --json
   ```

   If you want Cloud to derive `APP_URL` from this hostname, make it the environment's **primary**
   domain — a separate action in the Cloud dashboard. Setting `APP_URL` in step 4 does not depend on
   it.

   **Leave `SESSION_DOMAIN` unset.** If the app and artifact hostnames are neighbours under one domain,
   a shared `SESSION_DOMAIN` hands your login cookie to the artifact origin and removes the isolation
   that makes artifact hosting safe.

   If you put a CDN or proxy in front of the render hostname, it must not rewrite HTML. Capstan sends
   `Cache-Control: no-transform`, which Cloudflare honours.

6. **Deploy and migrate.**

   ```bash
   cloud deploy -n
   cloud deploy:monitor -n
   cloud command:run <env> --cmd "php artisan migrate --force" -n
   ```

   **Do not add `--no-monitor` here.** With it, `command:run` returns as soon as the command has been
   *submitted*, so a failed migration looks like success. Without it, a non-interactive run waits for
   the remote command to finish and reports how it ended. Wait for that result before step 7.

7. **Verify the injected resources actually work.** A green deploy does not prove this; read the
   resolved config out of the running environment:

   ```bash
   cloud tinker <env> --code="echo config('database.default');"        # your database engine, e.g. pgsql
   cloud tinker <env> --code="echo config('queue.default');"           # Cloud's managed queue connection
   cloud tinker <env> --code="echo config('filesystems.default');"     # the disk wired to your bucket
   cloud tinker <env> --code="echo config('app.url');"                 # must equal your app hostname
   cloud tinker <env> --code="var_dump(config('session.domain'));"     # must be null
   cloud tinker <env> --code="Storage::put('probe.txt','ok'); echo Storage::get('probe.txt'); Storage::delete('probe.txt');"
   ```

   The storage round-trip is the one that proves the bucket is attached and injected. If any value
   looks wrong, first check whether **you** set a variable shadowing an injected one, and remove it.

8. **Set up the deployed installation.** The accounts you made locally do not exist there.

   ```bash
   # Creates the Owner on <env>. You are prompted for the password here; only its
   # hash is sent. This is the one time you deliberately leave --local off.
   php artisan create-admin --environment=<env> --email=you@example.com --name="Your Name"

   # Postmaster only. This command is local-only by design, so run it INSIDE the
   # environment rather than pointing it at one. Again, no --no-monitor: wait for
   # it to report success, or you will not know the key exists.
   cloud command:run <env> --cmd "php artisan bfc:signing-root:provision --local" -n
   ```

9. **Check the app answers.** `https://<app-host>` should return `200` with a valid certificate, and
   `GET /up` should return `200` once the app has booted.

## Configuration

The settings a self-hosted Capstan normally changes. Laravel's own settings are in `.env.example`.

| Variable | Default | What it does |
| --- | --- | --- |
| `APP_URL` | `http://localhost` | The app's own hostname. Load-bearing: it names the only page allowed to frame artifacts, and it is the base of the poll and token URLs handed to Postmaster agents. On Laravel Cloud it is injected from the primary domain — verify it rather than setting it. |
| `CAPSTAN_FEATURE_ARTIFACTS` | `false` | Turns artifact hosting on. While off, the artifact API and viewer return `404`. |
| `CAPSTAN_ARTIFACT_RENDER_ORIGIN` | *(none)* | The second hostname artifact HTML is served from. **Required, and `.env.example` ships a placeholder you must replace.** With no value, artifact viewing returns `404` rather than falling back to the app hostname. |
| `CAPSTAN_ARTIFACT_MAX_CONTENT_BYTES` | `1048576` | Largest artifact accepted, in bytes. Anything bigger is a `422`. |
| `CAPSTAN_ARTIFACT_CSP_SCRIPT_SRC` | *(empty)* | Comma-separated extra sources artifact HTML may load scripts from. Empty means none. |
| `CAPSTAN_ARTIFACT_CSP_STYLE_SRC` | *(empty)* | Same, for stylesheets. |
| `CAPSTAN_ARTIFACT_CSP_FONT_SRC` | *(empty)* | Same, for fonts. |
| `CAPSTAN_ARTIFACT_CSP_IMG_SRC` | *(empty)* | Same, for images. Inline `data:` images are always allowed. |
| `CAPSTAN_FEATURE_POSTMASTER` | `false` | Turns the message bus on. While off, `/postmaster` and the poll API return `404`. |
| `CAPSTAN_SERVER_ID` | *(none)* | This server's permanent address. Leave it blank: Capstan mints one and stores it. Set it only to restore a previous identity after rebuilding a server. |
| `CAPSTAN_POSTMASTER_MAX_INBOUND` | `50` | Most messages returned by one poll. The rest stay queued for the next one. |
| `CAPSTAN_POSTMASTER_MAP_STALE_AFTER_SECONDS` | `300` | How long an agent can go without polling before the map shows it offline. |
| `CAPSTAN_POSTMASTER_PROBE_INTERVAL_SECONDS` | `300` | Minimum gap between liveness challenges to one agent. |
| `CAPSTAN_POSTMASTER_PROBE_TIMEOUT_SECONDS` | `900` | How long an agent has to answer a challenge before it is marked failed. |
| `CAPSTAN_POSTMASTER_PROBE_BACKOFF_SECONDS` | `1800` | How long to wait before challenging an agent that already failed. |
| `BUILT_FOR_CLOUD_PRODUCT` | `Capstan` | The product name reported by the public `GET /bfc/meta` endpoint. |
| `BUILT_FOR_CLOUD_CREDENTIAL_GUARD` | `bfc` | Name of the auth guard credentials resolve through. The default works; change it only if it collides with a guard you added. |
| `MAIL_MAILER` | `log` | Invitation email. While this is `log`, nothing is delivered — the message is written to the log instead. |

## Using it

### Getting a token

Capstan's APIs do not accept a password. They accept a **bearer token** issued for one specific job,
called a *purpose*. Capstan has two:

| Purpose | Used for |
| --- | --- |
| `capstan.artifact.ingest` | `POST /api/v1/artifacts` |
| `capstan.postmaster.poll` | `POST /api/v1/poll` |

A token is bound to its purpose, to this installation, and to the person it was issued to. A token for
one purpose is rejected on the other endpoint.

Tokens are issued by a **device authorization**: a program asks for one, you approve it in your
browser, and the program collects it. This is the flow that produces a token the APIs accept.

> **Not the Personal credentials page.** `/bfc/ui/credentials/personal` offers to issue a token for
> either purpose, but a token from that page is rejected with `401` by both APIs — a bug in the
> version of Built for Cloud this release pins. Use the device flow below instead. For Postmaster you
> do not do any of this by hand: the installer on the Postmaster page runs the whole flow for you.

You also need your **actor id**, a number the UI does not display. Read it once:

```bash
php artisan tinker --execute='echo ArtisanBuild\BuiltForCloud\User::query()->where("email","you@example.com")->value("id");'
```

Asking for an authorization needs a signed-in session, so start by getting one into a cookie file:

```bash
CAPSTAN_URL=http://localhost:8000

# The cookie jar holds a live session for your account. Keep it out of the
# repository and delete it when you are done, even if a command fails.
JAR=$(mktemp -t capstan-cookies)
trap 'rm -f "$JAR"' EXIT

# 1. Sign in.
CSRF=$(curl -s -c "$JAR" "$CAPSTAN_URL/bfc/login" \
  | grep -o 'name="_token" value="[^"]*"' | cut -d'"' -f4)
curl -s -b "$JAR" -c "$JAR" -X POST "$CAPSTAN_URL/bfc/login" \
  -d "_token=$CSRF" -d "email=you@example.com" -d "password=<your password>"

# 2. Ask for a token. Prints device_code, user_code and verification_uri.
CSRF=$(curl -s -b "$JAR" -c "$JAR" "$CAPSTAN_URL/bfc/ui" \
  | grep -o 'name="_token" value="[^"]*"' | cut -d'"' -f4)
curl -s -b "$JAR" -c "$JAR" -X POST "$CAPSTAN_URL/bfc/device-authorizations" \
  -H "Content-Type: application/json" -H "Accept: application/json" -H "X-CSRF-TOKEN: $CSRF" \
  -d '{"app_purpose":"capstan.artifact.ingest","label":"my laptop"}'

# 3. Open the verification_uri in a browser signed in as the same person and
#    press Approve. The pending request is already filled in for you.

# 4. Collect the token. Repeat until it stops returning authorization_pending.
curl -s -X POST "$CAPSTAN_URL/bfc/device/token" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"device_code":"<device_code from step 2>"}'

rm -f "$JAR"
```

If you adapt this against a deployed Capstan, the same rule applies: that file is a live login. Never
leave one in a directory you might commit.

Step 4 returns `{"access_token":"tok_…","token_type":"Bearer","credential_id":"…","app_purpose":"…"}`.
The authorization expires ten minutes after step 2, and a device code can be exchanged only once.

### Publishing an artifact

With `CAPSTAN_TOKEN` set to that `access_token` and `1` replaced by your actor id:

```bash
curl -X POST http://localhost:8000/api/v1/artifacts \
  -H "Authorization: Bearer $CAPSTAN_TOKEN" \
  -H "X-Capstan-Actor-ID: 1" \
  -H "Content-Type: application/json" \
  -d '{
        "content": "<!doctype html><title>Hello</title><p>Hello from Capstan.</p>",
        "content_type": "text/html",
        "visibility": "signed_url"
      }'
```

A success is `201` with the artifact and a link to share:

```json
{
  "artifact": {
    "id": "01a0ccd2-1b76-701b-b112-4a45af7329a9",
    "actor_id": "1",
    "visibility": "signed_url",
    "expires_at": null,
    "content_type": "text/html",
    "size_bytes": 61,
    "content_hash": "adc6f946144285907fb73c6f6358f8701ae6ff79da7975afa2f40627e236383a",
    "share_url": "http://localhost:8000/artifacts/01a0ccd2-.../share?expires=...&signature=...",
    "created_at": "2026-09-23T05:51:56.000000Z"
  },
  "share_url": "http://localhost:8000/artifacts/01a0ccd2-.../share?expires=...&signature=..."
}
```

Open `share_url` in a browser. You get a page on the app hostname holding a sandboxed iframe, and the
iframe loads your HTML from `artifacts.localhost:8000` under a content security policy that by default
permits inline scripts and styles and `data:` images, and blocks the page from reaching the network at
all. It can load an external script, stylesheet, font or image only from a source you add yourself
through the `CAPSTAN_ARTIFACT_CSP_*` variables. That is the isolation working: two hostnames, one page.

Fields you can send:

- `content` — the HTML. **Required.**
- `content_type` — `text/html` or `application/xhtml+xml`. **Required.**
- `visibility` — `org_auth` (default; anyone signed in to this Capstan can open it) or `signed_url`
  (anyone holding the link can open it, signed in or not).
- `expires_at` — an ISO-8601 time in the future. After it, the link returns `404`.

Application errors share one shape, so you can read `error.code`:

```json
{"error":{"code":"unauthenticated","message":"Unauthenticated."}}
```

You will see `401 unauthenticated` for a missing, wrong or mismatched token, `422 validation_failed`
with a per-field `errors` object for bad input, and `404` when the artifacts feature is switched off.

**One exception: rate limiting.** You get 60 API requests per minute, counted per person across both
endpoints together. The 61st is rejected by Laravel's throttle middleware before Capstan sees it, so
it is a plain `429` with `Retry-After` and `X-RateLimit-*` headers and **no `error.code`**. Handle that
separately.

### Connecting an agent to Postmaster

Sign in, open **/postmaster**, and under **Connect a local agent** press **Generate installer**.
Capstan prints a shell snippet containing this server's address, the poll URL and a one-time
authorisation code. It expires in ten minutes, and the page shows when.

Copy it, run it on the machine you want to connect, and approve the request in the browser tab it
opens. It stores a token, writes a poll script under `~/.config/capstan/<server-id>/`, and installs a
once-a-minute cron entry. It needs `curl`, `php` and `crontab` on that machine.

**The installed poller only announces presence and answers liveness probes.** Out of the box it
advertises no inboxes, sends nothing, and acknowledges nothing — it will go green on the map and carry
no mail. To receive messages, give it an address:

```bash
# on the agent machine; <server-id> is the directory the installer created
echo '["build-agent"]' > ~/.config/capstan/<server-id>/inboxes.json
```

The next poll claims `build-agent@<server-id>` for you and advertises it from then on. Inboxes are
first-come: the first user to claim a local part owns it, and another user's agent asking for it gets
`409 inbox_claimed`.

Sending and acknowledging are yours to write. A poll is one `POST /api/v1/poll`, and the same request
can advertise presence, send, and acknowledge at once:

```bash
curl -X POST http://localhost:8000/api/v1/poll \
  -H "Authorization: Bearer $POLL_TOKEN" \
  -H "X-Capstan-Actor-ID: 1" \
  -H "Content-Type: application/json" \
  -d '{
        "presence": {"ready_inboxes": ["build-agent", "deploy-agent"]},
        "outbound": [{
          "id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
          "type": "handoff",
          "version": 1,
          "from": "build-agent@<server-id>",
          "to": "deploy-agent@<server-id>",
          "created_at": "2026-09-23T06:00:00Z",
          "message_id": "01ARZ3NDEKTSV4RRFFQ69G5FAW",
          "body": {"task": "ship it"},
          "refs": []
        }],
        "acks": ["<message_id you have finished with>"]
      }'
```

`type` must be one of `handoff`, `kb_nomination`, `solicitation` or `generic` — anything else is a `422`.
You may only send `from` an inbox you have advertised at least once, so the sending address belongs in
`ready_inboxes` too. Never send a `signature` yourself: the server signs on delivery and rejects your
version. `refs` must be an empty array in version 1, and `created_at` is UTC to the second.

The response carries `inbound` and `cursor`. Each inbound envelope comes back with the server's
`signature` added — a 64-character hex HMAC — and keeps being redelivered on every poll until you name
its `message_id` in `acks`. After that it stops.

The wire format, inbox ownership rules, request caps and probe protocol are in
[docs/postmaster.md](docs/postmaster.md).

### Inviting the rest of your team

Sign in as Owner or Admin, open **/bfc/members**, and invite an email address. Owners and Admins can
invite Members; only an Owner can invite an Admin.

With the default `MAIL_MAILER=log` nothing is emailed. The members page lists the pending invitation
but **does not show its link** — the message is written to the log instead, so find it there:

```bash
grep -o 'http[^ ]*/bfc/invitations/[A-Za-z0-9_-]*' storage/logs/laravel.log | tail -1
```

Send that link to the person yourself. Opening it leads to an acceptance form; once they submit it,
they appear in the members list as active with the role you chose. To deliver invitations properly,
configure a mailer — see [docs/email-cloudflare.md](docs/email-cloudflare.md).

## Troubleshooting

**The install stops because PHP is too old.** Capstan requires PHP 8.5. Check with `php -v`; if you
manage several versions, make sure the 8.5 binary is the one on your `PATH`.

**An API token returns `401`.** If you issued it from `/bfc/ui/credentials/personal`, that is expected
at the pinned Built for Cloud version — use the device flow above. Otherwise check that
`X-Capstan-Actor-ID` is *your* id: Capstan compares the header against the token's owner and rejects a
mismatch before doing anything else.

**Artifact links return `404`.** Common causes, roughly in order: `CAPSTAN_FEATURE_ARTIFACTS` is still
`false`; `CAPSTAN_ARTIFACT_RENDER_ORIGIN` is empty; the artifact has expired or the id is wrong; or you
are requesting artifact *content* from the app hostname. Content is served only from the render
hostname and the viewer page only from the app hostname — each refuses the other's host deliberately,
so both names must resolve before artifacts work, including locally.

**Artifact HTML arrives rewritten, or with extra bytes.** A CDN or proxy in front of the render
hostname is editing it. Capstan sends `Cache-Control: no-transform`, which Cloudflare honours; if you
have another proxy, turn off its HTML rewriting for that hostname.

**Postmaster messages are never delivered.** The signing root is missing — Capstan signs every envelope
as it hands it over and refuses rather than deliver an unsigned one. Run
`php artisan bfc:signing-root:provision --local`, or inside a deployed environment as shown in step 8.

**The app refuses to boot with Postmaster on**, saying `Postmaster requires app.timezone to be UTC`.
Something changed `timezone` in `config/app.php`. Put `'UTC'` back. Message signatures are computed
over UTC timestamps, so any other timezone silently breaks every signature.

**An agent shows red or pending on the map.** Work down this list:

1. **Is it polling?** Red comes first from poll recency — an agent that has never polled, or has not
   polled within `CAPSTAN_POSTMASTER_MAP_STALE_AFTER_SECONDS` (default five minutes), is red whatever
   its probe says. Check `crontab -l` on the agent machine, then run its poll script by hand and read
   the error: a bad token or an unreachable host fails silently under cron.
2. **Has it answered a challenge?** An agent that polls but has not yet answered one shows **pending**,
   not red. That is normal for a new agent; it turns green after the first successful probe.
3. **Did a probe fail?** A wrong digest turns the agent red even while it polls happily. The failure is
   logged server-side.
4. **Only then, the scheduler.** `postmaster:probe-sweep` runs every minute and is what fails an
   *overdue* probe. If nothing ever moves out of pending, check that the scheduler is enabled on the
   server.

**An invitation was sent but nobody received it.** With `MAIL_MAILER=log` that is the designed
behaviour — read the link out of `storage/logs/laravel.log` as shown above.

**`php artisan serve` behaves differently from the tests.** `serve` only passes through some
environment variables, so it can quietly use a different database than you expect. Prefer `php artisan
dev`.

## More documentation

- [docs/built-for-cloud.md](docs/built-for-cloud.md) — how accounts, sign-in and API credentials work.
- [docs/postmaster.md](docs/postmaster.md) — the Postmaster wire contract, in full.
- [docs/email-cloudflare.md](docs/email-cloudflare.md) — sending invitation email.

## License

MIT — see [LICENSE](LICENSE).
