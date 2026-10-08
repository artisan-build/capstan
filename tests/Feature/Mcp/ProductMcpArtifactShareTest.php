<?php

use App\Enums\ArtifactVisibility;
use App\Mcp\WriteFaultInjector;
use App\Models\Artifact;
use App\Models\Team;
use App\Support\ArtifactRenderOrigin;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'app.url' => 'https://app.capstan.test',
        'capstan.features.artifacts' => true,
        'capstan.artifacts.max_content_bytes' => 1_048_576,
        'capstan.artifacts.render_origin' => 'https://artifacts.capstan.test',
    ]);
    Feature::flushCache();
    Storage::fake();
});

function artifactMcpToken(
    User $user,
    ?string $subjectRef = null,
    SubjectType $subjectType = SubjectType::UserPrincipal,
): string {
    $token = 'capstan-artifact-mcp-'.Str::random(40);

    Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => $subjectType,
        'subject_ref' => $subjectRef ?? 'capstan-user:'.$user->getKey(),
        'name' => 'Capstan artifact MCP test',
        'user_id' => (string) $user->getKey(),
        'abilities' => [],
        'secret_hash' => hash('sha256', $token),
        'status' => CredentialStatus::Active,
    ]);

    return $token;
}

/** @param array<string, mixed>|object $arguments */
function artifactMcpCall(array|object $arguments, string $token, string|int $id = 1, string $path = '/mcp/write'): TestResponse
{
    return test()->postJson($path, [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => 'tools/call',
        'params' => ['name' => 'create_artifact_share', 'arguments' => $arguments],
    ], ['Authorization' => 'Bearer '.$token]);
}

function artifactMcpList(string $token, string $path = '/mcp/write'): TestResponse
{
    return test()->postJson($path, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => [],
    ], ['Authorization' => 'Bearer '.$token]);
}

/** @return array<string, mixed> */
function artifactMcpStructured(TestResponse $response): array
{
    $response->assertOk();
    $structured = $response->json('result.structuredContent');

    if (! is_array($structured)) {
        throw new RuntimeException($response->getContent());
    }

    return $structured;
}

/** @return array<string, mixed> */
function artifactMcpArguments(string $key, string $content = '<html><body>Agent artifact</body></html>'): array
{
    return [
        'idempotency_key' => $key,
        'content' => $content,
        'content_type' => 'text/html',
        'visibility' => ArtifactVisibility::SignedUrl->value,
    ];
}

test('advertises the exact artifact share contract only through the write door', function (): void {
    $token = artifactMcpToken(capstanUser());
    $read = collect(artifactMcpList($token, '/mcp')->assertOk()->json('result.tools'))->keyBy('name');
    $write = collect(artifactMcpList($token)->assertOk()->json('result.tools'))->keyBy('name');
    $tool = $write->get('create_artifact_share');

    expect($read->has('create_artifact_share'))->toBeFalse()
        ->and($write->keys()->sort()->values()->all())->toBe([
            'ack_postmaster_messages',
            'create_artifact_share',
            'postmaster_messages',
            'postmaster_spokes',
            'send_postmaster_message',
        ])
        ->and(data_get($tool, '_meta.effect'))->toBe('write')
        ->and(data_get($tool, '_meta.classification'))->toBe('content')
        ->and(data_get($tool, 'annotations.readOnlyHint'))->toBeFalse()
        ->and(data_get($tool, 'annotations.destructiveHint'))->toBeFalse()
        ->and(data_get($tool, 'annotations.idempotentHint'))->toBeTrue()
        ->and(data_get($tool, 'annotations.openWorldHint'))->toBeTrue()
        ->and(data_get($tool, 'inputSchema.additionalProperties'))->toBeFalse()
        ->and(data_get($tool, 'inputSchema.required'))->toBe([
            'idempotency_key',
            'content',
            'content_type',
            'visibility',
        ])
        ->and(data_get($tool, 'inputSchema.properties.content_type.enum'))->toBe(['text/html', 'application/xhtml+xml'])
        ->and(data_get($tool, 'inputSchema.properties.visibility.enum'))->toBe(ArtifactVisibility::values());

    artifactMcpCall(artifactMcpArguments('read-door'), $token, path: '/mcp')
        ->assertBadRequest()
        ->assertJsonPath('error.code', -32000)
        ->assertJsonPath('error.message', 'effect_above_ceiling');
});

