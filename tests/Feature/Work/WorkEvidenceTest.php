<?php

use App\Http\ApiErrorException;
use App\Models\Artifact;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkEvidence;
use App\Models\WorkItem;
use App\Models\WorkTask;
use App\Work\WorkEvidenceService;
use App\Work\WorkWorkerLinks;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config([
        'app.url' => 'https://capstan-evidence.test',
        'capstan.artifacts.render_origin' => 'https://render-evidence.test',
        'capstan.work.evidence.max_bytes' => 10 * 1024 * 1024,
        'capstan.work.evidence.max_per_item' => 20,
        'capstan.work.evidence.uploads_per_minute' => 30,
    ]);
    Storage::fake();
});

/** @return array{0: WorkItem, 1: string} */
function evidenceWorkerItem(): array
{
    $team = Team::query()->create(['name' => 'Evidence test', 'slug' => (string) Str::uuid7()]);
    $task = WorkTask::query()->create([
        'team_id' => $team->id,
        'title' => 'Evidence task '.Str::random(8),
        'required_capabilities' => [],
        'timebox_minutes' => 30,
        'creator_actor' => 'evidence-creator',
    ]);
    $actor = 'worker-'.Str::random(12);
    $spoke = Spoke::query()->create(['actor_id' => $actor, 'credential_id' => (string) Str::uuid7()]);
    $item = $task->items()->create([
        'role' => 'builder',
        'state' => 'routed',
        'fence' => 1,
        'claimant_actor' => $actor,
        'claimant_spoke_id' => $spoke->id,
        'claimed_at' => now(),
        'lease_until' => now()->addMinutes(10),
    ]);

    return [$item, resolve(WorkWorkerLinks::class)->forItem($item)['evidence']];
}

/** @return array<string, string> */
function evidenceFiles(): array
{
    return [
        'proof.png' => (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
        'proof.jpg' => (string) base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABALDA4MChAODQ4SERATGCgaGBYWGDEjJR0oOjM9PDkzODdASFxOQERXRTc4UG1RV19iZ2hnPk1xeXBkeFxlZ2P/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAABv/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AHP/Z', true),
        'proof.jpeg' => (string) base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABALDA4MChAODQ4SERATGCgaGBYWGDEjJR0oOjM9PDkzODdASFxOQERXRTc4UG1RV19iZ2hnPk1xeXBkeFxlZ2P/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAABv/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AHP/Z', true),
        'proof.webp' => (string) base64_decode('UklGRhoAAABXRUJQVlA4TA4AAAAvAAAAAAcQEf0PRET/Aw==', true),
        'proof.gif' => (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true),
        'proof.mp4' => pack('N', 24).'ftypisom'.pack('N', 0).'isomiso2',
        'proof.txt' => "test-created evidence\n",
        'proof.json' => '{"test_created":true}',
    ];
}

function evidenceMcpToken(User $user): string
{
    $token = 'work-evidence-'.Str::random(40);
    Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => 'capstan-user:'.$user->id,
        'user_id' => (string) $user->id,
        'name' => 'Evidence test',
        'abilities' => [],
        'secret_hash' => hash('sha256', $token),
        'status' => CredentialStatus::Active,
    ]);

    return $token;
}

function evidenceMcpCall(string $tool, array|object $arguments, string $token, string $path = '/mcp/write'): TestResponse
{
    return test()->postJson($path, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ], ['Authorization' => 'Bearer '.$token]);
}

test('signed evidence upload accepts every allowlisted MIME and serves only permanent token URLs anonymously', function (): void {
    [$item, $url] = evidenceWorkerItem();
    $representations = [];
    foreach (evidenceFiles() as $filename => $bytes) {
        $response = $this->post($url, ['file' => UploadedFile::fake()->createWithContent($filename, $bytes)])
            ->assertCreated();
        $representation = $response->json('evidence');
        expect(array_keys($representation))->toBe(['id', 'task_id', 'item_id', 'url', 'content_type', 'size_bytes', 'created_at'])
            ->and($representation)->not->toContain(null)
            ->and($representation['task_id'])->toBe($item->work_task_id)
            ->and($representation['item_id'])->toBe($item->id)
            ->and(parse_url($representation['url'], PHP_URL_QUERY))->toBeNull();
        $stored = WorkEvidence::query()->findOrFail($representation['id']);
        expect($stored->id[14])->toBe('7')
            ->and(strlen($stored->public_token))->toBe(64)
            ->and($stored->storage_key)->toStartWith('work-evidence/private/');
        Storage::disk()->assertExists($stored->storage_key);
        $representations[$filename] = $representation;
    }

    expect(WorkEvidence::query()->count())->toBe(count(evidenceFiles()));
    $firstFilename = array_key_first(evidenceFiles());
    $first = WorkEvidence::query()->findOrFail($representations[$firstFilename]['id']);
    $public = $this->get($representations[$firstFilename]['url'])
        ->assertOk()
        ->assertStreamed()
        ->assertHeader('Content-Type', $first->content_type)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'inline; filename="work-evidence-'.$first->id.'.'.$first->extension.'"');
    expect($public->streamedContent())->toBe(evidenceFiles()[$firstFilename]);

    foreach ([$first->id, $item->id, $item->work_task_id, 'proof.png'] as $guess) {
        $this->get('/work-evidence/'.$guess)->assertNotFound();
    }
    $this->get('/work/items/'.$item->id.'/evidence')->assertNotFound();
});

