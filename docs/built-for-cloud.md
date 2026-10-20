# Built for Cloud

Capstan uses `artisan-build/built-for-cloud` as its only human, authority, invitation, session, and credential system. The package owns the `/bfc` browser, device, loopback, and credential-management routes, and the `/settings` account pages.

Capstan declares two application purposes.

| Application purpose | Protocol purpose | API |
| --- | --- | --- |
| `capstan.artifact.ingest` | `consumption` | `POST /api/v1/artifacts` |
| `capstan.machine` | `mcp` | `POST /api/v1/poll`, `/api/v1/postmaster/replace`, and `/api/v1/work/{team}/claim`, `/renew`, `/transition` |

These APIs accept only an exact-bound package bearer through `BoundBearerCredentialAuthenticator`. Clients also send the non-secret stable package actor id in `X-Capstan-Actor-ID`; Capstan derives `user_principal:capstan-user:<id>` from that request value independently of the presented credential. A mismatch is rejected before validation or domain work. Historical `capstan.postmaster.poll` and `capstan.work.drain` records may remain stored, but those purposes are no longer declared or accepted.

Device and loopback clients use the package routes under `/bfc/device*` and `/bfc/loopback*`. The Connect-this-machine installer on `/postmaster` starts the machine device flow and writes its credential and actor id under `~/.config/capstan/<server-id>/`; the approving browser user becomes its owner. Device and loopback clients never choose purpose, subject, installation, application, audience, ownership, abilities, credential kind, expiry, or binding.

Postmaster envelopes are signed server-side with the package installation signing root after bearer authentication and sender ownership checks. Provision it on a local installation with:

```sh
php artisan bfc:signing-root:provision --local
```

Never omit `--local` from a state-changing Built for Cloud artisan command unless a remote environment is explicitly intended and authorized.