test('stores one private artifact and grant then replays accepted state after metadata disappears', function (): void {
    Date::setTestNow('2026-09-30T10:00:00Z');
    $user = capstanUser();
    $token = artifactMcpToken($user);
    $arguments = artifactMcpArguments('artifact-once');
    $arguments['expires_at'] = '2026-10-01T12:34:56.123456+02:30';

    $accepted = artifactMcpStructured(artifactMcpCall($arguments, $token));
    $artifact = Artifact::query()->firstOrFail();
    $contentHash = hash('sha256', $arguments['content']);
    $storedResponse = DB::table('mcp_write_effects')->value('accepted_response');

    expect($accepted['outcome'])->toBe('accepted')
        ->and(data_get($accepted, 'artifact.id'))->toBe($artifact->id)
        ->and(data_get($accepted, 'artifact.expires_at'))->toBe('2026-10-01T10:04:56.000000Z')
        ->and(data_get($accepted, 'artifact.share_url'))->toContain('/artifacts/'.$artifact->id.'/share', 'signature=')
        ->and($artifact->actor_id)->toBe((string) $user->getKey())
        ->and($artifact->teams()->pluck('teams.id')->all())->toBe([Team::default()->id])
        ->and(Storage::disk()->visibility($artifact->storage_key))->toBe('private')
        ->and(Storage::disk()->get($artifact->storage_key))->toBe($arguments['content'])
        ->and(DB::table('mcp_write_effects')->count())->toBe(1)
        ->and($storedResponse)->toBeString();

    $id = $artifact->id;
    $artifact->delete();
    $arguments['expires_at'] = '2026-10-01T10:04:56.999999Z';
    $replay = artifactMcpStructured(artifactMcpCall($arguments, $token));
    $stored = json_decode($storedResponse, true, 512, JSON_THROW_ON_ERROR);

    expect($replay['outcome'])->toBe('already_accepted')
        ->and($replay['artifact'])->toBe($accepted['artifact'])
        ->and($accepted['artifact'])->not->toHaveKey('content_hash')
        ->and($stored['artifact'])->not->toHaveKey('content_hash')
        ->and($replay['artifact'])->not->toHaveKey('content_hash')
        ->and(json_encode($accepted, JSON_THROW_ON_ERROR))->not->toContain($contentHash)
        ->and($storedResponse)->not->toContain($contentHash)
        ->and(json_encode($replay, JSON_THROW_ON_ERROR))->not->toContain($contentHash)
        ->and(Artifact::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles('artifacts'))->toHaveCount(1)
        ->and(DB::table('mcp_write_claims')->where('target_id', $id)->value('state'))->toBe('accepted');
});

test('normalizes expiry into immutable intent and scopes textual keys by owner', function (): void {
    Date::setTestNow('2026-09-30T10:00:00Z');
    $alice = capstanUser();
    $bob = capstanUser();
    $aliceToken = artifactMcpToken($alice);
    $bobToken = artifactMcpToken($bob);
    $arguments = artifactMcpArguments('shared-key');
    $arguments['expires_at'] = '2026-10-01T12:00:00+02:00';

    artifactMcpStructured(artifactMcpCall($arguments, $aliceToken));
    $equivalent = [...$arguments, 'expires_at' => '2026-10-01T10:00:00.000000Z'];
    expect(artifactMcpStructured(artifactMcpCall($equivalent, $aliceToken))['outcome'])->toBe('already_accepted');

    $changed = [...$arguments, 'content' => '<html><body>Changed intent</body></html>'];
    expect(artifactMcpStructured(artifactMcpCall($changed, $aliceToken)))
        ->toBe(['outcome' => 'refused', 'reason' => 'idempotency_key_reused']);

    artifactMcpStructured(artifactMcpCall($changed, $bobToken));
    expect(Artifact::query()->count())->toBe(2)
        ->and(DB::table('mcp_write_claims')->where('idempotency_key', 'shared-key')->count())->toBe(2);
});

