<?php

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Features\Postmaster;
use App\Models\Envelope;
use App\Models\Spoke;
use App\Models\User;
use App\Support\EnvelopeSigner;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

const POLL_SERVER_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const POLL_FOREIGN_SERVER_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAW';

beforeEach(function (): void {
    config([
        'capstan.features.postmaster' => true,
        'capstan.postmaster.server_id' => POLL_SERVER_ID,
        'capstan.postmaster.signing_key' => 'poll-test-signing-key',
        'capstan.postmaster.poll.max_inbound' => 50,
    ]);
    Feature::flushCache();
});

function spokeToken(User $user): string
{
    return $user->createToken('capstan-cli')->plainTextToken;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pollWireEnvelope(string $to, array $overrides = []): array
{
    $envelope = new Envelope(array_merge([
        'id' => POLL_SERVER_ID.':'.Str::ulid(),
        'type' => MessageType::Generic,
        'from_address' => 'sender@'.POLL_SERVER_ID,
        'to_address' => $to,
        'body' => (object) ['subject' => 'Ready'],
        'refs' => [],
        'message_id' => POLL_SERVER_ID.':'.Str::ulid(),
    ], $overrides));
    $envelope->created_at = $overrides['created_at'] ?? now()->utc()->startOfSecond();
    $envelope->signature = app(EnvelopeSigner::class)->sign($envelope);

    return [...$envelope->signablePayload(), 'signature' => $envelope->signature];
}

test('a message crosses spokes exactly once in each poll batch and redelivers until acked', function (): void {
    $sender = User::factory()->create();
    $receiver = User::factory()->create();
    $senderToken = spokeToken($sender);
    $receiverToken = spokeToken($receiver);
    $wireEnvelope = pollWireEnvelope('receiver@'.POLL_SERVER_ID);

    $this->withToken($senderToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender']],
        'outbound' => [$wireEnvelope],
    ])->assertOk()->assertJsonCount(0, 'inbound');

    $first = $this->withToken($receiverToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['receiver']],
    ])->assertOk()->assertJsonCount(1, 'inbound');

    expect($first->json('inbound.0.message_id'))->toBe($wireEnvelope['message_id'])
        ->and($first->json('cursor'))->toBe($wireEnvelope['id']);

    $delivered = Envelope::query()->firstOrFail();
    expect($delivered->status)->toBe(MessageStatus::Delivered)
        ->and($delivered->delivered_at)->not->toBeNull();

    $second = $this->withToken($receiverToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['receiver']],
        'cursor' => $first->json('cursor'),
    ])->assertOk()->assertJsonCount(1, 'inbound');

    expect($second->json('inbound.0.message_id'))->toBe($wireEnvelope['message_id']);

    $this->withToken($receiverToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['receiver']],
        'acks' => [$wireEnvelope['message_id']],
    ])->assertOk()->assertJsonCount(0, 'inbound')->assertJsonPath('cursor', null);

    $this->withToken($receiverToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['receiver']],
    ])->assertOk()->assertJsonCount(0, 'inbound');

    expect(Envelope::query()->firstOrFail()->status)->toBe(MessageStatus::Acked);
});

test('foreign messages park without entering a local inbox', function (): void {
    $user = User::factory()->create();
    $token = spokeToken($user);
    $wireEnvelope = pollWireEnvelope('receiver@'.POLL_FOREIGN_SERVER_ID);

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['receiver']],
        'outbound' => [$wireEnvelope],
    ])->assertOk()->assertJsonCount(0, 'inbound');

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['receiver']],
    ])->assertOk()->assertJsonCount(0, 'inbound');

    expect(Envelope::query()->firstOrFail()->status)->toBe(MessageStatus::PendingRelay);
});

test('presence replaces the routing table and stamps poll state', function (): void {
    Date::setTestNow('2026-08-17 12:00:00');
    $user = User::factory()->create();
    $token = spokeToken($user);

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['a', 'b']],
        'cursor' => 'previous-marker',
    ])->assertOk();

    $spoke = Spoke::query()->firstOrFail();
    expect($spoke->inboxes()->orderBy('local_part')->pluck('local_part')->all())->toBe(['a', 'b'])
        ->and($spoke->last_cursor)->toBe('previous-marker')
        ->and($spoke->last_polled_at?->toDateTimeString())->toBe('2026-08-17 12:00:00');

    Date::setTestNow('2026-08-17 12:01:00');

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['b', 'c', 'c']],
    ])->assertOk();

    $spoke->refresh();
    expect($spoke->inboxes()->orderBy('local_part')->pluck('local_part')->all())->toBe(['b', 'c'])
        ->and($spoke->last_cursor)->toBeNull()
        ->and($spoke->last_polled_at?->toDateTimeString())->toBe('2026-08-17 12:01:00')
        ->and(Spoke::query()->count())->toBe(1);
});

