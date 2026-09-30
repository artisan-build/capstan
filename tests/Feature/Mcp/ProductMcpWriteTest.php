<?php

use App\Enums\MessageStatus;
use App\Mcp\CapstanServer;
use App\Mcp\WriteFaultInjector;
use App\Models\Envelope;
use App\Models\Inbox;
use App\Support\JsonCanonicalizer;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\Hmac\SigningRootLifecycle;
use ArtisanBuild\BuiltForCloud\Hmac\SigningRootMac;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedTools;
use ArtisanBuild\BuiltForCloud\Testing\McpProductAdmission;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;

const MCP_WRITE_SERVER_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const MCP_WRITE_FOREIGN_SERVER_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAW';

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('w', 32)),
        'capstan.features.postmaster' => true,
        'capstan.postmaster.server_id' => MCP_WRITE_SERVER_ID,
    ]);
    Feature::flushCache();
    resolve(SigningRootLifecycle::class)->provision();
});

function capstanMcpWriteToken(
    User $user,
    ?string $subjectRef = null,
    SubjectType $subjectType = SubjectType::UserPrincipal,
): string {
    $token = 'capstan-mcp-write-'.Str::random(40);

    Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => $subjectType,
        'subject_ref' => $subjectRef ?? 'capstan-user:'.$user->getKey(),
        'name' => 'Capstan MCP write test',
        'user_id' => (string) $user->getKey(),
        'abilities' => [],
        'secret_hash' => hash('sha256', $token),
        'status' => CredentialStatus::Active,
    ]);

    return $token;
}

/** @param array<string, mixed>|object $arguments */
function capstanMcpWriteCall(
    string $tool,
    array|object $arguments,
    string $token,
    string|int $id = 1,
    string $path = '/mcp/write',
): TestResponse {
    return test()->postJson($path, [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ], ['Authorization' => 'Bearer '.$token]);
}

function capstanMcpWriteList(string $token, string $path = '/mcp/write'): TestResponse
{
    return test()->postJson($path, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => [],
    ], ['Authorization' => 'Bearer '.$token]);
}

/** @return array<string, mixed> */
function capstanMcpWriteStructured(TestResponse $response): array
{
    $response->assertOk();
    $structured = $response->json('result.structuredContent');

    if (! is_array($structured)) {
        throw new RuntimeException($response->getContent());
    }

    return $structured;
}

/** @return array<string, mixed> */
function capstanMcpWriteSendArguments(string $key, array|object|null $body = null): array
{
    return [
        'idempotency_key' => $key,
        'from' => 'sender@'.MCP_WRITE_SERVER_ID,
        'to' => 'receiver@'.MCP_WRITE_SERVER_ID,
        'type' => 'generic',
        'version' => Envelope::CURRENT_VERSION,
        'message_id' => 'message-'.Str::uuid(),
        'body' => $body ?? (object) ['subject' => 'Hello'],
    ];
}

test('mounts the Postmaster write family behind the write ceiling without artifact leakage', function (): void {
    $token = capstanMcpWriteToken(capstanUser());

    McpDelegatedTools::assertConforms(CapstanServer::class);
    McpProductAdmission::assert();

    $read = collect(capstanMcpWriteList($token, '/mcp')->assertOk()->json('result.tools'))->keyBy('name');
    $write = collect(capstanMcpWriteList($token)->assertOk()->json('result.tools'))->keyBy('name');

    expect($read->keys()->sort()->values()->all())->toBe(['postmaster_messages', 'postmaster_spokes'])
        ->and($write->keys()->sort()->values()->all())->toBe([
            'ack_postmaster_messages',
            'postmaster_messages',
            'postmaster_spokes',
            'send_postmaster_message',
        ])
        ->and($write->has('create_artifact_share'))->toBeFalse();

    foreach ([
        'send_postmaster_message' => ['content', false, false, true],
        'ack_postmaster_messages' => ['metadata', false, false, true],
    ] as $name => [$classification, $readOnly, $destructive, $idempotent]) {
        $tool = $write->get($name);
        expect(data_get($tool, '_meta.effect'))->toBe('write')
            ->and(data_get($tool, '_meta.classification'))->toBe($classification)
            ->and(data_get($tool, 'annotations.readOnlyHint'))->toBe($readOnly)
            ->and(data_get($tool, 'annotations.destructiveHint'))->toBe($destructive)
            ->and(data_get($tool, 'annotations.idempotentHint'))->toBe($idempotent)
            ->and(data_get($tool, 'inputSchema.additionalProperties'))->toBeFalse();
    }

    capstanMcpWriteCall('send_postmaster_message', (object) [], $token, path: '/mcp')
        ->assertBadRequest()
        ->assertJsonPath('error.code', -32000)
        ->assertJsonPath('error.message', 'effect_above_ceiling');
});