test('rejects disabled features and unsupported principals before any durable or blob effect', function (): void {
    $user = capstanUser();
    $token = artifactMcpToken($user);
    config(['capstan.features.artifacts' => false]);
    Feature::flushCache();

    artifactMcpCall(artifactMcpArguments('disabled'), $token)
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'Artifacts are unavailable.');

    config(['capstan.features.artifacts' => true]);
    Feature::flushCache();
    $unsupported = artifactMcpToken($user, 'capstan-user:'.$user->id, SubjectType::ExternalConsumer);
    artifactMcpCall(artifactMcpArguments('unsupported'), $unsupported)
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'This MCP principal is not supported.');

    expect(DB::table('mcp_write_claims')->count())->toBe(0)
        ->and(DB::table('mcp_write_effects')->count())->toBe(0)
        ->and(Artifact::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);
});

test('rejects invalid shapes bytes content types visibility and expiry before durable state', function (): void {
    Date::setTestNow('2026-09-30T10:00:00Z');
    config(['capstan.artifacts.max_content_bytes' => 4]);
    $token = artifactMcpToken(capstanUser());
    $valid = artifactMcpArguments('invalid');
    $valid['content'] = 'test';

    foreach ([
        [],
        (object) ['0' => 'numeric-key'],
        [...$valid, 'unexpected' => true],
        [...$valid, 'idempotency_key' => null],
        [...$valid, 'content' => ''],
        [...$valid, 'content' => 'ééa'],
        [...$valid, 'content_type' => 'text/plain'],
        [...$valid, 'visibility' => 'public'],
        [...$valid, 'expires_at' => null],
        [...$valid, 'expires_at' => 123],
        [...$valid, 'expires_at' => 'not-a-date'],
        [...$valid, 'expires_at' => '2026-02-30T12:00:00Z'],
        [...$valid, 'expires_at' => '2026-09-30T09:59:59Z'],
        [...$valid, 'expires_at' => '2026-10-01 10:00:00Z'],
        [...$valid, 'expires_at' => '2026-10-01T10:00:00.1234567Z'],
    ] as $arguments) {
        $response = artifactMcpCall($arguments, $token);
        expect($response->status())->toBeIn([200, 400]);

        if ($response->status() === 200) {
            expect($response->json('result.isError'))->toBeTrue();
        } else {
            expect($response->json('error'))->toBeArray();
        }
    }

    expect(DB::table('mcp_write_claims')->count())->toBe(0)
        ->and(DB::table('mcp_write_effects')->count())->toBe(0)
        ->and(Artifact::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);

    $unicode = [...$valid, 'idempotency_key' => 'four-byte-unicode', 'content' => 'éé'];
    expect(artifactMcpStructured(artifactMcpCall($unicode, $token))['outcome'])->toBe('accepted')
        ->and(Artifact::query()->firstOrFail()->size_bytes)->toBe(4);
});

test('rejects impossible numeric offset components through stable validation before any effect', function (): void {
    Date::setTestNow('2026-09-30T10:00:00Z');
    $token = artifactMcpToken(capstanUser());
    $responses = [];

    foreach (['+24:00', '+00:60', '+99:99'] as $index => $offset) {
        $arguments = artifactMcpArguments('invalid-offset-'.$index);
        $arguments['expires_at'] = '2026-10-05T12:00:00'.$offset;
        $responses[] = artifactMcpCall($arguments, $token)->assertOk();
    }

    expect(DB::table('mcp_write_claims')->count())->toBe(0)
        ->and(DB::table('mcp_write_effects')->count())->toBe(0)
        ->and(Artifact::query()->count())->toBe(0)
        ->and(DB::table('artifact_team')->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);

    foreach ($responses as $response) {
        $response->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'The expires at field must be a valid RFC 3339 timestamp.');
    }
});

