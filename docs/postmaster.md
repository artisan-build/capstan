# Postmaster Wire Contract

Postmaster envelopes use an immutable, opaque server identity. A server id is exactly 26 uppercase
Crockford base32 characters matching `[0-9A-HJKMNP-TV-Z]{26}`. It is minted once and is not derived
from a hostname. A fully qualified address is `{local-part}@{server-id}`. Its lowercase local part is
1 to 64 characters, starts and ends with an ASCII letter or digit, and may contain `.`, `_`, or `-`
internally.

Envelope signatures are lowercase hexadecimal HMAC-SHA256 values computed over RFC 8785 canonical
JSON containing every envelope field except `signature`. Object keys sort by UTF-16 code unit at every
depth, and objects remain distinct from arrays in the canonical form. Version 1 control-plane JSON
permits integers, strings, booleans, null, lists, and objects, but rejects all floating-point values.
The envelope `body` is a JSON object and `refs` is a JSON array. Inbound envelopes must therefore be
decoded with `json_decode($json)` to preserve objects as `stdClass`, not with associative-array mode;
alternatively, retain the raw received bytes for verification. `created_at` is normalized to UTC and
serialized at whole-second precision as `Y-m-dTH:i:sZ` before signing so database round-trips do not
invalidate signatures.

The `refs` list is reserved and must remain empty in version 1. Blob bytes never enter an envelope;
future references will identify data served through Capstan's existing private artifact path.

## Layer 1 polling

An authenticated spoke calls `POST /api/v1/poll` with a JSON object containing:

```json
{
  "presence": { "ready_inboxes": ["inbox-a", "inbox-b"] },
  "outbound": [],
  "acks": [],
  "cursor": null
}
```

`presence.ready_inboxes` is required and replaces that spoke's routing set on every successful poll.
`outbound`, `acks`, `cursor`, and `probe_response` may be omitted. Each outbound envelope contains `id`, `type`,
`version`, `from`, `to`, `created_at`, `message_id`, `body`, `refs`, and `signature`. Version 1 requires
an empty `refs` array. Unsupported versions are rejected with `error.known_version` naming the version
this server speaks. Local messages are delivered to spokes routing for the destination local part;
foreign messages are parked for a future relay transport.

A poll is atomic: any rejection (`validation_failed`, `invalid_signature`, `unsupported_version`,
`inbox_claimed`, `sender_not_owned`) leaves presence, routing, ownership, messages, and acks exactly as
they were.

### Request caps

| field | maximum | over the cap |
|---|---|---|
| `presence.ready_inboxes` | 256 entries | 422 `validation_failed` on `presence.ready_inboxes` |
| `outbound` | 100 envelopes | 422 `validation_failed` on `outbound` |
| `acks` | 1000 message ids | 422 `validation_failed` on `acks` |

Responses are bounded by the configured `max_inbound` (`CAPSTAN_POSTMASTER_MAX_INBOUND`, default 50).
The caps bound the work one poll can demand of the database and keep every request well inside the
drivers' bind-parameter limits, so an over-sized poll is a clean 422 in the `ApiError` envelope, never
a server error, and never leaves a spoke unable to poll again.

### Inbox ownership

Every local part is owned by exactly one user; the first user to advertise it claims it. Claims persist
after every spoke of that user stops advertising the inbox, so pending mail can never be captured by a
later claimant. Multiple spokes of the **same** user may advertise the same inbox — this is the
`{inbox-or-role-or-pool}@{server}` pool: several CLI installs competing for one address, each receiving
the unacknowledged batch until one of them acks. A spoke of a **different** user advertising a claimed
inbox is rejected with 409 `inbox_claimed`; `error.inboxes` lists the offending local parts and nothing
in that request is applied, including any other first-claims it bundled.

The persisted routing table, not the request array, decides what a spoke may read and acknowledge.
Delivery is scoped to the inboxes the polling spoke currently routes for (its persisted routing rows,
restricted to inboxes its user owns). Acknowledgements are scoped to every inbox the polling **user**
owns, so a spoke that has stopped advertising an inbox can still ack the batch it already received.

### Sender authentication

Every outbound `from` must be a local address (`from`'s server id equals this server's id) whose local
part is an inbox owned by the sending user; anything else is rejected with 403 `sender_not_owned` and
`error.index` naming the envelope. Sending from an inbox therefore requires having advertised it at
least once. This binds a sender to a user, not to a particular spoke — any of a user's spokes may send
from any inbox that user owns. Per-spoke sender authentication needs per-spoke keys and is out of scope
for Layer 1.

### Ordering and acknowledgement

The response contains `inbound` and `cursor`. Pending and previously delivered messages are returned in
ascending `received_at`, then `id`, order until acknowledged or the configured batch limit is reached.
`received_at` is assigned by the server when an envelope is first stored and is identical on every
database driver; the signed, client-supplied `created_at` is never used for ordering, so backdating an
envelope cannot promote it. A message's `delivered_at` records its first delivery and survives
redelivery. Unknown and repeated acks are no-ops.

The cursor is an opaque resumption and observability marker, not a deduplication mechanism. A response
cursor is the id of the last envelope in that batch, or null for an empty batch. The request cursor is
recorded on the spoke, but it never suppresses an unacknowledged message; acknowledgements are the
authoritative deduplication state. Stale, unknown, and malformed marker values have no effect on
delivery.

### Synthetic liveness probes

When a spoke is due for a liveness check, its poll response contains a challenge:

```json
{
  "probe_challenge": {
    "probe_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    "nonce": "JqNfS1VK5GQTzJ1VQYjHeYxjJ0X79EmgmMyYaLB6w1A",
    "algorithm": "sha256"
  }
}
```

