<?php

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Features\Postmaster;
use App\Http\Controllers\Api\PollController;
use App\Models\Envelope;
use App\Models\Inbox;
use App\Models\Spoke;
use App\Models\User;
use App\Support\EnvelopeSigner;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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
        'presence' => ['ready_inboxes' => ['sender', 'receiver']],
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
        ->and(Spoke::query()->count())->toBe(1)
        // Ownership outlives presence: "a" is still this user's even though no spoke routes for it.
        ->and(Inbox::query()->orderBy('local_part')->pluck('local_part')->all())->toBe(['a', 'b', 'c'])
        ->and(Inbox::query()->where('local_part', 'a')->value('user_id'))->toBe($user->id);
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
        'presence' => ['ready_inboxes' => ['sender', 'receiver']],
        'outbound' => [$wireEnvelope],
    ])->assertOk()->assertJsonCount(1, 'inbound');
    $decoded = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);

    expect($decoded->inbound[0]->body)->toBeInstanceOf(stdClass::class)
        ->and($decoded->inbound[0]->body->{'0'})->toBe('x');

    $wireEnvelope['body'] = ['x', 'y'];

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender', 'receiver']],
        'outbound' => [$wireEnvelope],
    ])->assertUnprocessable()->assertJsonValidationErrors('outbound.0.body', 'error.errors');
});

test('acks are scoped to inboxes the polling user owns', function (): void {
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
        'presence' => ['ready_inboxes' => ['sender', 'receiver']],
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
        ->and(Inbox::query()->count())->toBe(0)
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
    Date::setTestNow('2026-08-17 12:00:00');
    $sender = User::factory()->create();
    $receiver = User::factory()->create();
    $senderToken = spokeToken($sender);
    $receiverToken = spokeToken($receiver);
    $createdAt = now()->utc()->startOfSecond();
    // Received together: same received_at, so id is the tiebreak; the signed created_at is irrelevant.
    $tieSecond = pollWireEnvelope('receiver@'.POLL_SERVER_ID, [
        'id' => POLL_SERVER_ID.':02ARZ3NDEKTSV4RRFFQ69G5FAA',
        'message_id' => 'tie-second',
        'created_at' => $createdAt->subDay(),
    ]);
    $tieFirst = pollWireEnvelope('receiver@'.POLL_SERVER_ID, [
        'id' => POLL_SERVER_ID.':01ARZ3NDEKTSV4RRFFQ69G5FAA',
        'message_id' => 'tie-first',
        'created_at' => $createdAt,
    ]);
    // Received a second later; oldest possible created_at and lowest id must not promote it.
    $later = pollWireEnvelope('receiver@'.POLL_SERVER_ID, [
        'id' => POLL_SERVER_ID.':00ARZ3NDEKTSV4RRFFQ69G5FAA',
        'message_id' => 'later',
        'created_at' => $createdAt->subYear(),
    ]);

    $this->withToken($senderToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender']],
        'outbound' => [$tieSecond, $tieFirst],
    ])->assertOk();

    Date::setTestNow('2026-08-17 12:00:01');

    $this->withToken($senderToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender']],
        'outbound' => [$later],
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

    $this->withToken($receiverToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['receiver']],
        'acks' => ['tie-first', 'tie-second'],
    ])->assertOk()->assertJsonPath('inbound.*.message_id', ['later']);
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

test('a poll without a probe response remains compatible', function (): void {
    $user = User::factory()->create();

    $this->withToken(spokeToken($user))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()
        ->assertJsonPath('inbound', [])
        ->assertJsonPath('cursor', null);
});

test('another user advertising a claimed inbox is rejected atomically and receives nothing', function (): void {
    $alice = User::factory()->create();
    $mallory = User::factory()->create();
    $aliceToken = spokeToken($alice);
    $malloryToken = spokeToken($mallory);
    $wireEnvelope = pollWireEnvelope('alice@'.POLL_SERVER_ID, ['from_address' => 'alice@'.POLL_SERVER_ID]);

    $this->withToken($aliceToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['alice']],
        'outbound' => [$wireEnvelope],
    ])->assertOk();

    $this->withToken($aliceToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk();

    // Even a bundled first-claim ("mallory") is rolled back with the rejected request.
    $this->withToken($malloryToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['mallory', 'alice']],
    ])->assertConflict()
        ->assertJsonPath('error.code', 'inbox_claimed')
        ->assertJsonPath('error.inboxes', ['alice'])
        ->assertJsonMissingPath('inbound');

    expect(Spoke::query()->where('user_id', $mallory->id)->exists())->toBeFalse()
        ->and(Inbox::query()->where('local_part', 'mallory')->exists())->toBeFalse()
        ->and(Inbox::query()->where('local_part', 'alice')->value('user_id'))->toBe($alice->id)
        ->and(Envelope::query()->firstOrFail()->acked_at)->toBeNull();

    $this->withToken($aliceToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['alice']],
    ])->assertOk()->assertJsonPath('inbound.0.message_id', $wireEnvelope['message_id']);
});