test('requires the canonical second precision expiry to remain future before creating state', function (): void {
    Date::setTestNow('2026-09-30T10:00:00.500000Z');
    $token = artifactMcpToken(capstanUser());
    $sameSecond = artifactMcpArguments('same-second-expiry');
    $sameSecond['expires_at'] = '2026-09-30T10:00:00.900000Z';

    artifactMcpCall($sameSecond, $token)
        ->assertOk()
        ->assertJsonPath('result.isError', true);

    expect(DB::table('mcp_write_claims')->count())->toBe(0)
        ->and(DB::table('mcp_write_effects')->count())->toBe(0)
        ->and(Artifact::query()->count())->toBe(0)
        ->and(DB::table('artifact_team')->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);

    $nextSecond = artifactMcpArguments('next-second-expiry');
    $nextSecond['expires_at'] = '2026-09-30T10:00:01.900000Z';
    $accepted = artifactMcpStructured(artifactMcpCall($nextSecond, $token));

    expect($accepted['outcome'])->toBe('accepted')
        ->and(data_get($accepted, 'artifact.expires_at'))->toBe('2026-09-30T10:00:01.000000Z')
        ->and(DB::table('mcp_write_claims')->count())->toBe(1)
        ->and(DB::table('mcp_write_effects')->count())->toBe(1)
        ->and(Artifact::query()->count())->toBe(1)
        ->and(DB::table('artifact_team')->count())->toBe(1)
        ->and(Storage::disk()->allFiles('artifacts'))->toHaveCount(1);
});

test('leaves no state or blob on a pre claim fault and accepts a retry', function (): void {
    $token = artifactMcpToken(capstanUser());
    $arguments = artifactMcpArguments('preclaim');
    app()->instance(WriteFaultInjector::class, new class extends WriteFaultInjector
    {
        public function beforeClaim(string $tool): void
        {
            throw new RuntimeException('secret artifact preclaim failure');
        }
    });

    $failed = artifactMcpCall($arguments, $token)->assertOk();
    expect($failed->json('result.isError'))->toBeTrue()
        ->and($failed->getContent())->not->toContain('secret artifact preclaim failure')
        ->and(DB::table('mcp_write_claims')->count())->toBe(0)
        ->and(DB::table('mcp_write_effects')->count())->toBe(0)
        ->and(Artifact::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);

    app()->instance(WriteFaultInjector::class, new WriteFaultInjector);
    expect(artifactMcpStructured(artifactMcpCall($arguments, $token))['outcome'])->toBe('accepted');
});

test('replays a known artifact effect after primary and fallback settlement failures', function (): void {
    $token = artifactMcpToken(capstanUser());
    $arguments = artifactMcpArguments('settlement-recovery');
    app()->instance(WriteFaultInjector::class, new class extends WriteFaultInjector
    {
        public function beforeSettlement(string $tool): void
        {
            throw new RuntimeException('primary artifact settlement failure');
        }

        public function beforeAcceptedRecoverySettlement(string $tool): void
        {
            throw new RuntimeException('fallback artifact settlement failure');
        }
    });

    $accepted = artifactMcpStructured(artifactMcpCall($arguments, $token));
    $artifact = Artifact::query()->firstOrFail();
    expect($accepted['outcome'])->toBe('accepted')
        ->and(DB::table('mcp_write_claims')->value('state'))->toBe('in_progress')
        ->and(DB::table('mcp_write_effects')->count())->toBe(1)
        ->and($artifact->teams()->count())->toBe(1);

    $artifact->delete();
    app()->instance(WriteFaultInjector::class, new WriteFaultInjector);
    $replay = artifactMcpStructured(artifactMcpCall($arguments, $token));

    expect($replay['outcome'])->toBe('already_accepted')
        ->and($replay['artifact'])->toBe($accepted['artifact'])
        ->and(DB::table('mcp_write_claims')->value('state'))->toBe('accepted')
        ->and(DB::table('mcp_write_effects')->count())->toBe(1)
        ->and(Artifact::query()->count())->toBe(0);
});