The spoke computes the lowercase hexadecimal SHA-256 digest of the nonce and includes it in a later
poll:

```json
{
  "probe_response": {
    "probe_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    "digest": "70b0c590af60d9a18b18b12f16100a8b52ca83bbf5931da66875dc821f67bb3a"
  }
}
```

A digest, rather than a verbatim echo, demonstrates that the client executed code on the received
challenge. The server compares it with `hash_equals`. A correct response marks the spoke green; a
wrong response marks it red. Malformed responses receive a 422 `validation_failed`. Well-formed
responses for unknown, expired, already answered, or another spoke's probe are ignored so the
auxiliary probe can never reject that poll's postal exchange.

Until its original deadline, an unanswered challenge is included unchanged in every poll response.
This at-least-once delivery lets a spoke recover from a lost HTTP response without changing the nonce
or extending the time available to compute its digest.

`CAPSTAN_POSTMASTER_PROBE_INTERVAL_SECONDS` (default 300 seconds) is the minimum gap between challenges
for one spoke. `CAPSTAN_POSTMASTER_PROBE_TIMEOUT_SECONDS` (default 900 seconds, minimum 60) is the
response deadline, and `CAPSTAN_POSTMASTER_PROBE_BACKOFF_SECONDS` (default 1800 seconds) delays the next
challenge after a failure. An unexpired outstanding challenge suppresses new challenges. Expiry makes
another challenge eligible, but only the sweep transitions the overdue record to failed.

The scheduler runs `php artisan postmaster:probe-sweep` every minute. It fails overdue unanswered
probes even when a spoke has stopped polling entirely; checking lazily on the next poll would never
detect that failure mode. A stale failure is retained in probe history but cannot overwrite a newer
successful probe. Each transition into red invokes the rebindable `App\Postmaster\ProbeFailureNotifier`
exactly once. Its default implementation logs a warning, and forks may bind it to email or Slack.
Notifier exceptions are logged without rolling back probe state or stopping the sweep. Implementations
must remain out-of-band: never send failure notices over the spoke poll channel whose failure they
report. While Postmaster is disabled, the sweep voids outstanding challenges so re-enabling cannot
produce alerts from frozen liveness state.

## Hub map liveness

The authenticated Postmaster map lists the registered CLI installations and their routing counts. An
owner or admin can see every spoke for operational oversight; a member can see only spokes owned by
their user account. The page never exposes another member's spokes or inboxes.

Displayed liveness combines polling recency with the independent probe outcome:

- **Green:** the spoke polled within the staleness window and its probe state is `green`.
- **Red:** the spoke has not polled within the window, has never polled, or its probe state is `red`.
- **Pending:** the spoke polled within the window but its probe state remains `unknown`.

`CAPSTAN_POSTMASTER_MAP_STALE_AFTER_SECONDS` configures the staleness window and defaults to 300
seconds, allowing five missed polls at the expected once-a-minute cadence. A poll exactly at the
cutoff remains current; only an earlier poll is stale.

Pending is intentional: a newly registered, actively polling spoke has not proved that it can process
a challenge, but presenting it as failed would be misleading. The first successful poll makes a spoke
pending, not green; it becomes green only after its first probe passes.

### Onboarding a spoke

Owners and admins can generate the **Connect a local agent** snippet on the Postmaster map. Members can
view their own spokes but cannot generate or retrieve onboarding snippets. Postmaster's feature flag is
checked on the initial page request and every Livewire round-trip; disabling it makes both the map and
snippet generation return 404 without issuing a device grant.

The snippet contains this install's configured application name, immutable server id, absolute poll and
device-token URLs, verification URL and user code, a random device code, and the literal once-a-minute
cron entry. The device and user codes belong to the existing CLI device flow. They expire after ten
minutes, and the device code can be exchanged only once after a signed-in operator approves the request
at the verification URL. The snippet never contains a personal access token or any other durable bearer
credential.

Pasting the snippet requires `curl`, `php`, and `crontab`. It opens the verification URL, waits for
approval, exchanges the one-time device code, and writes the resulting personal access token to
`~/.config/capstan/{server-id}/token` with mode 600. It writes a mode-700 poll script beside that file
and installs a tagged cron entry that runs it every minute. The bearer header is supplied to `curl`
through stdin rather than exposed in the process argument list. The poll script sends no inbox
declarations by default, but it registers the token's spoke, persists a pending probe response beside
the token when needed, and returns that response on its next poll. The server stores only the SHA-256
hash of a pending device code plus its visible user code, status, expiry, and eventual approving user.
A successful exchange consumes that row and stores the normal Sanctum token metadata and hash;
plaintext token material is not retained by the server.

The initial poll makes the spoke visible as **Pending**. The next poll returns the first challenge's
correct digest and makes it **Green**. A stale poller or a failed probe is **Red**.

Treat a copied snippet as sensitive until its ten-minute device grant expires or is consumed. If it
leaks before approval, do not approve it; generate a new snippet and let the old code expire. If it
leaks after approval or may have been exchanged, revoke the resulting `capstan-cli` token under token
settings, remove the local token file and cron entry, then generate a fresh snippet.

### Deployment requirements

Postmaster requires `app.timezone` to be `UTC` (the value shipped in `config/app.php`; do not change it
in a fork). Envelope timestamps are signed as UTC and stored naively; any other application timezone
would silently invalidate every signature, so the application refuses to boot with the feature enabled
under a non-UTC timezone, and the signer refuses to sign or verify.