test('another user cannot ack a message addressed to an inbox it does not own', function (): void {
    $alice = User::factory()->create();
    $mallory = User::factory()->create();
    $aliceToken = spokeToken($alice);
    $malloryToken = spokeToken($mallory);
    $wireEnvelope = pollWireEnvelope('alice@'.POLL_SERVER_ID, ['from_address' => 'alice@'.POLL_SERVER_ID]);

    $this->withToken($aliceToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['alice']],
        'outbound' => [$wireEnvelope],
    ])->assertOk();

    $this->withToken($malloryToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['mallory']],
        'acks' => [$wireEnvelope['message_id']],
    ])->assertOk()->assertJsonCount(0, 'inbound');

    $this->withToken($malloryToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['alice']],
        'acks' => [$wireEnvelope['message_id']],
    ])->assertConflict()->assertJsonPath('error.code', 'inbox_claimed');

    $message = Envelope::query()->firstOrFail();
    expect($message->status)->toBe(MessageStatus::Delivered)
        ->and($message->acked_at)->toBeNull();

    $this->withToken($aliceToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['alice']],
    ])->assertOk()->assertJsonCount(1, 'inbound');
});

test('spokes of the same user share an inbox as a pool', function (): void {
    $alice = User::factory()->create();
    $laptop = spokeToken($alice);
    $desktop = spokeToken($alice);
    $wireEnvelope = pollWireEnvelope('alice@'.POLL_SERVER_ID, ['from_address' => 'alice@'.POLL_SERVER_ID]);

    $this->withToken($laptop)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['alice']],
        'outbound' => [$wireEnvelope],
    ])->assertOk()->assertJsonCount(1, 'inbound');

    $this->withToken($desktop)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['alice']],
    ])->assertOk()->assertJsonPath('inbound.0.message_id', $wireEnvelope['message_id']);

    expect(Inbox::query()->where('local_part', 'alice')->count())->toBe(1)
        ->and(Spoke::query()->where('user_id', $alice->id)->count())->toBe(2)
        ->and(DB::table('spoke_inboxes')->count())->toBe(2);

    // Either member of the pool may ack; the other stops receiving it.
    $this->withToken($desktop)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['alice']],
        'acks' => [$wireEnvelope['message_id']],
    ])->assertOk()->assertJsonCount(0, 'inbound');

    $this->withToken($laptop)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['alice']],
    ])->assertOk()->assertJsonCount(0, 'inbound');
});

test('delivery follows persisted routing while acks follow ownership', function (): void {
    $alice = User::factory()->create();
    $token = spokeToken($alice);
    $wireEnvelope = pollWireEnvelope('b@'.POLL_SERVER_ID, ['from_address' => 'a@'.POLL_SERVER_ID]);

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['a', 'b']],
        'outbound' => [$wireEnvelope],
    ])->assertOk()->assertJsonCount(1, 'inbound');

    // Still owned, no longer routed here: not delivered again...
    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['a']],
    ])->assertOk()->assertJsonCount(0, 'inbound');

    expect(Envelope::query()->firstOrFail()->status)->toBe(MessageStatus::Delivered);

    // ...but the batch it already received can still be acknowledged.
    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['a']],
        'acks' => [$wireEnvelope['message_id']],
    ])->assertOk();

    expect(Envelope::query()->firstOrFail()->status)->toBe(MessageStatus::Acked);
});

test('over-cap request arrays are rejected cleanly without touching state', function (): void {
    $user = User::factory()->create();
    $token = spokeToken($user);
    $wireEnvelope = pollWireEnvelope('receiver@'.POLL_SERVER_ID);

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender', 'receiver']],
        'outbound' => [$wireEnvelope],
    ])->assertOk();

    $tooManyInboxes = array_map(fn (int $i): string => "inbox-$i", range(1, PollController::MAX_READY_INBOXES + 1));

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => $tooManyInboxes],
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors('presence.ready_inboxes', 'error.errors');

    $tooManyAcks = array_map(fn (int $i): string => "ack-$i", range(1, PollController::MAX_ACKS));
    $tooManyAcks[] = $wireEnvelope['message_id'];

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender', 'receiver']],
        'acks' => $tooManyAcks,
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors('acks', 'error.errors');

    $tooManyOutbound = array_map(fn (int $i): array => pollWireEnvelope('receiver@'.POLL_SERVER_ID), range(1, PollController::MAX_OUTBOUND + 1));

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender', 'receiver']],
        'outbound' => $tooManyOutbound,
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors('outbound', 'error.errors');

    expect(Spoke::query()->firstOrFail()->inboxes()->orderBy('local_part')->pluck('local_part')->all())->toBe(['receiver', 'sender'])
        ->and(Inbox::query()->count())->toBe(2)
        ->and(Envelope::query()->count())->toBe(1)
        ->and(Envelope::query()->firstOrFail()->status)->toBe(MessageStatus::Delivered);
});

