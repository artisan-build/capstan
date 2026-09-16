<?php

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Envelope;
use App\Support\JsonCanonicalizer;
use ArtisanBuild\BuiltForCloud\Hmac\SigningRootLifecycle;
use ArtisanBuild\BuiltForCloud\Hmac\SigningRootMac;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('e', 32))]);
    app(SigningRootLifecycle::class)->provision();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function postmasterTestEnvelope(array $overrides = []): Envelope
{
    $serverId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $envelope = new Envelope(array_merge([
        'id' => $serverId.':01ARZ3NDEKTSV4RRFFQ69G5FAW',
        'type' => MessageType::Handoff,
        'from_address' => 'sender@'.$serverId,
        'to_address' => 'inbox.primary@'.$serverId,
        'body' => ['nested' => ['count' => 1, 'ready' => true], 'title' => 'Handoff'],
        'message_id' => $serverId.':01ARZ3NDEKTSV4RRFFQ69G5FAX',
    ], $overrides));
    $envelope->created_at = now()->utc()->startOfSecond();
    $mac = app(SigningRootMac::class)->mac(JsonCanonicalizer::encode($envelope->signablePayload()));
    $envelope->signature = $mac->lowercaseHexMac;
    $envelope->signing_key_id = $mac->keyId;

    return $envelope;
}

function verifyPostmasterEnvelope(Envelope $envelope): bool
{
    if (! is_string($envelope->signature) || ! is_string($envelope->signing_key_id)) {
        return false;
    }

    return app(SigningRootMac::class)->verify(
        $envelope->signing_key_id,
        JsonCanonicalizer::encode($envelope->signablePayload()),
        $envelope->signature,
    );
}

test('an envelope round trips with enum and JSON casts and synchronized routing columns', function (): void {
    $envelope = postmasterTestEnvelope();
    $envelope->save();
    $stored = Envelope::query()->findOrFail($envelope->id);

    expect($stored->id)->toBe($envelope->id)
        ->and($stored->type)->toBe(MessageType::Handoff)
        ->and($stored->version)->toBe(Envelope::CURRENT_VERSION)
        ->and($stored->from_address)->toBe($envelope->from_address)
        ->and($stored->to_address)->toBe($envelope->to_address)
        ->and($stored->to_local_part)->toBe('inbox.primary')
        ->and($stored->to_server_id)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->and($stored->body)->toEqual($envelope->body)
        ->and($stored->body)->toBeInstanceOf(stdClass::class)
        ->and($stored->refs)->toBe([])
        ->and($stored->message_id)->toBe($envelope->message_id)
        ->and($stored->signature)->toBe($envelope->signature)
        ->and($stored->status)->toBe(MessageStatus::Pending)
        ->and($stored->delivered_at)->toBeNull()
        ->and($stored->acked_at)->toBeNull()
        ->and($stored->getIncrementing())->toBeFalse()
        ->and($stored->getKeyType())->toBe('string');
});

test('message enums are closed sets backed by varchar columns', function (): void {
    expect(MessageType::values())->toBe([
        'handoff',
        'kb_nomination',
        'solicitation',
        'generic',
    ])->and(MessageStatus::values())->toBe([
        'pending',
        'delivered',
        'acked',
        'pending_relay',
    ]);

    $typeColumn = collect(Schema::getColumns('messages'))->firstWhere('name', 'type');

    expect($typeColumn)->not->toBeNull()
        ->and($typeColumn['type_name'])->toBe('varchar');
});

test('message id uniqueness is enforced by the database', function (): void {
    $first = postmasterTestEnvelope();
    $first->save();
    $second = postmasterTestEnvelope([
        'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV:01ARZ3NDEKTSV4RRFFQ69G5FAY',
    ]);

    expect(fn (): bool => $second->save())->toThrow(UniqueConstraintViolationException::class);
});

test('version helpers flag unknown versions with the version this server speaks', function (): void {
    $known = postmasterTestEnvelope();
    $unknown = postmasterTestEnvelope(['version' => Envelope::CURRENT_VERSION + 1]);

    expect($known->isVersionSupported())->toBeTrue()
        ->and($known->knownVersionForRejection())->toBeNull()
        ->and($unknown->isVersionSupported())->toBeFalse()
        ->and($unknown->knownVersionForRejection())->toBe(Envelope::CURRENT_VERSION);
});

test('version one refuses nonempty reserved refs', function (): void {
    $envelope = postmasterTestEnvelope(['refs' => ['artifact:123']]);

    expect(fn (): bool => $envelope->save())->toThrow(InvalidArgumentException::class);
});

test('saving rejects malformed envelope addresses', function (array $overrides): void {
    $envelope = postmasterTestEnvelope($overrides);

    expect(fn (): bool => $envelope->save())->toThrow(InvalidArgumentException::class);
})->with([
    'malformed from address' => [['from_address' => 'sender-without-server']],
    'malformed to address' => [['to_address' => 'inbox-without-server']],
    'newline-tainted address' => [['to_address' => "inbox@01ARZ3NDEKTSV4RRFFQ69G5FAV\n"]],
]);

test('a correct lowercase hexadecimal HMAC verifies', function (): void {
    $envelope = postmasterTestEnvelope();

    expect($envelope->signature)->toMatch('/^[0-9a-f]{64}$/')
        ->and(verifyPostmasterEnvelope($envelope))->toBeTrue();
});

