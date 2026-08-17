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
