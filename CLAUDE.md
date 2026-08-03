# Capstan — agent brief

Capstan is the **fork-and-deploy AI ecosystem server** for the Solo fleet: ONE self-hosted Laravel app
that an organization forks, deploys, and toggles into whatever it needs (gated artifacts, handoffs, org
KB, postmortems, collaboration). Pre-launch, greenfield.

## Design of record (READ FIRST)
The full architecture + every ratified decision live in the **brain** metaproject, not here:
- `~/Herd/brain/ideas/ecosystem/README.md` — decision log D1–D26.
- `~/Herd/brain/ideas/ecosystem/deterministic-server.md` — the slice-1 build spec.

## Non-negotiable constraints (from brain decisions)
- **One fork-and-deploy Laravel app — never split into multiple apps** (D23).
- **Standard Laravel Livewire starter kit; FREE Flux only — NO Flux Pro** (this is forkable OSS; a forker
  lacks the Pro license) (D20).
- **Feature flags via Laravel Pennant; `.env` → `config()` is the source of truth**, not Pennant's DB
  store (D23).
- **Laravel Cloud storage:** use the **Flysystem default disk** (auto-wired to the attached bucket); set
  NO `AWS_*`; require `league/flysystem-aws-s3-v3` (D22).
- **Artifacts:** rich HTML kept safe by **origin isolation, not sanitization**; **private storage,
  app-mediated serve — never expose raw public bucket URLs** (D22).
- **Prefer first-party Laravel + artisan-build packages;** any other third-party needs Ed's sign-off.

## Workflow
Feature builds: see `.solo/workflow.md` and the `multi-agent-build` skill.