test('interactive MCP upload is idempotent and returns the signed ingress representation shape', function (): void {
    $user = capstanUser(['role' => 'member']);
    $team = Team::default();
    $task = WorkTask::query()->create([
        'team_id' => $team->id,
        'title' => 'Interactive evidence',
        'required_capabilities' => [],
        'timebox_minutes' => 30,
        'creator_actor' => (string) $user->id,
    ]);
    $item = $task->items()->create(['role' => 'reviewer']);
    $token = evidenceMcpToken($user);
    $bytes = evidenceFiles()['proof.json'];
    $arguments = [
        'team_id' => $team->id,
        'task_id' => $task->id,
        'item_id' => $item->id,
        'filename' => 'proof.json',
        'bytes_base64' => base64_encode($bytes),
        'idempotency_key' => 'interactive-evidence',
    ];

    $first = evidenceMcpCall('upload_work_evidence', $arguments, $token)->assertOk()->json('result.structuredContent');
    $replay = evidenceMcpCall('upload_work_evidence', $arguments, $token)->assertOk()->json('result.structuredContent');
    expect($first['outcome'])->toBe('accepted')
        ->and($replay['outcome'])->toBe('already_accepted')
        ->and($replay['evidence'])->toBe($first['evidence'])
        ->and(array_keys($first['evidence']))->toBe(['id', 'task_id', 'item_id', 'url', 'content_type', 'size_bytes', 'created_at'])
        ->and(WorkEvidence::query()->count())->toBe(1);
    $stored = WorkEvidence::query()->sole();
    Storage::disk()->assertExists($stored->storage_key);
});

test('wrong type mismatch and oversized signed uploads leave no evidence or blobs', function (string $filename, string $bytes): void {
    [, $url] = evidenceWorkerItem();
    $this->post($url, ['file' => UploadedFile::fake()->createWithContent($filename, $bytes)])
        ->assertUnprocessable();
    expect(WorkEvidence::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);
})->with([
    'wrong extension' => ['proof.pdf', 'test-created PDF-like bytes'],
    'MIME mismatch' => ['proof.png', 'test-created text'],
    'oversized' => ['proof.txt', str_repeat('x', 10 * 1024 * 1024 + 1)],
]);

test('the twenty-first evidence upload is refused without an orphan', function (): void {
    [$item, $url] = evidenceWorkerItem();
    foreach (range(1, 20) as $index) {
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent("proof-{$index}.txt", "evidence {$index}\n")])
            ->assertCreated();
    }
    $before = Storage::disk()->allFiles();
    $this->post($url, ['file' => UploadedFile::fake()->createWithContent('proof-21.txt', "evidence 21\n")])
        ->assertUnprocessable();
    expect($item->evidence()->count())->toBe(20)
        ->and(Storage::disk()->allFiles())->toBe($before);
});

test('evidence rate limits signed workers before storage', function (): void {
    config(['capstan.work.evidence.uploads_per_minute' => 1]);
    [$item, $url] = evidenceWorkerItem();
    $this->post($url, ['file' => UploadedFile::fake()->createWithContent('first.txt', "first\n")])->assertCreated();
    $before = Storage::disk()->allFiles();
    $this->post($url, ['file' => UploadedFile::fake()->createWithContent('second.txt', "second\n")])->assertStatus(429);
    expect($item->evidence()->count())->toBe(1)
        ->and(Storage::disk()->allFiles())->toBe($before);
});