test('the signing root covers the exact canonical nine-field wire', function (): void {
    Date::setTestNow('2026-08-17 11:44:44');
    $envelope = postmasterTestEnvelope();
    $wire = JsonCanonicalizer::encode($envelope->signablePayload());

    expect($wire)->toBe('{"body":{"nested":{"count":1,"ready":true},"title":"Handoff"},"created_at":"2026-08-17T11:44:44Z","from":"sender@01ARZ3NDEKTSV4RRFFQ69G5FAV","id":"01ARZ3NDEKTSV4RRFFQ69G5FAV:01ARZ3NDEKTSV4RRFFQ69G5FAW","message_id":"01ARZ3NDEKTSV4RRFFQ69G5FAV:01ARZ3NDEKTSV4RRFFQ69G5FAX","refs":[],"to":"inbox.primary@01ARZ3NDEKTSV4RRFFQ69G5FAV","type":"handoff","version":1}')
        ->and(verifyPostmasterEnvelope($envelope))->toBeTrue();
});

test('mutating any signable envelope field invalidates its HMAC', function (): void {
    $mutations = [
        'id' => function (Envelope $envelope): void {
            $envelope->id .= '-tampered';
        },
        'type' => function (Envelope $envelope): void {
            $envelope->type = MessageType::Generic;
        },
        'version' => function (Envelope $envelope): void {
            $envelope->version++;
        },
        'from' => function (Envelope $envelope): void {
            $envelope->from_address = 'other@01ARZ3NDEKTSV4RRFFQ69G5FAV';
        },
        'to' => function (Envelope $envelope): void {
            $envelope->to_address = 'other@01ARZ3NDEKTSV4RRFFQ69G5FAV';
        },
        'created_at' => function (Envelope $envelope): void {
            $envelope->created_at = $envelope->created_at->addSecond();
        },
        'message_id' => function (Envelope $envelope): void {
            $envelope->message_id .= '-tampered';
        },
        'nested body' => function (Envelope $envelope): void {
            $body = $envelope->body;

            if (! $body instanceof stdClass || ! $body->nested instanceof stdClass) {
                throw new LogicException('The test envelope body did not retain its object shape.');
            }

            $body->nested->count = 2;
            $envelope->body = $body;
        },
        'refs' => function (Envelope $envelope): void {
            $envelope->refs = ['tampered'];
        },
    ];

    foreach ($mutations as $mutate) {
        $envelope = postmasterTestEnvelope();
        $mutate($envelope);

        expect(verifyPostmasterEnvelope($envelope))->toBeFalse();
    }
});

test('reordering equivalent nested body keys does not invalidate its HMAC', function (): void {
    $envelope = postmasterTestEnvelope([
        'body' => ['z' => ['b' => 2, 'a' => 1], 'a' => true],
    ]);
    $envelope->body = ['a' => true, 'z' => ['a' => 1, 'b' => 2]];

    expect(verifyPostmasterEnvelope($envelope))->toBeTrue();
});

test('list and numeric-keyed object bodies cannot collide', function (): void {
    $list = postmasterTestEnvelope(['body' => ['x', 'y']]);
    $object = postmasterTestEnvelope(['body' => ['1' => 'y', '0' => 'x']]);

    expect($list->signature)->not->toBe($object->signature);

    $object->signature = $list->signature;

    expect(verifyPostmasterEnvelope($object))->toBeFalse();
});

test('wrong or missing signatures fail without error', function (mixed $signature): void {
    $envelope = postmasterTestEnvelope();
    $envelope->signature = $signature;

    expect(verifyPostmasterEnvelope($envelope))->toBeFalse();
})->with([
    'wrong signature of equal length' => str_repeat('0', 64),
    'wrong signature of different length' => 'short',
    'empty signature' => '',
    'null signature' => null,
]);

test('created at normalization survives database precision loss', function (): void {
    $envelope = postmasterTestEnvelope();
    $envelope->created_at = '2026-08-17 11:44:44';
    $mac = app(SigningRootMac::class)->mac(JsonCanonicalizer::encode($envelope->signablePayload()));
    $envelope->signature = $mac->lowercaseHexMac;
    $envelope->signing_key_id = $mac->keyId;
    $envelope->save();

    if (DB::getDriverName() === 'pgsql') {
        // Laravel's current timestamps are precision-zero on Postgres. Widen
        // this transactional test column to exercise the driver's real precision.
        DB::statement('alter table messages alter column created_at type timestamp(6) without time zone');
    }

    DB::table('messages')->where('id', $envelope->id)
        ->update(['created_at' => '2026-08-17 11:44:44.654321']);

    $stored = Envelope::query()->findOrFail($envelope->id);

    expect($stored->created_at->micro)->toBe(654321)
        ->and(verifyPostmasterEnvelope($stored))->toBeTrue()
        ->and($stored->signablePayload()['created_at'])->toMatch('/Z$/');
});

test('stored envelopes remain verifiable through signing-root rotation', function (): void {
    $stored = postmasterTestEnvelope();
    $stored->save();
    $oldKeyId = $stored->signing_key_id;

    app(SigningRootLifecycle::class)->rotate($oldKeyId, false);
    $next = postmasterTestEnvelope([
        'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV:01ARZ3NDEKTSV4RRFFQ69G5FAY',
        'message_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV:01ARZ3NDEKTSV4RRFFQ69G5FAZ',
    ]);

    expect($next->signing_key_id)->not->toBe($oldKeyId)
        ->and(verifyPostmasterEnvelope($stored->fresh()))->toBeTrue()
        ->and(verifyPostmasterEnvelope($next))->toBeTrue();
});

test('stored envelopes survive a staged APP_KEY rotation and fail without the previous key', function (): void {
    $envelope = postmasterTestEnvelope();
    $oldKey = (string) config('app.key');

    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('n', 32)),
        'app.previous_keys' => [$oldKey],
    ]);

    expect(verifyPostmasterEnvelope($envelope))->toBeTrue();

    config(['app.previous_keys' => []]);

    expect(verifyPostmasterEnvelope($envelope))->toBeFalse();
});