test('sends and acknowledges once with canonical replay and shared signing policy', function (): void {
    $user = capstanUser();
    $token = capstanMcpWriteToken($user);
    Inbox::query()->create(['actor_id' => (string) $user->id, 'local_part' => 'sender']);
    Inbox::query()->create(['actor_id' => (string) $user->id, 'local_part' => 'receiver']);
    $body = new stdClass;
    $body->{'0'} = 'numeric object key';
    $arguments = capstanMcpWriteSendArguments('send-once', $body);

    $accepted = capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $arguments, $token));
    $replayed = capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $arguments, $token));
    $envelope = Envelope::query()->firstOrFail();

    expect($accepted['outcome'])->toBe('accepted')
        ->and($replayed['outcome'])->toBe('already_accepted')
        ->and($replayed['message'])->toBe($accepted['message'])
        ->and(Envelope::query()->count())->toBe(1)
        ->and(DB::table('mcp_write_claims')->value('target_id'))->toBe($envelope->id)
        ->and(resolve(SigningRootMac::class)->verify(
            $envelope->signing_key_id,
            JsonCanonicalizer::encode($envelope->signablePayload()),
            $envelope->signature,
        ))->toBeTrue();

    $ack = capstanMcpWriteStructured(capstanMcpWriteCall('ack_postmaster_messages', [
        'idempotency_key' => 'ack-once',
        'message_ids' => ['missing-message', $arguments['message_id']],
    ], $token));
    $ackedAt = Envelope::query()->firstOrFail()->acked_at;
    Date::setTestNow(now()->addMinute());
    $ackReplay = capstanMcpWriteStructured(capstanMcpWriteCall('ack_postmaster_messages', [
        'idempotency_key' => 'ack-once',
        'message_ids' => [$arguments['message_id'], 'missing-message'],
    ], $token));

    expect($ack)->toMatchArray(['outcome' => 'accepted', 'requested_count' => 2, 'acknowledged_count' => 1])
        ->and($ackReplay['outcome'])->toBe('already_accepted')
        ->and((string) Envelope::query()->firstOrFail()->acked_at)->toBe((string) $ackedAt);
});

test('scopes writes and textual idempotency keys by type qualified actor', function (): void {
    $alice = capstanUser();
    $bob = capstanUser();
    $aliceToken = capstanMcpWriteToken($alice);
    $bobToken = capstanMcpWriteToken($bob);
    Inbox::query()->create(['actor_id' => (string) $alice->id, 'local_part' => 'sender']);
    Inbox::query()->create(['actor_id' => (string) $bob->id, 'local_part' => 'bob']);
    $arguments = capstanMcpWriteSendArguments('shared-key');

    capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $arguments, $aliceToken));
    $arguments['from'] = 'bob@'.MCP_WRITE_SERVER_ID;
    $arguments['to'] = 'bob@'.MCP_WRITE_SERVER_ID;
    $arguments['message_id'] = 'bob-message';
    capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $arguments, $bobToken));

    expect(Envelope::query()->count())->toBe(2)
        ->and(DB::table('mcp_write_claims')->where('idempotency_key', 'shared-key')->count())->toBe(2);

    $unsupported = capstanMcpWriteToken($alice, 'capstan-user:'.$alice->id, SubjectType::ExternalConsumer);
    capstanMcpWriteCall('send_postmaster_message', capstanMcpWriteSendArguments('unsupported'), $unsupported)
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'This MCP principal is not supported.');
});

test('never acknowledges foreign messages and makes foreign and missing ids indistinguishable', function (): void {
    $alice = capstanUser();
    $bob = capstanUser();
    $aliceToken = capstanMcpWriteToken($alice);
    $bobToken = capstanMcpWriteToken($bob);
    Inbox::query()->create(['actor_id' => (string) $alice->id, 'local_part' => 'sender']);
    Inbox::query()->create(['actor_id' => (string) $alice->id, 'local_part' => 'alice']);
    Inbox::query()->create(['actor_id' => (string) $bob->id, 'local_part' => 'bob']);
    $arguments = capstanMcpWriteSendArguments('foreign-message');
    $arguments['to'] = 'alice@'.MCP_WRITE_SERVER_ID;
    $arguments['message_id'] = 'alice-message';
    capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $arguments, $aliceToken));

    $foreign = capstanMcpWriteStructured(capstanMcpWriteCall('ack_postmaster_messages', [
        'idempotency_key' => 'foreign-ack',
        'message_ids' => ['alice-message'],
    ], $bobToken));
    $missing = capstanMcpWriteStructured(capstanMcpWriteCall('ack_postmaster_messages', [
        'idempotency_key' => 'missing-ack',
        'message_ids' => ['missing-message'],
    ], $bobToken));

    unset($foreign['outcome'], $missing['outcome']);
    expect($foreign)->toBe($missing)
        ->and(Envelope::query()->firstOrFail()->status)->toBe(MessageStatus::Pending)
        ->and(Envelope::query()->firstOrFail()->acked_at)->toBeNull();
});