test('evidence rate limits interactive MCP callers without a second blob', function (): void {
    config(['capstan.work.evidence.uploads_per_minute' => 1]);
    $user = capstanUser(['role' => 'member']);
    $team = Team::default();
    $task = WorkTask::query()->create([
        'team_id' => $team->id,
        'title' => 'MCP rate limit',
        'required_capabilities' => [],
        'timebox_minutes' => 30,
        'creator_actor' => (string) $user->id,
    ]);
    $items = [
        $task->items()->create(['role' => 'first']),
        $task->items()->create(['role' => 'second']),
    ];
    $token = evidenceMcpToken($user);
    RateLimiter::clear('work-evidence:'.hash('sha256', 'actor:'.$user->id));
    $upload = fn (WorkItem $item, string $key): array => evidenceMcpCall('upload_work_evidence', [
        'team_id' => $team->id,
        'task_id' => $task->id,
        'item_id' => $item->id,
        'filename' => $key.'.txt',
        'bytes_base64' => base64_encode($key."\n"),
        'idempotency_key' => $key,
    ], $token)->assertOk()->json('result.structuredContent');

    expect($upload($items[0], 'first')['outcome'])->toBe('accepted')
        ->and($upload($items[1], 'second'))->toBe(['outcome' => 'refused', 'reason' => 'evidence_rate_limited'])
        ->and(WorkEvidence::query()->count())->toBe(1)
        ->and(Storage::disk()->allFiles())->toHaveCount(1);
});

test('stale worker and unauthorized MCP evidence uploads have no durable effects', function (): void {
    [$item, $url] = evidenceWorkerItem();
    $item->update(['fence' => 2]);
    $this->post($url, ['file' => UploadedFile::fake()->createWithContent('stale.txt', "stale\n")])->assertConflict();

    $user = capstanUser(['role' => 'owner']);
    $foreign = Team::query()->create(['name' => 'Foreign evidence', 'slug' => (string) Str::uuid7()]);
    $task = WorkTask::query()->create([
        'team_id' => $foreign->id,
        'title' => 'Foreign evidence task',
        'required_capabilities' => [],
        'timebox_minutes' => 30,
        'creator_actor' => 'foreign',
    ]);
    $foreignItem = $task->items()->create(['role' => 'builder']);
    evidenceMcpCall('upload_work_evidence', [
        'team_id' => $foreign->id,
        'task_id' => $task->id,
        'item_id' => $foreignItem->id,
        'filename' => 'foreign.txt',
        'bytes_base64' => base64_encode("foreign\n"),
        'idempotency_key' => 'foreign-evidence',
    ], evidenceMcpToken($user))->assertOk()->assertJsonPath('result.isError', true);

    expect(WorkEvidence::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([])
        ->and(DB::table('mcp_write_claims')->where('tool', 'upload_work_evidence')->count())->toBe(0);
});

test('storage and database failures leave no row or orphaned blob', function (string $failure, string $exception): void {
    [$item] = evidenceWorkerItem();
    $service = resolve(WorkEvidenceService::class);
    $bytes = "failure test\n";
    $metadata = $service->inspect('failure.txt', $bytes);

    if ($failure === 'storage') {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturnFalse();
        $disk->shouldReceive('delete')->once()->andReturnTrue();
        Storage::shouldReceive('disk')->twice()->andReturn($disk);
    } else {
        WorkEvidence::creating(static fn () => throw new RuntimeException('forced database failure'));
    }

    expect(fn () => $service->store($item, $bytes, $metadata))->toThrow($exception);
    expect(WorkEvidence::query()->count())->toBe(0);
    if ($failure === 'database') {
        expect(Storage::disk()->allFiles())->toBe([]);
    }
})->with([
    'storage' => ['storage', ApiErrorException::class],
    'database' => ['database', RuntimeException::class],
]);

test('task detail lists evidence while Pages Artifacts and list_pages do not expose it', function (): void {
    $user = capstanUser(['role' => 'owner']);
    [$item] = evidenceWorkerItem();
    $service = resolve(WorkEvidenceService::class);
    $bytes = "visible only on Work\n";
    $stored = $service->store($item, $bytes, $service->inspect('work-only.txt', $bytes));
    $url = $service->representation($stored)['url'];

    $work = $this->actingAsVersioned($user)->get(route('work.index'))->assertOk();
    $work->assertSee('data-testid="work-task-evidence"', false)
        ->assertSee($stored->id)
        ->assertSee($url, false);
    $pages = $this->get(route('pages.index'))->assertOk();
    $pages->assertDontSee($stored->id)->assertDontSee($url, false);
    $listed = evidenceMcpCall('list_pages', (object) [], evidenceMcpToken($user), '/mcp')->assertOk();
    expect($listed->content())->not->toContain($stored->id)
        ->not->toContain($stored->public_token)
        ->not->toContain($stored->storage_key)
        ->and(Artifact::query()->count())->toBe(0);
});