test('sending the same envelope is idempotent', function (): void {
    $user = User::factory()->create();
    $token = spokeToken($user);
    $wireEnvelope = pollWireEnvelope('receiver@'.POLL_SERVER_ID);
    $payload = [
        'presence' => ['ready_inboxes' => ['sender']],
        'outbound' => [$wireEnvelope],
    ];

    $this->withToken($token)->postJson('/api/v1/poll', $payload)->assertOk();
    $this->withToken($token)->postJson('/api/v1/poll', $payload)->assertOk();

    expect(Envelope::query()->count())->toBe(1);
});

test('an invalid signature rejects the whole batch before state changes', function (): void {
    $user = User::factory()->create();
    $wireEnvelope = pollWireEnvelope('receiver@'.POLL_SERVER_ID);
    $wireEnvelope['signature'] = str_repeat('0', 64);

    $this->withToken(spokeToken($user))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender']],
        'outbound' => [$wireEnvelope],
    ])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_signature');

    expect(Envelope::query()->count())->toBe(0)
        ->and(Spoke::query()->count())->toBe(0);
});

test('a malformed envelope names its batch index and prevents partial persistence', function (): void {
    $user = User::factory()->create();
    $valid = pollWireEnvelope('receiver@'.POLL_SERVER_ID);
    $malformed = pollWireEnvelope('receiver@'.POLL_SERVER_ID);
    $malformed['refs'] = ['reserved-reference'];

    $this->withToken(spokeToken($user))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender']],
        'outbound' => [$valid, $malformed],
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors('outbound.1.refs', 'error.errors');

    expect(Envelope::query()->count())->toBe(0)
        ->and(Spoke::query()->count())->toBe(0);
});

test('an unknown version is rejected with the version this server speaks', function (): void {
    $user = User::factory()->create();
    $wireEnvelope = pollWireEnvelope('receiver@'.POLL_SERVER_ID, [
        'version' => Envelope::CURRENT_VERSION + 1,
    ]);

    $this->withToken(spokeToken($user))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender']],
        'outbound' => [$wireEnvelope],
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'unsupported_version')
        ->assertJsonPath('error.known_version', Envelope::CURRENT_VERSION);

    expect(Envelope::query()->count())->toBe(0);
});

test('numeric keyed objects retain their signed shape and cannot be replaced by lists', function (): void {
    $user = User::factory()->create();
    $token = spokeToken($user);
    $body = new stdClass;
    $body->{'0'} = 'x';
    $body->{'1'} = 'y';
    $wireEnvelope = pollWireEnvelope('receiver@'.POLL_SERVER_ID, ['body' => $body]);

    $response = $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['receiver']],
        'outbound' => [$wireEnvelope],
    ])->assertOk()->assertJsonCount(1, 'inbound');
    $decoded = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);

    expect($decoded->inbound[0]->body)->toBeInstanceOf(stdClass::class)
        ->and($decoded->inbound[0]->body->{'0'})->toBe('x');

    $wireEnvelope['body'] = ['x', 'y'];

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['receiver']],
        'outbound' => [$wireEnvelope],
    ])->assertUnprocessable()->assertJsonValidationErrors('outbound.0.body', 'error.errors');
});

test('acks are scoped to the polling spoke inboxes', function (): void {
    $sender = User::factory()->create();
    $receiver = User::factory()->create();
    $senderToken = spokeToken($sender);
    $receiverToken = spokeToken($receiver);
    $wireEnvelope = pollWireEnvelope('receiver@'.POLL_SERVER_ID);

    $this->withToken($senderToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender']],
        'outbound' => [$wireEnvelope],
    ])->assertOk();

    $this->withToken($senderToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender']],
        'acks' => [$wireEnvelope['message_id']],
    ])->assertOk();

    expect(Envelope::query()->firstOrFail()->status)->toBe(MessageStatus::Pending);

    $this->withToken($receiverToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['receiver']],
    ])->assertOk()->assertJsonCount(1, 'inbound');
});

