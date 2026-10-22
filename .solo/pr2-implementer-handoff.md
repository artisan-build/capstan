# PR2 implementer handoff — reassignment and owner notifications

## State

Implementation is complete and committed on `feat/orchestration-reassignment`.

- Base: `18dd01681f2b4d35d4d8f311a725d59a82940a43`
- Implementation commit: `7852da9f355f2b9158260df615359980ea107ea9` (`Add atomic Work task reassignment`)
- Behavioral verdict: `test-verified`
- Harness: Pest feature tests against SQLite `:memory:` and local PostgreSQL 16-compatible lane
- Push/PR/merge/deploy: not performed

## Delivered

- Added MCP `reassign_task` with exact input validation, durable replay, root-only targeting, owner or same-team Owner/Admin authorization, and active admitted same-team target validation.
- Added atomic subtree reassignment for `move` and `escalations` modes. Ownership and person routing move together so non-pooled descendants cannot remain pinned to the former owner.
- `move` recovers and fences an active orchestrator turn, releases the seat holder/session, preserves live ordinary claims, and routes replacement work only to the new owner.
- `escalations` preserves the live seat and ordinary claims while readdressing every unanswered escalation and queued human pair. Answered escalations remain historical.
- Added one waking `task_reassigned` event and one new-owner database notification per accepted durable call. Notification failure rolls back the reassignment and remains safe to retry.
- Added omitted-address escalation defaulting to the current root owner while retaining explicit active addressees.
- Replaced the Work notifier binding with database delivery that delegates to the existing logger. Existing escalation, stall, heartbeat, pinned-host, and seat-waiting log lines remain.
- Added bounded identifier/state-only notification payloads and explicit recipient rules.
- Preserved the inherited one-active-P0-per-owner invariant during reassignment.
- Updated `docs/work.md` with the two modes, authorization, cascade, seat/fence behavior, escalation addressing, and notifications.

## Changed files

- `.solo/pr2-implementer-handoff.md`
- `app/Mcp/CapstanServer.php`
- `app/Mcp/Tools/ReassignTaskTool.php`
- `app/Mcp/Tools/WorkTool.php`
- `app/Notifications/WorkNotification.php`
- `app/Providers/AppServiceProvider.php`
- `app/Work/DatabaseWorkNotifier.php`
- `app/Work/LogWorkNotifier.php`
- `app/Work/WorkNotifier.php`
- `app/Work/WorkOrchestrationCapability.php`
- `app/Work/WorkOrchestrationService.php`
- `app/Work/WorkOrchestrationSurface.php`
- `app/Work/WorkReassignmentService.php`
- `app/Work/WorkSeatService.php`
- `docs/work.md`
- `tests/Feature/Work/WorkDatabaseNotificationTest.php`
- `tests/Feature/Work/WorkReassignmentTest.php`

## Frozen histories checked before editing

`git log origin/main -- <file>` history was inspected for:

- `app/Mcp/CapstanServer.php`
- `app/Mcp/Tools/WorkTool.php`
- `app/Mcp/Tools/WorkOrchestrationTool.php`
- `app/Machine/TeamMemberships.php`
- `app/Providers/AppServiceProvider.php`
- `app/Work/LogWorkNotifier.php`
- `app/Work/WorkNotifier.php`
- `app/Work/WorkClaimService.php`
- `app/Work/WorkOrchestrationCapability.php`
- `app/Work/WorkOrchestrationLock.php`
- `app/Work/WorkOrchestrationService.php`
- `app/Work/WorkOrchestrationSurface.php`
- `app/Work/WorkSeatService.php`
- `app/Work/WorkToolService.php`
- `docs/work.md`
- `tests/Feature/Work/WorkMcpToolsTest.php`
- `tests/Feature/Work/WorkOrchestrationTest.php`
- `tests/Feature/Work/WorkSweepsTest.php`

New files had no history to inspect.

## Focused verification

### SQLite

Final command:

