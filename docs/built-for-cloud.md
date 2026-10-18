# Built for Cloud

Capstan uses `artisan-build/built-for-cloud` as its only human, authority, invitation, session, and credential system. The package owns the `/bfc` browser, device, loopback, and credential-management routes, and the `/settings` account pages.

Capstan declares three application purposes.

| Application purpose | Protocol purpose | API |
| --- | --- | --- |
| `capstan.artifact.ingest` | `consumption` | `POST /api/v1/artifacts` |
| `capstan.postmaster.poll` | `mcp` | `POST /api/v1/poll` |
| `capstan.work.drain` | `mcp` | `POST /api/v1/work/{team}/claim`, `/renew`, `/transition` |

All three APIs accept only an exact-bound package bearer through `BoundBearerCredentialAuthenticator`. Clients also send the non-secret stable package actor id in `X-Capstan-Actor-ID`; Capstan derives `user_principal:capstan-user:<id>` from that request value independently of the presented credential. A mismatch is rejected before validation or domain work.

Device and loopback clients use the package routes under `/bfc/device*` and `/bfc/loopback*`. Issue API tokens through the device flow; a token minted from `/settings/credentials/personal` or `/settings/credentials/installation` is rejected by these APIs with `401` (last checked 2026-10-18, Built for Cloud v0.20.0). Device and loopback clients never choose purpose, subject, installation, application, audience, ownership, abilities, credential kind, expiry, or binding.

Postmaster envelopes are signed server-side with the package installation signing root after bearer authentication and sender ownership checks. Provision it on a local installation with:

```sh
php artisan bfc:signing-root:provision --local
```

Never omit `--local` from a state-changing Built for Cloud artisan command unless a remote environment is explicitly intended and authorized.