test('repeated and unknown acks are successful no ops', function (): void {
    $user = User::factory()->create();
    $token = spokeToken($user);
    $wireEnvelope = pollWireEnvelope('receiver@'.POLL_SERVER_ID);

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['receiver']],
        'outbound' => [$wireEnvelope],
        'acks' => ['unknown-message-id'],
    ])->assertOk();

    $payload = [
        'presence' => ['ready_inboxes' => ['receiver']],
        'acks' => [$wireEnvelope['message_id'], 'unknown-message-id'],
    ];

    $this->withToken($token)->postJson('/api/v1/poll', $payload)->assertOk()->assertJsonCount(0, 'inbound');
    $ackedAt = Envelope::query()->firstOrFail()->acked_at;
    Date::setTestNow(now()->addMinute());
    $this->withToken($token)->postJson('/api/v1/poll', $payload)->assertOk()->assertJsonCount(0, 'inbound');

    expect(Envelope::query()->firstOrFail()->acked_at?->equalTo($ackedAt))->toBeTrue();
});

test('a disabled feature returns not found without postmaster state changes', function (): void {
    config(['capstan.features.postmaster' => false]);
    Feature::flushCache();
    $user = User::factory()->create();

    $this->withToken(spokeToken($user))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender']],
        'outbound' => [pollWireEnvelope('receiver@'.POLL_SERVER_ID)],
    ])->assertNotFound()->assertJsonPath('error.code', 'not_found');

    expect(Spoke::query()->count())->toBe(0)
        ->and(Envelope::query()->count())->toBe(0)
        ->and(Feature::active(Postmaster::class))->toBeFalse();
});

test('poll requires api authentication', function (): void {
    $this->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
});

test('the inbound limit and stable ordering are honored across cursor redelivery', function (): void {
    config(['capstan.postmaster.poll.max_inbound' => 2]);
    $sender = User::factory()->create();
    $receiver = User::factory()->create();
    $senderToken = spokeToken($sender);
    $receiverToken = spokeToken($receiver);
    $createdAt = now()->utc()->startOfSecond();
    $later = pollWireEnvelope('receiver@'.POLL_SERVER_ID, [
        'id' => POLL_SERVER_ID.':03ARZ3NDEKTSV4RRFFQ69G5FAA',
        'message_id' => 'later',
        'created_at' => $createdAt->addSecond(),
    ]);
    $tieSecond = pollWireEnvelope('receiver@'.POLL_SERVER_ID, [
        'id' => POLL_SERVER_ID.':02ARZ3NDEKTSV4RRFFQ69G5FAA',
        'message_id' => 'tie-second',
        'created_at' => $createdAt,
    ]);
    $tieFirst = pollWireEnvelope('receiver@'.POLL_SERVER_ID, [
        'id' => POLL_SERVER_ID.':01ARZ3NDEKTSV4RRFFQ69G5FAA',
        'message_id' => 'tie-first',
        'created_at' => $createdAt,
    ]);

    $this->withToken($senderToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender']],
        'outbound' => [$later, $tieSecond, $tieFirst],
    ])->assertOk();

    $first = $this->withToken($receiverToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['receiver']],
    ])->assertOk()->assertJsonCount(2, 'inbound');

    expect($first->json('inbound.*.message_id'))->toBe(['tie-first', 'tie-second'])
        ->and($first->json('cursor'))->toBe($tieSecond['id']);

    $second = $this->withToken($receiverToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['receiver']],
        'cursor' => $first->json('cursor'),
    ])->assertOk()->assertJsonCount(2, 'inbound');

    expect($second->json('inbound.*.message_id'))->toBe(['tie-first', 'tie-second']);
});

test('malformed ready inboxes leave the existing routing table unchanged', function (): void {
    $user = User::factory()->create();
    $token = spokeToken($user);

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['valid-inbox']],
    ])->assertOk();

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['valid-inbox', 'Invalid Inbox']],
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors('presence.ready_inboxes.1', 'error.errors');

    expect(Spoke::query()->firstOrFail()->inboxes()->pluck('local_part')->all())->toBe(['valid-inbox']);
});

test('probe responses are ignored and challenges remain reserved', function (): void {
    $user = User::factory()->create();

    $this->withToken(spokeToken($user))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => ['future' => true],
    ])->assertOk()->assertJsonMissingPath('probe_challenge');
});
