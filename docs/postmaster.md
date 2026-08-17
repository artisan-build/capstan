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
this server speaks. Local messages are delivered to spokes advertising the destination local part;
foreign messages are parked for a future relay transport.

The response contains `inbound` and `cursor`. Pending and previously delivered messages are returned in
ascending `created_at`, then `id`, order until acknowledged or the configured batch limit is reached.
An ack is effective only for a message addressed to one of the same spoke's currently advertised
inboxes. Unknown and repeated acks are no-ops.

The cursor is an opaque resumption and observability marker, not a deduplication mechanism. A response
cursor is the id of the last envelope in that batch, or null for an empty batch. The request cursor is
recorded on the spoke, but it never suppresses an unacknowledged message; acknowledgements are the
authoritative deduplication state. Stale, unknown, and malformed marker values have no effect on
delivery.

`probe_response` in requests and `probe_challenge` in responses are reserved for the Layer 2 probe
protocol. Layer 1 accepts and ignores `probe_response`, and does not emit `probe_challenge`.