test('settles immutable intent conflicts refusals and unknown outcomes durably', function (): void {
    $user = capstanUser();
    $token = capstanMcpWriteToken($user);
    Inbox::query()->create(['actor_id' => (string) $user->id, 'local_part' => 'sender']);
    $arguments = capstanMcpWriteSendArguments('immutable-key');

    capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $arguments, $token));
    $changed = $arguments;
    $changed['to'] = 'other@'.MCP_WRITE_FOREIGN_SERVER_ID;
    expect(capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $changed, $token)))
        ->toBe(['outcome' => 'refused', 'reason' => 'idempotency_key_reused']);

    $notOwned = capstanMcpWriteSendArguments('not-owned');
    $notOwned['from'] = 'absent@'.MCP_WRITE_SERVER_ID;
    $refused = capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $notOwned, $token));
    expect(capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $notOwned, $token)))
        ->toBe($refused)
        ->and($refused)->toBe(['outcome' => 'refused', 'reason' => 'sender_not_owned']);

    app()->instance(WriteFaultInjector::class, new class extends WriteFaultInjector
    {
        public function beforeOperation(string $tool): void
        {
            throw new RuntimeException('secret operation failure');
        }

        public function beforeFailureSettlement(string $tool): void
        {
            throw new RuntimeException('secret settlement failure');
        }
    });
    $compound = capstanMcpWriteSendArguments('compound');
    expect(capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $compound, $token)))
        ->toBe(['outcome' => 'in_progress']);

    app()->instance(WriteFaultInjector::class, new WriteFaultInjector);
    expect(capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $compound, $token)))
        ->toBe(['outcome' => 'in_progress']);
    DB::table('mcp_write_claims')->where('idempotency_key', 'compound')->update(['lease_expires_at' => now()->subSecond()]);
    expect(capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $compound, $token)))
        ->toBe(['outcome' => 'outcome_unknown'])
        ->and(capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $compound, $token)))
        ->toBe(['outcome' => 'outcome_unknown']);
});

test('repairs a known accepted effect after both settlement attempts fail', function (): void {
    $user = capstanUser();
    $token = capstanMcpWriteToken($user);
    Inbox::query()->create(['actor_id' => (string) $user->id, 'local_part' => 'sender']);
    $arguments = capstanMcpWriteSendArguments('accepted-recovery');
    app()->instance(WriteFaultInjector::class, new class extends WriteFaultInjector
    {
        public function beforeSettlement(string $tool): void
        {
            throw new RuntimeException('primary settlement failed');
        }

        public function beforeAcceptedRecoverySettlement(string $tool): void
        {
            throw new RuntimeException('fallback settlement failed');
        }
    });

    $accepted = capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $arguments, $token));
    expect($accepted['outcome'])->toBe('accepted')
        ->and(DB::table('mcp_write_claims')->value('state'))->toBe('in_progress')
        ->and(DB::table('mcp_write_effects')->count())->toBe(1)
        ->and(Envelope::query()->count())->toBe(1);

    app()->instance(WriteFaultInjector::class, new WriteFaultInjector);
    $replay = capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $arguments, $token));
    expect($replay['outcome'])->toBe('already_accepted')
        ->and($replay['message'])->toBe($accepted['message'])
        ->and(DB::table('mcp_write_claims')->value('state'))->toBe('accepted')
        ->and(Envelope::query()->count())->toBe(1);
});

test('refuses message identity collisions without overwriting accepted content', function (): void {
    $user = capstanUser();
    $token = capstanMcpWriteToken($user);
    Inbox::query()->create(['actor_id' => (string) $user->id, 'local_part' => 'sender']);
    $first = capstanMcpWriteSendArguments('collision-first');
    $first['message_id'] = 'collision-id';
    $second = capstanMcpWriteSendArguments('collision-second');
    $second['message_id'] = 'collision-id';
    $second['to'] = 'remote@'.MCP_WRITE_FOREIGN_SERVER_ID;
    capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $first, $token));

    $collision = capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $second, $token));
    expect($collision)->toBe(['outcome' => 'refused', 'reason' => 'message_identity_conflict'])
        ->and(capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $second, $token)))->toBe($collision)
        ->and(Envelope::query()->count())->toBe(1)
        ->and(Envelope::query()->firstOrFail()->to_address)->toBe($first['to']);
});

