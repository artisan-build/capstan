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
`outbound`, `acks`, and `cursor` may be omitted. Each outbound envelope contains `id`, `type`,
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

### Deployment requirements

Postmaster requires `app.timezone` to be `UTC` (the value shipped in `config/app.php`; do not change it
in a fork). Envelope timestamps are signed as UTC and stored naively; any other application timezone
would silently invalidate every signature, so the application refuses to boot with the feature enabled
under a non-UTC timezone, and the signer refuses to sign or verify.

`probe_response` in requests and `probe_challenge` in responses are reserved for the Layer 2 probe
protocol. Layer 1 accepts and ignores `probe_response`, and does not emit `probe_challenge`.
