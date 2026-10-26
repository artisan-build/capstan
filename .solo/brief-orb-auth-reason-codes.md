# Orb authentication deny reason instrumentation

## Objective

Add diagnostic-only, fail-closed reason-code logging to the Amp workload-token verifier and claim binder so production can reveal the first denied predicate. There must be no authentication/authorization behaviour change.

## Required scope

- Instrument every denial path in `App\Auth\AmpTokenVerifier` and `App\Auth\AmpClaimBinder` with a stable, low-cardinality reason code.
- Keep the externally observable denial exactly unchanged: same exception class/message, same middleware status/body, and no success-path change.
- Log through the existing Laravel log channel. Use one stable message and structured context.
- The standard context may contain only a reason code (and, if useful, a constant component name). Never log the JWT, signature/header/payload, exception text, email, Amp user ID, project ID, thread ID, local user ID, item ID, claimant actor, nonce, fence, URL fetched from untrusted token data, or any identity/token value.
- `aud_mismatch` must be a distinct verifier reason code.
- On `aud_mismatch` only, the received audience may be logged verbatim as `received_aud` **only** when it exactly equals one of these public configured-application URL shapes derived from a valid trusted `config('app.url')`:
  - the configured URL with trailing slash removed;
  - that URL plus `/`;
  - that URL plus `/mcp` or `/mcp/`;
  - that URL plus `/mcp/orb` or `/mcp/orb/`.
  Arbitrary same-origin paths are not allowlisted. Non-string audiences, foreign origins, credentials, query strings, fragments, ports/hosts/schemes that differ, or any other value must not be logged. Do not substitute a hash or alternate token-derived value.
- Distinguish verifier failures sufficiently to identify the failed predicate, including malformed token/header/payload/signature/JWK/metadata and each identity claim check. In particular, do not retain a single compound identity denial that hides whether audience, issuer, lifetime, token use, verified-email flag, required strings, email shape, or subject binding failed.
- Distinguish binder failures sufficiently to identify the failed predicate/category, including item match/current-context, identity link/user lookup or creation eligibility, claimant actor, admission, membership, and launch nonce. Preserve the exact transaction/race semantics.
- Unexpected verifier failures must remain fail-closed and get a generic bounded reason code without exception detail.
- Missing/malformed Authorization headers are outside the verifier/binder scope and need no new instrumentation.

## Tests

Add focused tests that prove:

1. audience mismatch logs `aud_mismatch`, and the HTTP response remains the existing 401 body;
2. an allowlisted configured-origin or MCP URL audience is included verbatim as `received_aud` on mismatch;
3. a foreign/arbitrary/sensitive-looking audience is not present anywhere in the logged message/context;
4. representative verifier and binder denial paths produce their intended stable codes without token or identity values;
5. successful authentication/binding remains successful and does not emit a denial log;
6. existing Orb MCP/auth coverage remains green.

Prefer tests that inspect the exact `Log` call and reject extra context keys. Do not weaken existing assertions.

## Delivery and gate

- Work only in this worktree and branch.
- Use the required PHP 8.5 shim.
- Run focused tests while implementing.
- Run `composer ready` before handoff and let its mechanical changes ride along if any.
- Commit all intended changes. Leave a clean worktree and report the exact candidate SHA plus tests run.
- Do not push, open a PR, deploy, or touch production.