test('rejects malformed closed inputs before claims or effects', function (): void {
    $user = capstanUser();
    $token = capstanMcpWriteToken($user);
    Inbox::query()->create(['actor_id' => (string) $user->id, 'local_part' => 'sender']);
    $send = capstanMcpWriteSendArguments('invalid-send');
    $floatBody = (object) ['score' => 1.5];

    foreach ([
        ['send_postmaster_message', [...$send, 'signature' => str_repeat('0', 64)]],
        ['send_postmaster_message', [...$send, 'body' => ['list-not-object']]],
        ['send_postmaster_message', [...$send, 'body' => $floatBody]],
        ['send_postmaster_message', [...$send, 'body' => (object) ['content' => str_repeat('x', 262_145)]]],
        ['ack_postmaster_messages', ['idempotency_key' => 'duplicates', 'message_ids' => ['one', 'one']]],
        ['ack_postmaster_messages', ['idempotency_key' => 'object', 'message_ids' => (object) ['0' => 'one']]],
        ['ack_postmaster_messages', ['idempotency_key' => 'too-many', 'message_ids' => array_fill(0, 1001, 'x')]],
    ] as [$tool, $arguments]) {
        $response = capstanMcpWriteCall($tool, $arguments, $token);
        expect($response->status())->toBeIn([200, 400]);

        if ($response->status() === 200) {
            expect($response->json('result.isError'))->toBeTrue();
        } else {
            expect($response->json('error'))->toBeArray();
        }
    }

    expect(DB::table('mcp_write_claims')->count())->toBe(0)
        ->and(DB::table('mcp_write_effects')->count())->toBe(0)
        ->and(Envelope::query()->count())->toBe(0);
});

test('feature and pre claim failures are bounded and retryable without durable state', function (): void {
    $user = capstanUser();
    $token = capstanMcpWriteToken($user);
    Inbox::query()->create(['actor_id' => (string) $user->id, 'local_part' => 'sender']);
    $arguments = capstanMcpWriteSendArguments('preclaim');
    config(['capstan.features.postmaster' => false]);
    Feature::flushCache();

    capstanMcpWriteCall('send_postmaster_message', $arguments, $token)
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'Postmaster is unavailable.');
    capstanMcpWriteCall('ack_postmaster_messages', [
        'idempotency_key' => 'disabled-ack',
        'message_ids' => ['missing'],
    ], $token)
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'Postmaster is unavailable.');
    expect(DB::table('mcp_write_claims')->count())->toBe(0);

    config(['capstan.features.postmaster' => true]);
    Feature::flushCache();
    app()->instance(WriteFaultInjector::class, new class extends WriteFaultInjector
    {
        public function beforeClaim(string $tool): void
        {
            throw new RuntimeException('secret preclaim failure');
        }
    });
    $failed = capstanMcpWriteCall('send_postmaster_message', $arguments, $token)->assertOk();
    expect($failed->json('result.isError'))->toBeTrue()
        ->and($failed->getContent())->not->toContain('secret preclaim failure')
        ->and(DB::table('mcp_write_claims')->count())->toBe(0);

    app()->instance(WriteFaultInjector::class, new WriteFaultInjector);
    expect(capstanMcpWriteStructured(capstanMcpWriteCall('send_postmaster_message', $arguments, $token))['outcome'])
        ->toBe('accepted');
});

test('keeps maximal id responses bounded and redacts durable and signing internals', function (): void {
    $user = capstanUser();
    $token = capstanMcpWriteToken($user);
    Inbox::query()->create(['actor_id' => (string) $user->id, 'local_part' => 'sender']);
    $arguments = capstanMcpWriteSendArguments('bounded-response', (object) ['content' => str_repeat('\\', 100_000)]);
    $arguments['to'] = 'remote@'.MCP_WRITE_FOREIGN_SERVER_ID;
    $response = capstanMcpWriteCall('send_postmaster_message', $arguments, $token, str_repeat('i', 254));
    $accepted = capstanMcpWriteStructured($response);

    expect($accepted['outcome'])->toBe('accepted')
        ->and(data_get($accepted, 'message.status'))->toBe(MessageStatus::PendingRelay->value)
        ->and($response->getContent())->not->toContain('claim_id', 'intent_hash', 'fence_token', 'signing_key_id', 'storage_key', 'APP_KEY')
        ->and(strlen($response->getContent()))->toBeLessThan(1_048_576);
});
