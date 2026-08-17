# Workflow — capstan

Project profile for the `multi-agent-build` skill. The coordinator agent reads this FIRST.
Full design lives in the brain metaproject: `brain/ideas/ecosystem/README.md` (decisions D19–D26)
and `brain/ideas/ecosystem/deterministic-server.md` (the slice-1 build spec).

## Phase & mode
- phase: pre-launch
- default mode: A-autonomous
- merge_policy: merge when CI green; no human PR code review (Mode A). The agent loop's own
  quality-review + acceptance-judge stages are the review; CI green is the merge gate.
- merge method: `gh pr merge --squash --auto`

## Hard gate (must be green before review; coordinator verifies on the committed SHA, clean tree)
- command: `composer ready`  (to be established by the scaffolding PR — see CI note)
- extra suites: none yet
- monorepo: no (single app; capability modules are pre-required composer packages, added over time)

## CI (the merge gate for Mode A)
- status: PENDING — no CI exists on a fresh repo. The **scaffolding PR establishes it** and CANNOT
  itself be CI-gated; the coordinator verifies that first PR on a green local `composer ready` + a
  driven smoke of the app. Gate-on-green (Mode A) applies from the NEXT PR onward.
- minimum bar: CI MUST include (1) testing (Pest) and (2) static analysis (PHPStan/Larastan). The
  scaffolding PR must add both before any later PR is CI-gated.
- workflows/jobs: to be created — `.github/workflows/tests.yml` (pest + phpstan), `lint.yml` (pint).

## Dependency install (fresh worktree)
- command: `composer install --no-interaction`
- post-install: copy `.env.example` → `.env`; `php artisan key:generate`; touch
  `database/database.sqlite` (SQLite for tests). Confirm during scaffolding.
- **PHP 8.5 shim (REQUIRED):** the default `php` on PATH is 8.4, but capstan requires `^8.5`. Herd ships
  8.5.7 at `~/Library/Application Support/Herd/bin/php85` (a.k.a. `Herd/bin/php85`). Create a shim dir with
  a `php` → `php85` symlink and **prepend it to PATH** in every agent's environment, or `composer install`
  fails with a misleading "requires php ^8.5". Fold this into each spawned agent's shell setup.

## Process lessons (from the postmaster poll slice, 2026-08-17 — apply on every build)
- **Gate on BOTH drivers.** `composer ready` is SQLite-only; CI also runs Postgres 16. A Postgres-only bug
  (`SQLSTATE[25P02]`) turned CI red once and would have again. Run the PG container in the coordinator's own
  gate check from the outset, not just SQLite.
- **CI env ≠ dev env.** `/bin/zsh` is absent on `ubuntu-latest`; a shell-portability test that executes
  under zsh must install it in CI *and* skip gracefully when a shell is genuinely absent (don't delete the
  coverage). `sh -n` is a *syntax* check and cannot validate runtime shell semantics.
- **Stage agent briefs INSIDE the worktree.** OpenCode prompts per external directory; a brief living
  elsewhere costs a permission stall (its TUI never registers idle, so the idle-timer can't detect it).
- **Mutation testing:** print the mutated line AND confirm the mutation can actually trip the assertion —
  both a silently-unapplied mutation (green ⇒ false bail) and a mutation that survives for the wrong reason
  (⇒ false blocking finding) occurred here. Restore `vendor/` after any `pest --mutate` shim (it's
  gitignored, so `git status` looks clean regardless).
- **Never merge on a red board, even when you know the cause** (e.g. a GitHub 429 codeload outage) — re-run,
  don't merge past. Re-verify the gate after rebasing onto a squash-merged base (content merges clean while
  history diverges — where a silent semantic conflict hides).
- **Trust the independent implementer's pushback** — it corrected the coordinator's brief twice here and was
  right both times.

## Harness map (role -> runtime; decorrelate model lineages)
- implementer: OpenCode (Solo agent tool — resolve id at spawn via list_agent_tools)
- quality reviewer: Claude (Solo agent tool — different ROLE; only Claude + OpenCode CLIs work here)
- acceptance judge: Claude (Solo agent tool)
- NOTE: decorrelate by ROLE, not model — Codex is unavailable in this Solo env.

## Toolchain conformance — the ride-along rule (STANDING, all projects)
Run `composer ready` as part of finalizing every PR; let whatever it changes ride along in that PR as
a single isolated `composer ready` commit. No separate branch unless the TOOL CONFIG changes (new
Rector rule / pint.json edit) — that gets its own dedicated PR.

## Ship details
- branch naming: feat/<slug>
- PR target repo: artisan-build/capstan
- release / split steps: none yet (capability packages split out later via kibble, per D9)

## Plan & coordination
- plan location: brain design docs (above) + the PRD scratchpad assembled at build kickoff
- Solo project: capstan (id resolved at load)
- run-log: the coordinator's Solo scratchpad, appended at every transition

## Stack notes / quirks
- Starter kit: the **standard Laravel Livewire starter kit**, **FREE Flux only** — NO Flux Pro (this is
  fork-and-self-host OSS; a forker won't have the Pro license). (brain D20.)
- One fork-and-deploy app; server capabilities toggle via **Laravel Pennant** flags whose resolvers read
  **`.env` → `config()`** (config is source of truth, NOT Pennant's DB store). (brain D23.)
- **Pennant features MUST be class-based** (one class per feature with a `resolve()` method), NOT closures
  registered in a service provider. (Ed's strong preference, 2026-08-03.)
- Artifacts = rich HTML kept safe by **origin ISOLATION not sanitization**: a separate cookieless render
  origin (custom domain) + sandboxed opaque-origin iframe + strict CSP; egress a rebindable default-locked
  policy. Blob on the **Flysystem default disk**, metadata in DB; **private storage, app-mediated serve —
  never raw public bucket URLs**. Requires `league/flysystem-aws-s3-v3`; use the default disk, never `AWS_*`.
  (brain D22.)
- Teams = intra-org sub-partition, `artifact_team` **grant pivot**, seeded default team, UI de-emphasized.
  Org roles (owner/admin/member) are a SEPARATE axis from team membership. (brain D21/D25.)
- Auth = unified OAuth + durable token (mirror the Ballast CLI/MCP pattern); a minimal **auth-only Go CLI**
  (`capstan login`) is built alongside — it is the start of the D8 runner binary, not a throwaway. (D24/D26.)
- First-run: `/register` is open until the first user (→ owner, claimed DB-race-safe); invite-code required
  thereafter. (brain D25.)