test('request arrays at the cap are accepted', function (): void {
    $user = User::factory()->create();

    $this->withToken(spokeToken($user))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => array_map(fn (int $i): string => "inbox-$i", range(1, PollController::MAX_READY_INBOXES))],
        'acks' => array_map(fn (int $i): string => "ack-$i", range(1, PollController::MAX_ACKS)),
    ])->assertOk();

    expect(Inbox::query()->count())->toBe(PollController::MAX_READY_INBOXES)
        ->and(DB::table('spoke_inboxes')->count())->toBe(PollController::MAX_READY_INBOXES);
});

test('a sender must be a local inbox owned by the sending user', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $aliceToken = spokeToken($alice);

    $this->withToken(spokeToken($bob))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['bob']],
    ])->assertOk();

    $foreignSender = pollWireEnvelope('bob@'.POLL_SERVER_ID, ['from_address' => 'alice@'.POLL_FOREIGN_SERVER_ID]);
    $borrowedSender = pollWireEnvelope('bob@'.POLL_SERVER_ID, ['from_address' => 'bob@'.POLL_SERVER_ID]);
    $unclaimedSender = pollWireEnvelope('bob@'.POLL_SERVER_ID, ['from_address' => 'nobody@'.POLL_SERVER_ID]);
    $ownSender = pollWireEnvelope('bob@'.POLL_SERVER_ID, ['from_address' => 'alice@'.POLL_SERVER_ID]);

    foreach ([$foreignSender, $borrowedSender, $unclaimedSender] as $rejected) {
        $this->withToken($aliceToken)->postJson('/api/v1/poll', [
            'presence' => ['ready_inboxes' => ['alice']],
            'outbound' => [$ownSender, $rejected],
        ])->assertForbidden()
            ->assertJsonPath('error.code', 'sender_not_owned')
            ->assertJsonPath('error.index', 1);
    }

    expect(Envelope::query()->count())->toBe(0)
        ->and(Spoke::query()->where('user_id', $alice->id)->exists())->toBeFalse();

    $this->withToken($aliceToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['alice']],
        'outbound' => [$ownSender],
    ])->assertOk();

    expect(Envelope::query()->count())->toBe(1);
});

test('a spoke may send from any inbox its user owns even without advertising it', function (): void {
    $alice = User::factory()->create();
    $laptop = spokeToken($alice);
    $desktop = spokeToken($alice);

    $this->withToken($laptop)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['alice']],
    ])->assertOk();

    $this->withToken($desktop)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'outbound' => [pollWireEnvelope('alice@'.POLL_SERVER_ID, ['from_address' => 'alice@'.POLL_SERVER_ID])],
    ])->assertOk();

    expect(Envelope::query()->count())->toBe(1);
});

test('a backdated created_at does not jump the delivery queue', function (): void {
    Date::setTestNow('2026-08-17 12:00:00');
    $sender = User::factory()->create();
    $receiver = User::factory()->create();
    $senderToken = spokeToken($sender);
    $receiverToken = spokeToken($receiver);
    $first = pollWireEnvelope('receiver@'.POLL_SERVER_ID, ['message_id' => 'first']);

    $this->withToken($senderToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender']],
        'outbound' => [$first],
    ])->assertOk();

    Date::setTestNow('2026-08-17 12:00:05');
    $backdated = pollWireEnvelope('receiver@'.POLL_SERVER_ID, [
        'message_id' => 'backdated',
        'created_at' => now()->utc()->subYears(5),
    ]);

    $this->withToken($senderToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender']],
        'outbound' => [$backdated],
    ])->assertOk();

    $response = $this->withToken($receiverToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['receiver']],
    ])->assertOk();

    expect($response->json('inbound.*.message_id'))->toBe(['first', 'backdated'])
        ->and($response->json('inbound.1.created_at'))->toBe($backdated['created_at'])
        ->and(Envelope::query()->where('message_id', 'first')->firstOrFail()->received_at?->toDateTimeString())->toBe('2026-08-17 12:00:00')
        ->and(Envelope::query()->where('message_id', 'backdated')->firstOrFail()->received_at?->toDateTimeString())->toBe('2026-08-17 12:00:05');
});

