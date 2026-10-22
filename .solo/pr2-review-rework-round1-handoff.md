# PR2 round-1 review rework handoff

## Candidate

- Branch: `feat/orchestration-reassignment`
- Rework commit: `5ac70b8` (`Separate Work escalation assignment`)
- Starting candidate: `f43d852e11d90134990a80962d2acfda64654d03`
- Verdict: `test-verified`
- Push, PR, merge, and deploy: not performed

## Accepted blockers resolved

### Mode-correct ownership, escalation assignment, and device pins

- Added nullable `work_tasks.escalation_actor`. Existing and new null values use `owner_actor` as the effective fallback, avoiding a bulk data rewrite.
- Descendants inherit the root's explicit escalation actor. Task and orchestration representations expose the effective value.
- `move` updates work ownership, escalation assignment, and future person routing across the subtree. Pins owned by the target remain; incompatible or missing-device pins are cleared. Live ordinary claims are untouched, while the old orchestrator seat and item fences are invalidated as before.
- `escalations` updates only escalation assignment and open escalation pairs. Work owner, person routing, device pins, seat/session/lease/fence/active turn, and live ordinary claims remain unchanged. Newly queued work and new non-pooled children continue to route to the old owner's machines.
- Omitted escalation addresses, owner signals, circuit-breaker escalations, reassignment notifications, stalled-item alerts, pinned-host alerts, and seat-waiting alerts use the effective escalation actor. Machine heartbeat alerts still go to the machine owner.

### Retryable notification rollback

- A database-notification `QueryException` during reassignment is converted to a narrow `RetryableWriteException` only after the operation transaction rolls back.
- `DurableWriteCoordinator` releases only the still-fenced, in-progress claim when no accepted effect exists, then returns the stable transient MCP error `The write service is temporarily unavailable.`
- If claim ownership or effect state is ambiguous, the existing conservative `outcome_unknown` settlement remains.
- The HTTP regression drops notification storage, calls `/mcp/write`, verifies ownership/escalation/seat/item/event/effect rollback and claim release, restores storage, then retries identical arguments and idempotency key. The retry creates one accepted effect, one reassignment event, and one notification.

## Changed files

- `app/Mcp/DurableWriteCoordinator.php`
- `app/Mcp/RetryableWriteException.php`
- `app/Mcp/Tools/WorkOrchestrationTool.php`
- `app/Mcp/Tools/WorkTool.php`
- `app/Models/WorkTask.php`
- `app/Work/DatabaseWorkNotifier.php`
- `app/Work/WorkOrchestrationCapability.php`
- `app/Work/WorkOrchestrationService.php`
- `app/Work/WorkReassignmentService.php`
- `app/Work/WorkRepresentation.php`
- `database/migrations/2026_10_22_010000_add_escalation_actor_to_work_tasks.php`
- `docs/work.md`
- `tests/Feature/Work/WorkDatabaseNotificationTest.php`
- `tests/Feature/Work/WorkReassignmentTest.php`

## Focused evidence

### SQLite

```sh
"$HOME/Library/Application Support/Herd/bin/php85" artisan test --compact \
  tests/Feature/Work/WorkReassignmentTest.php \
  tests/Feature/Work/WorkDatabaseNotificationTest.php \
  tests/Feature/Work/WorkMcpToolsTest.php \
  tests/Feature/Work/WorkOrchestrationTest.php \
  tests/Feature/Work/WorkOrchestrationFoundationTest.php \
  tests/Feature/Work/WorkSweepsTest.php \
  tests/Feature/Mcp/ProductMcpWriteTest.php
```

Observed: **191 passed, 10,897 assertions**, 18.52s.

### PostgreSQL

The mandatory Solo-cache worktree guard passed. The same seven files ran with PHP 8.5 and explicit local test settings: `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_DATABASE=capstan_pr2_4888`, and the local test user. The local test password is intentionally omitted from this committed report.

Observed: **191 passed, 10,897 assertions**, 51.08s.

### Static analysis and lint

```sh
PATH="/private/var/folders/t7/54bvztf142x0bppp3vstxfzh0000gn/T/opencode/capstan-pr2-php85-bin:$PATH" composer stan
PATH="/private/var/folders/t7/54bvztf142x0bppp3vstxfzh0000gn/T/opencode/capstan-pr2-php85-bin:$PATH" composer test:lint
```

- PHPStan: **passed, 0 errors**
- Pint: **passed**

### Documentation checker

```sh
python3 /Users/edgrosvenor/Herd/brain/skills/human-writing/scripts/check-tells.py docs/work.md
```

- Before: 2,781 words, 44 paragraphs, no mechanical tells
- After: 2,800 words, 45 paragraphs, no mechanical tells

## Explicitly not run

Per the rework brief, the full suite, `composer ready`, Rector, concurrency process suites, fresh installation, live client verification, and mutation testing were **not run**. The coordinator owns both full database gates after this rework.

No mutation-debt or decision-trail records were written.