```sh
PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php85 artisan test --compact \
  tests/Feature/Work/WorkReassignmentTest.php \
  tests/Feature/Work/WorkDatabaseNotificationTest.php \
  tests/Feature/Work/WorkMcpToolsTest.php \
  tests/Feature/Work/WorkOrchestrationTest.php \
  tests/Feature/Work/WorkSweepsTest.php
```

Observed: **156 passed, 10,327 assertions**, 8.17s.

The two new focused files alone also passed: **12 passed, 232 assertions**.

### PostgreSQL

A dedicated local database was created at `127.0.0.1`; the mandatory Solo-cache worktree assertion ran before the database setup and every PostgreSQL test command.

Final command used the same five files with explicit local `DB_CONNECTION=pgsql`, host, port, database, and user settings.

Observed: **156 passed, 10,327 assertions**, 24.83s.

The first PostgreSQL pass found that symbolic legacy/test actors caused a bigint lookup error in the new database notifier. Delivery now rejects non-numeric actor ids before querying `users`, while preserving logging. The complete focused PostgreSQL lane passed after that fix.

### Static analysis and lint

Final commands, with a PHP 8.5 `php` shim prepended to `PATH`:

```sh
composer stan
composer test:lint
```

Observed:

- PHPStan: **passed, 0 errors**
- Pint check: **passed**

The first attempt was not green. Composer spawned PHPStan under the default PHP 8.4 and failed the platform check; Pint also found import/operator formatting in `app/Mcp/Tools/WorkTool.php`. `vendor/bin/pint --parallel` fixed that file, a verified PHP 8.5 shim was added outside the repository, and both final checks passed.

### Documentation tell checker

Command:

```sh
python3 /Users/edgrosvenor/Herd/brain/skills/human-writing/scripts/check-tells.py docs/work.md
```

| Run | Observed result |
| --- | --- |
| Before | 2,391 words, 38 paragraphs, no mechanical tells |
| After | 2,781 words, 44 paragraphs, no mechanical tells |

## Acceptance evidence covered

- Persisted root/descendant ownership and routing cascade.
- Signed stale orchestrator write rejection after `move`.
- Old-owner replacement-turn exclusion and new-owner claim success.
- Live ordinary claim field preservation and successful completion by the former owner's machine.
- Full listed seat-field preservation in `escalations` mode.
- Open escalation plus queued-human readdressing across descendants; answered escalation preservation.
- Default-to-new-root-owner and explicit active escalation addressing with recipient notification checks.
- Root owner Member, same-team Admin, unrelated Member, inactive/offboarded caller, cross-team caller, invalid target, child target, P0 conflict, exact scalar/UUID/enum/note/unknown-field, and replay behavior.
- Exactly one reassignment event and notification on durable replay.
- Correct recipient, one-row delivery, bounded payload exclusions, and existing log emission for every PR2-relevant Work notification.
- Notification insert failure rollback followed by a successful retry.
- Existing non-pooled child pin/refusal/equal-owner tests passed unchanged in the focused Work orchestration file.

## Not run

Per the implementer brief, these were deliberately not run:

- Full test suite
- `composer ready`
- Rector
- Concurrency process suites
- Fresh archive/install gate
- Live browser, drain, or external MCP smoke
- Mutation testing or deliberate source mutation

The coordinator still owns the full SQLite/PostgreSQL gates, `composer ready`, driven/live verification, CI, and review.

## Durable records

- Decision trail: `/Users/edgrosvenor/Herd/brain/projects/capstan/decisions-orchestration-reassignment.tsv`
- Mutation debt ledger: `/Users/edgrosvenor/Herd/brain/projects/capstan/mutation-debt.tsv`
- Open mutation-debt keys:
  - `pr2-reassignment-authorization`
  - `pr2-reassignment-move-fencing`
  - `pr2-reassignment-escalations`
  - `pr2-work-database-notifications`
  - `pr2-escalation-default-owner`

## Unresolved risk

No known implementation defect remains from the focused evidence. The work is below `mutation-verified` and has not been driven through a live client, so the five behavioral areas above remain recorded mutation debt and the user-visible surface is not `live-verified`. The coordinator must run the omitted gates before opening the PR.
