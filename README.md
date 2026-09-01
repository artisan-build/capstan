# Capstan

The fork-and-deploy AI ecosystem server for the Solo fleet — one self-hosted Laravel app that an
organization forks, deploys (Laravel Cloud one-click), and toggles into whatever it needs: gated
artifact sharing, primitive handoffs, an org knowledge base, cadence-driven postmortems, and
role-addressed collaboration.

**Status:** pre-launch, greenfield. First slice = the **gated-artifact host** (safe sharing of rich,
AI-generated HTML). Public source, MIT licensed.

## Design of record

The architecture and every ratified decision live in the **brain** metaproject:
- `brain/ideas/ecosystem/README.md` — the decision log (D1–D26).
- `brain/ideas/ecosystem/deterministic-server.md` — the slice-1 build spec.

## Core shape (see brain for rationale)

- **One app, many hats.** Capability modules ship as pre-required composer packages; each is toggled at
  runtime by a **Laravel Pennant** flag whose source of truth is `.env` → `config()`. No `composer
  require` to enable a feature — flip a boolean.
- **Fork-and-self-host, single-org-per-instance.** Not multi-tenant SaaS. The org owns and rebinds every
  policy.
- **Rich artifacts, safe by isolation.** AI-generated HTML is served from a separate cookieless render
  origin inside a sandboxed opaque-origin iframe under a strict CSP — contained, not sanitized.
- **Starter kit:** standard Laravel Livewire, free Flux only (no Flux Pro — it's forkable OSS).

## Workflow

Feature builds: see `.solo/workflow.md` and the `multi-agent-build` skill.

## License

MIT — see [LICENSE](LICENSE).