test('refuses target identity collisions without overwriting and reports content addressed orphan residue', function (): void {
    $user = capstanUser();
    $token = artifactMcpToken($user);
    $arguments = artifactMcpArguments('identity-collision', '<html><body>Candidate</body></html>');
    app()->instance(WriteFaultInjector::class, new class extends WriteFaultInjector
    {
        public function beforeOperation(string $tool): void
        {
            $targetId = DB::table('mcp_write_claims')->where('tool', $tool)->value('target_id');
            [$hash, $key] = Artifact::storeBlob('<html><body>Existing</body></html>');
            Artifact::factory()->create([
                'id' => $targetId,
                'actor_id' => 'collision-owner',
                'content_type' => 'text/html',
                'size_bytes' => strlen('<html><body>Existing</body></html>'),
                'content_hash' => $hash,
                'storage_key' => $key,
            ]);
        }
    });

    $refused = artifactMcpStructured(artifactMcpCall($arguments, $token));
    $existing = Artifact::query()->firstOrFail();

    expect($refused)->toBe(['outcome' => 'refused', 'reason' => 'artifact_identity_conflict'])
        ->and(artifactMcpStructured(artifactMcpCall($arguments, $token)))->toBe($refused)
        ->and(Artifact::query()->count())->toBe(1)
        ->and($existing->actor_id)->toBe('collision-owner')
        ->and(Storage::disk()->get($existing->storage_key))->toBe('<html><body>Existing</body></html>')
        ->and(Storage::disk()->allFiles('artifacts'))->toHaveCount(2)
        ->and(DB::table('mcp_write_effects')->count())->toBe(0);
});

test('returns a bounded redacted share whose signed viewer reaches isolated content and expires', function (): void {
    Date::setTestNow('2026-09-30T10:00:00Z');
    $token = artifactMcpToken(capstanUser());
    $content = '<html><body><script>window.proof = true</script>Isolated artifact</body></html>';
    $arguments = artifactMcpArguments('render-proof', $content);
    $arguments['expires_at'] = '2026-09-30T11:00:00Z';
    $response = artifactMcpCall($arguments, $token, str_repeat('i', 254));
    $accepted = artifactMcpStructured($response);
    $artifact = Artifact::query()->firstOrFail();
    $encoded = $response->getContent();

    expect(strlen($encoded))->toBeLessThan(1_048_576)
        ->and($encoded)->not->toContain('storage_key', 'claim_id', 'intent_hash', 'fence_token', 'AWS_', 'APP_KEY')
        ->and(data_get($accepted, 'artifact.share_url'))->toBe(resolve(ArtifactRenderOrigin::class)->signedViewerUrl($artifact));

    $viewer = $this->get(data_get($accepted, 'artifact.share_url'))
        ->assertOk()
        ->assertSee('sandbox="allow-scripts"', false)
        ->assertDontSee('allow-same-origin', false);
    $contentUrl = resolve(ArtifactRenderOrigin::class)->signedContentUrl($artifact);
    $rendered = $this->get($contentUrl)->assertOk()->assertStreamed()->assertHeaderMissing('Set-Cookie');
    expect($viewer->content())->toContain('https://artifacts.capstan.test/artifacts/'.$artifact->id.'/content')
        ->and($rendered->streamedContent())->toBe($content);

    $this->travelTo('2026-09-30T11:00:01Z');
    $this->get($contentUrl)->assertNotFound()->assertDontSee('Isolated artifact');
});

test('refuses the write tool when there is no isolated render origin to serve the artifact from', function (): void {
    $user = capstanUser();
    $token = artifactMcpToken($user);

    // Same host as APP_URL: a derived render origin on an install with no
    // custom domain. The flag is left ON throughout to prove the origin alone
    // closes the tool.
    config(['capstan.artifacts.render_origin' => 'https://app.capstan.test']);
    Feature::flushCache();

    artifactMcpCall(artifactMcpArguments('same-host'), $token)
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'Artifacts are unavailable.');

    config(['capstan.artifacts.render_origin' => null]);
    Feature::flushCache();

    artifactMcpCall(artifactMcpArguments('unset-origin'), $token)
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'Artifacts are unavailable.');

    expect(Feature::active(App\Features\Artifacts::class))->toBeFalse()
        ->and(DB::table('mcp_write_claims')->count())->toBe(0)
        ->and(DB::table('mcp_write_effects')->count())->toBe(0)
        ->and(Artifact::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);
});
