# Built for Cloud

Capstan uses `artisan-build/built-for-cloud` as its only human, authority, invitation, session, and credential system. The package owns the `/bfc` browser, device, loopback, and credential-management routes.

Capstan declares two application purposes:

| Application purpose | Protocol purpose | API |
| --- | --- | --- |
| `capstan.artifact.ingest` | `consumption` | `POST /api/v1/artifacts` |
| `capstan.postmaster.poll` | `mcp` | `POST /api/v1/poll` |

Both APIs accept only an exact-bound package bearer through `BoundBearerCredentialAuthenticator`. Clients also send the non-secret stable package actor id in `X-Capstan-Actor-ID`; Capstan derives `user_principal:capstan-user:<id>` from that request value independently of the presented credential. A mismatch is rejected before validation or domain work.

Device and loopback clients use the package routes under `/bfc/device*` and `/bfc/loopback*`. They never choose purpose, subject, installation, application, audience, ownership, abilities, credential kind, expiry, or binding.

Postmaster envelopes are signed server-side with the package installation signing root after bearer authentication and sender ownership checks. Provision it on a local installation with:

```sh
php artisan bfc:signing-root:provision --local
```

Never omit `--local` from a state-changing Built for Cloud artisan command unless a remote environment is explicitly intended and authorized.