test('the first delivery time survives redelivery', function (): void {
    Date::setTestNow('2026-08-17 12:00:00');
    $user = User::factory()->create();
    $token = spokeToken($user);
    $wireEnvelope = pollWireEnvelope('receiver@'.POLL_SERVER_ID);

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender', 'receiver']],
        'outbound' => [$wireEnvelope],
    ])->assertOk()->assertJsonCount(1, 'inbound');

    Date::setTestNow('2026-08-17 12:05:00');

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender', 'receiver']],
    ])->assertOk()->assertJsonCount(1, 'inbound');

    $message = Envelope::query()->firstOrFail();
    expect($message->delivered_at?->toDateTimeString())->toBe('2026-08-17 12:00:00')
        ->and($message->status)->toBe(MessageStatus::Delivered);
});

test('revoking a token removes its spoke and routing but not inbox ownership', function (): void {
    $user = User::factory()->create();
    $token = spokeToken($user);

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['alice']],
    ])->assertOk();

    expect(Spoke::query()->count())->toBe(1)
        ->and(DB::table('spoke_inboxes')->count())->toBe(1);

    $user->tokens()->delete();

    expect(Spoke::query()->count())->toBe(0)
        ->and(DB::table('spoke_inboxes')->count())->toBe(0)
        ->and(Inbox::query()->where('local_part', 'alice')->value('user_id'))->toBe($user->id);
});

test('malformed envelope addresses are rejected as validation errors', function (string $field, string $address): void {
    $user = User::factory()->create();
    $wireEnvelope = pollWireEnvelope('receiver@'.POLL_SERVER_ID);
    $wireEnvelope[$field] = $address;

    $this->withToken(spokeToken($user))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender']],
        'outbound' => [$wireEnvelope],
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors("outbound.0.$field", 'error.errors');

    expect(Envelope::query()->count())->toBe(0)
        ->and(Spoke::query()->count())->toBe(0);
})->with([
    'from without separator' => ['from', 'sender'],
    'from with malformed server id' => ['from', 'sender@not-a-server-id'],
    'from with malformed local part' => ['from', 'Sender!@'.POLL_SERVER_ID],
    'to without separator' => ['to', 'receiver'],
    'to with malformed server id' => ['to', 'receiver@not-a-server-id'],
    'to with malformed local part' => ['to', '-receiver@'.POLL_SERVER_ID],
]);

test('an outbound entry that is a JSON list is rejected by index', function (): void {
    $user = User::factory()->create();

    $this->withToken(spokeToken($user))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['sender']],
        'outbound' => [pollWireEnvelope('receiver@'.POLL_SERVER_ID), ['not', 'an', 'envelope']],
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors('outbound.1', 'error.errors');

    expect(Envelope::query()->count())->toBe(0);
});

test('a parked message for the same local part on another server is neither delivered nor ackable', function (): void {
    $alice = User::factory()->create();
    $token = spokeToken($alice);
    $foreign = pollWireEnvelope('alice@'.POLL_FOREIGN_SERVER_ID, ['from_address' => 'alice@'.POLL_SERVER_ID]);
    $local = pollWireEnvelope('alice@'.POLL_SERVER_ID, ['from_address' => 'alice@'.POLL_SERVER_ID]);

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['alice']],
        'outbound' => [$foreign, $local],
    ])->assertOk()->assertJsonPath('inbound.*.message_id', [$local['message_id']]);

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['alice']],
        'acks' => [$foreign['message_id'], $local['message_id']],
    ])->assertOk()->assertJsonCount(0, 'inbound');

    $parked = Envelope::query()->where('message_id', $foreign['message_id'])->firstOrFail();
    expect($parked->status)->toBe(MessageStatus::PendingRelay)
        ->and($parked->delivered_at)->toBeNull()
        ->and($parked->acked_at)->toBeNull()
        ->and(Envelope::query()->where('message_id', $local['message_id'])->firstOrFail()->status)->toBe(MessageStatus::Acked);

    // Even a foreign-addressed message that is somehow pending (a state relay may one day
    // produce) must stay out of a local inbox: the server-id guard, not just status, holds.
    $parked->forceFill(['status' => MessageStatus::Pending])->save();

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['alice']],
    ])->assertOk()->assertJsonCount(0, 'inbound');

    expect($parked->fresh()?->delivered_at)->toBeNull();
});

test('over-cap arrays are refused before any per-element validation runs', function (): void {
    $user = User::factory()->create();
    Validator::spy();

    $this->withToken(spokeToken($user))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => array_map(fn (int $i): string => "inbox-$i", range(1, PollController::MAX_READY_INBOXES + 1))],
    ])->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');

    Validator::shouldNotHaveReceived('make');
});
