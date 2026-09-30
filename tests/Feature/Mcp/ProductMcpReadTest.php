<?php

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Mcp\CapstanServer;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\SignedCursor;
use App\Mcp\Tools\PostmasterSpokesTool;
use App\Models\Envelope;
use App\Models\Inbox;
use App\Models\Spoke;
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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\ResponseFactory;
use Laravel\Pennant\Feature;

const MCP_READ_SERVER_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
        'capstan.features.postmaster' => true,
        'capstan.postmaster.server_id' => MCP_READ_SERVER_ID,
        'capstan.postmaster.map.stale_after_seconds' => 300,
    ]);
    Feature::flushCache();
    resolve(SigningRootLifecycle::class)->provision();
});

function capstanMcpReadToken(
    User $user,
    ?string $subjectRef = null,
    SubjectType $subjectType = SubjectType::UserPrincipal,
): string {
    $token = 'capstan-mcp-read-'.Str::random(40);

    Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => $subjectType,
        'subject_ref' => $subjectRef ?? 'capstan-user:'.$user->getKey(),
        'name' => 'Capstan MCP read test',
        'user_id' => (string) $user->getKey(),
        'abilities' => [],
        'secret_hash' => hash('sha256', $token),
        'status' => CredentialStatus::Active,
    ]);

    return $token;
}

/** @param array<string, mixed>|object $arguments */
function capstanMcpReadCall(
    string $tool,
    array|object $arguments,
    string $token,
    string|int $id = 1,
    string $path = '/mcp',
): TestResponse {
    return test()->postJson($path, [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ], ['Authorization' => 'Bearer '.$token]);
}

function capstanMcpReadList(string $token, string $path = '/mcp'): TestResponse
{
    return test()->postJson($path, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => [],
    ], ['Authorization' => 'Bearer '.$token]);
}

function capstanMcpReadCallWithoutArguments(string $tool, string $token, string|int $id = 1): TestResponse
{
    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => 'tools/call',
        'params' => ['name' => $tool],
    ], ['Authorization' => 'Bearer '.$token]);
}

/** @return array<string, mixed> */
function capstanMcpReadStructured(TestResponse $response): array
{
    $response->assertOk();
    $structured = $response->json('result.structuredContent');

    if (! is_array($structured)) {
        throw new RuntimeException($response->getContent());
    }

    return $structured;
}

function capstanMcpReadError(TestResponse $response): string
{
    if ($response->status() === 200) {
        $response->assertJsonPath('result.isError', true);
        $message = $response->json('result.content.0.text');
    } else {
        $response->assertBadRequest();
        $message = $response->json('error.message');
    }

    expect($message)->toBeString();

    return $message;
}

function capstanMcpReadEnvelope(
    string $id,
    string $messageId,
    string $to,
    object $body,
    ?string $receivedAt = null,
): Envelope {
    $envelope = new Envelope([
        'id' => $id,
        'type' => MessageType::Generic,
        'from_address' => 'sender@'.MCP_READ_SERVER_ID,
        'to_address' => $to,
        'body' => $body,
        'refs' => [],
        'message_id' => $messageId,
    ]);
    $envelope->created_at = now()->utc()->startOfSecond();
    $envelope->received_at = $receivedAt ?? now()->format('Y-m-d H:i:s');
    $mac = resolve(SigningRootMac::class)->mac(JsonCanonicalizer::encode($envelope->signablePayload()));
    $envelope->signature = $mac->lowercaseHexMac;
    $envelope->signing_key_id = $mac->keyId;
    $envelope->save();

    return $envelope->refresh();
}

test('mounts one conforming read-only effect-scoped server with exactly two tools', function (): void {
    $token = capstanMcpReadToken(capstanUser());

    McpDelegatedTools::assertConforms(CapstanServer::class);
    McpProductAdmission::assert();

    $meta = $this->getJson('/bfc/meta')->assertOk();
    expect($meta->json('capabilities'))->toContain('mcp-serve', 'mcp-delegated', 'mcp-effect-scoped')
        ->and($meta->json('endpoints'))->toBe(['mcp' => '/mcp']);

    $tools = collect(capstanMcpReadList($token)->assertOk()->json('result.tools'))->keyBy('name');
    expect($tools->keys()->sort()->values()->all())->toBe(['postmaster_messages', 'postmaster_spokes']);

    foreach ([
        'postmaster_spokes' => 'metadata',
        'postmaster_messages' => 'content',
    ] as $name => $classification) {
        $tool = $tools->get($name);
        expect(data_get($tool, '_meta.effect'))->toBe('read')
            ->and(data_get($tool, '_meta.classification'))->toBe($classification)
            ->and(data_get($tool, 'annotations.readOnlyHint'))->toBeTrue()
            ->and(data_get($tool, 'inputSchema.additionalProperties'))->toBeFalse();
    }

    foreach (['send_postmaster_message', 'ack_postmaster_messages', 'create_artifact_share'] as $excluded) {
        $response = capstanMcpReadCall($excluded, (object) [], $token);
        expect($response->status())->toBeIn([200, 400])
            ->and($response->getContent())->not->toContain('storage_key', 'signing_key_id', 'APP_KEY');
        capstanMcpReadError($response);
    }

    capstanMcpReadList($token, '/mcp/write')->assertNotFound();
});

test('lists only actor owned spokes and routed actor owned inboxes without credential data', function (): void {
    $owner = capstanUser();
    $other = capstanUser();
    $token = capstanMcpReadToken($owner);
    $owned = Spoke::query()->create([
        'actor_id' => (string) $owner->id,
        'credential_id' => (string) Str::uuid(),
        'name' => 'Owned',
        'last_polled_at' => now(),
        'probe_status' => 'green',
    ]);
    $foreign = Spoke::query()->create([
        'actor_id' => (string) $other->id,
        'credential_id' => (string) Str::uuid(),
        'name' => 'Foreign',
        'last_polled_at' => now(),
        'probe_status' => 'green',
    ]);
    $ownedInbox = Inbox::query()->create(['actor_id' => (string) $owner->id, 'local_part' => 'owned']);
    $foreignInbox = Inbox::query()->create(['actor_id' => (string) $other->id, 'local_part' => 'foreign']);
    $owned->inboxes()->attach([$ownedInbox->id, $foreignInbox->id]);
    $foreign->inboxes()->attach($foreignInbox);

    $response = capstanMcpReadCall('postmaster_spokes', ['limit' => 25], $token, str_repeat('i', 254))->assertOk();
    expect($response->json('result.structuredContent.items'))->toHaveCount(1)
        ->and($response->json('result.structuredContent.items.0.name'))->toBe('Owned')
        ->and($response->json('result.structuredContent.items.0.inboxes'))->toBe(['owned'])
        ->and($response->getContent())->not->toContain('credential_id', (string) $foreign->credential_id)
        ->and(strlen($response->getContent()))->toBeLessThan(1_048_576);
});

test('spoke cursors bind the actor and exact query', function (): void {
    Date::setTestNow('2026-09-30 12:00:00');
    $owner = capstanUser();
    $other = capstanUser();
    $token = capstanMcpReadToken($owner);
    $otherToken = capstanMcpReadToken($other);

    foreach ([now()->subHour(), now()->subHour(), now()] as $polledAt) {
        Spoke::query()->create([
            'actor_id' => (string) $owner->id,
            'credential_id' => (string) Str::uuid(),
            'name' => Str::random(),
            'last_polled_at' => $polledAt,
            'probe_status' => 'green',
        ]);
    }

    $first = capstanMcpReadStructured(capstanMcpReadCall('postmaster_spokes', ['limit' => 1, 'status' => 'red'], $token));
    expect(data_get($first, 'items.0.status'))->toBe('red')
        ->and($first['has_more'])->toBeTrue()
        ->and($first['next_cursor'])->toBeString();
    $encodedPayload = explode('.', $first['next_cursor'], 2)[0];
    $cursorPayload = json_decode(
        (string) base64_decode(strtr($encodedPayload, '-_', '+/'), true),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    expect(array_keys($cursorPayload))->toBe(['actor', 'query', 'last_id']);

    $second = capstanMcpReadStructured(capstanMcpReadCall('postmaster_spokes', [
        'limit' => 1,
        'status' => 'red',
        'cursor' => $first['next_cursor'],
    ], $token));
    expect(data_get($second, 'items.0.status'))->toBe('red')
        ->and(capstanMcpReadError(capstanMcpReadCall('postmaster_spokes', [
            'limit' => 1,
            'status' => 'red',
            'cursor' => $first['next_cursor'],
        ], $otherToken)))->toBe('The cursor is invalid for this request.')
        ->and(capstanMcpReadError(capstanMcpReadCall('postmaster_spokes', [
            'limit' => 2,
            'status' => 'red',
            'cursor' => $first['next_cursor'],
        ], $token)))->toBe('The cursor is invalid for this request.')
        ->and(capstanMcpReadError(capstanMcpReadCall('postmaster_spokes', [
            'limit' => 1,
            'status' => 'green',
            'cursor' => $first['next_cursor'],
        ], $token)))->toBe('The cursor is invalid for this request.');
});

test('spoke cursors remain stateless when mutable fields change', function (): void {
    Date::setTestNow('2026-09-30 12:00:00');
    $owner = capstanUser();
    $token = capstanMcpReadToken($owner);
    $spokes = collect(['A', 'B', 'C'])->mapWithKeys(function (string $name) use ($owner): array {
        $spoke = Spoke::query()->create([
            'actor_id' => (string) $owner->id,
            'credential_id' => (string) Str::uuid(),
            'name' => $name,
            'last_polled_at' => now()->subHour(),
            'probe_status' => 'green',
        ]);

        return [$name => $spoke];
    });

    $first = capstanMcpReadStructured(capstanMcpReadCall('postmaster_spokes', ['limit' => 1], $token));
    $firstId = data_get($first, 'items.0.id');
    expect($firstId)->toBeInt();
    $returned = $spokes->firstOrFail(fn (Spoke $spoke): bool => $spoke->id === $firstId);
    $unseen = $spokes->firstOrFail(fn (Spoke $spoke): bool => $spoke->id !== $firstId);

    $returned->update(['last_polled_at' => now(), 'probe_status' => 'green']);
    $unseen->update(['name' => 'changed']);

    $seen = [$firstId];
    $cursor = $first['next_cursor'];

    for ($pageNumber = 0; $cursor !== null && $pageNumber < 10; $pageNumber++) {
        $page = capstanMcpReadStructured(capstanMcpReadCall('postmaster_spokes', [
            'limit' => 1,
            'cursor' => $cursor,
        ], $token));
        expect($page['items'])->toHaveCount(1);
        $seen[] = data_get($page, 'items.0.id');
        $cursor = $page['next_cursor'];
    }

    expect($cursor)->toBeNull()
        ->and($seen)->toEqualCanonicalizing($spokes->pluck('id')->values()->all())
        ->and(array_unique($seen))->toHaveCount(3);
});

test('reading spokes performs no writes with the production database cache store', function (): void {
    $owner = capstanUser();
    $token = capstanMcpReadToken($owner);

    foreach (range(1, 2) as $index) {
        Spoke::query()->create([
            'actor_id' => (string) $owner->id,
            'credential_id' => (string) Str::uuid(),
            'name' => 'Spoke '.$index,
            'last_polled_at' => now(),
            'probe_status' => 'green',
        ]);
    }

    config(['cache.default' => 'database']);
    resolve('cache')->setDefaultDriver('database');
    expect(DB::table('cache')->count())->toBe(0);
    $credential = Credential::query()->where('secret_hash', hash('sha256', $token))->firstOrFail();
    $credential->forceFill(['user_id' => (string) $owner->id]);
    $httpRequest = HttpRequest::create(
        '/mcp',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['params' => ['arguments' => ['limit' => 1]]], JSON_THROW_ON_ERROR),
    );
    app()->instance('request', $httpRequest);
    $httpRequest->setUserResolver(static fn (): Credential => $credential);

    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/\A\s*(insert|update|delete|replace|merge|truncate)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $response = resolve(PostmasterSpokesTool::class)->handle(
        new McpRequest(['limit' => 1]),
        new OwnerSubjectResolver($httpRequest),
        resolve(SignedCursor::class),
    );
    expect($response)->toBeInstanceOf(ResponseFactory::class)
        ->and($response->getStructuredContent())->toMatchArray(['has_more' => true])
        ->and(data_get($response->getStructuredContent(), 'next_cursor'))->toBeString()
        ->and($writes)->toBe([])
        ->and(DB::table('cache')->count())->toBe(0);
});

test('byte-paginates maximum-inbox spokes below the relay response ceiling', function (): void {
    $owner = capstanUser();
    $token = capstanMcpReadToken($owner);
    $inboxIds = [];

    for ($index = 0; $index < 256; $index++) {
        $localPart = sprintf('inbox%03d', $index).str_repeat('x', 56);
        $inboxIds[] = Inbox::query()->create([
            'actor_id' => (string) $owner->id,
            'local_part' => $localPart,
        ])->id;
    }

    for ($index = 0; $index < 25; $index++) {
        $spoke = Spoke::query()->create([
            'actor_id' => (string) $owner->id,
            'credential_id' => (string) Str::uuid(),
            'name' => sprintf('Spoke %02d', $index),
            'last_polled_at' => now(),
            'probe_status' => 'green',
        ]);
        $spoke->inboxes()->attach($inboxIds);
    }

    $response = capstanMcpReadCall('postmaster_spokes', ['limit' => 25], $token, str_repeat('i', 254))->assertOk();
    $items = $response->json('result.structuredContent.items');
    expect($items)->toBeArray()
        ->and($items)->not->toBeEmpty()
        ->and(count($items))->toBeLessThan(25)
        ->and($response->json('result.structuredContent.has_more'))->toBeTrue()
        ->and($response->json('result.structuredContent.next_cursor'))->toBeString()
        ->and(strlen($response->getContent()))->toBeLessThan(1_048_576);
});

test('reads signed message content without acknowledging and hides internal routing and key data', function (): void {
    $owner = capstanUser();
    $token = capstanMcpReadToken($owner);
    Inbox::query()->create(['actor_id' => (string) $owner->id, 'local_part' => 'receiver']);
    $body = new stdClass;
    $body->{'0'} = 'numeric object key';
    $envelope = capstanMcpReadEnvelope(
        MCP_READ_SERVER_ID.':01ARZ3NDEKTSV4RRFFQ69G5FAW',
        'message-one',
        'receiver@'.MCP_READ_SERVER_ID,
        $body,
    );

    $response = capstanMcpReadCall('postmaster_messages', ['inbox' => 'receiver'], $token, str_repeat('i', 254))->assertOk();
    $decoded = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);
    expect($decoded->result->structuredContent->items[0]->body)->toBeInstanceOf(stdClass::class)
        ->and($decoded->result->structuredContent->items[0]->body->{'0'})->toBe('numeric object key')
        ->and($decoded->result->structuredContent->items[0]->signature)->toBe($envelope->signature)
        ->and($response->getContent())->not->toContain('signing_key_id', 'to_local_part', 'to_server_id')
        ->and(strlen($response->getContent()))->toBeLessThan(1_048_576);

    $envelope->refresh();
    expect($envelope->status)->toBe(MessageStatus::Pending)
        ->and($envelope->delivered_at)->toBeNull()
        ->and($envelope->acked_at)->toBeNull();
});

test('message cursors traverse equal delivery timestamps and bind inbox and actor', function (): void {
    Date::setTestNow('2026-09-30 13:00:00');
    $owner = capstanUser();
    $other = capstanUser();
    $token = capstanMcpReadToken($owner);
    $otherToken = capstanMcpReadToken($other);
    Inbox::query()->create(['actor_id' => (string) $owner->id, 'local_part' => 'receiver']);
    Inbox::query()->create(['actor_id' => (string) $owner->id, 'local_part' => 'alternate']);
    Inbox::query()->create(['actor_id' => (string) $other->id, 'local_part' => 'foreign']);

    foreach (['A', 'B', 'C'] as $suffix) {
        capstanMcpReadEnvelope(
            MCP_READ_SERVER_ID.':01ARZ3NDEKTSV4RRFFQ69G5F'.$suffix,
            'message-'.$suffix,
            'receiver@'.MCP_READ_SERVER_ID,
            (object) ['sequence' => $suffix],
            '2026-09-30 13:00:00',
        );
    }

    $seen = [];
    $cursor = null;

    do {
        $arguments = ['inbox' => 'receiver', 'limit' => 1];

        if ($cursor !== null) {
            $arguments['cursor'] = $cursor;
        }

        $page = capstanMcpReadStructured(capstanMcpReadCall('postmaster_messages', $arguments, $token));
        $seen[] = data_get($page, 'items.0.message_id');
        $cursor = $page['next_cursor'];
    } while ($cursor !== null);

    expect($seen)->toBe(['message-A', 'message-B', 'message-C']);

    $first = capstanMcpReadStructured(capstanMcpReadCall('postmaster_messages', [
        'inbox' => 'receiver',
        'limit' => 1,
    ], $token));
    expect(capstanMcpReadError(capstanMcpReadCall('postmaster_messages', [
        'inbox' => 'alternate',
        'limit' => 1,
        'cursor' => $first['next_cursor'],
    ], $token)))->toBe('The cursor is invalid for this request.')
        ->and(capstanMcpReadError(capstanMcpReadCall('postmaster_messages', [
            'inbox' => 'foreign',
            'limit' => 1,
            'cursor' => $first['next_cursor'],
        ], $otherToken)))->toBe('The cursor is invalid for this request.');
});

test('message cursors exhaust true legacy null and timestamped delivery rows', function (): void {
    $owner = capstanUser();
    $token = capstanMcpReadToken($owner);
    Inbox::query()->create(['actor_id' => (string) $owner->id, 'local_part' => 'receiver']);

    foreach ([
        ['W', 'legacy-null-a', null],
        ['X', 'timestamped-a', '2026-09-30 13:00:00'],
        ['Y', 'legacy-null-b', null],
        ['Z', 'timestamped-b', '2026-09-30 13:00:01'],
    ] as [$suffix, $messageId, $receivedAt]) {
        $envelope = capstanMcpReadEnvelope(
            MCP_READ_SERVER_ID.':01ARZ3NDEKTSV4RRFFQ69G5FA'.$suffix,
            $messageId,
            'receiver@'.MCP_READ_SERVER_ID,
            (object) ['sequence' => $messageId],
            $receivedAt,
        );

        if ($receivedAt === null) {
            DB::table('messages')->where('id', $envelope->id)->update(['received_at' => null]);
        }
    }

    $seen = [];
    $cursor = null;

    for ($pageNumber = 0; $pageNumber < 10; $pageNumber++) {
        $arguments = ['inbox' => 'receiver', 'limit' => 1];

        if ($cursor !== null) {
            $arguments['cursor'] = $cursor;
        }

        $page = capstanMcpReadStructured(capstanMcpReadCall('postmaster_messages', $arguments, $token));
        expect($page['items'])->toHaveCount(1);
        $seen[] = data_get($page, 'items.0.message_id');
        $cursor = $page['next_cursor'];

        if ($cursor === null) {
            break;
        }
    }

    expect($cursor)->toBeNull()
        ->and($seen)->toBe(['legacy-null-a', 'legacy-null-b', 'timestamped-a', 'timestamped-b'])
        ->and(array_unique($seen))->toHaveCount(4);
});

test('foreign and missing inboxes disclose the same bounded refusal', function (): void {
    $owner = capstanUser();
    $other = capstanUser();
    $token = capstanMcpReadToken($owner);
    Inbox::query()->create(['actor_id' => (string) $other->id, 'local_part' => 'foreign']);

    $foreign = capstanMcpReadCall('postmaster_messages', ['inbox' => 'foreign'], $token);
    $missing = capstanMcpReadCall('postmaster_messages', ['inbox' => 'missing'], $token);
    expect(capstanMcpReadError($foreign))->toBe('The inbox is unavailable.')
        ->and(capstanMcpReadError($missing))->toBe('The inbox is unavailable.')
        ->and($foreign->getContent())->toBe($missing->getContent());
});

test('an oversized legacy message is explicitly omitted and pagination advances', function (): void {
    $owner = capstanUser();
    $token = capstanMcpReadToken($owner);
    Inbox::query()->create(['actor_id' => (string) $owner->id, 'local_part' => 'receiver']);
    capstanMcpReadEnvelope(
        MCP_READ_SERVER_ID.':01ARZ3NDEKTSV4RRFFQ69G5FAW',
        'legacy-large',
        'receiver@'.MCP_READ_SERVER_ID,
        (object) ['content' => str_repeat('\\', 190_000)],
        '2026-09-30 13:00:00',
    );
    capstanMcpReadEnvelope(
        MCP_READ_SERVER_ID.':01ARZ3NDEKTSV4RRFFQ69G5FAX',
        'legacy-small',
        'receiver@'.MCP_READ_SERVER_ID,
        (object) ['content' => 'small'],
        '2026-09-30 13:00:01',
    );

    $firstResponse = capstanMcpReadCall(
        'postmaster_messages',
        ['inbox' => 'receiver', 'limit' => 1],
        $token,
        str_repeat('i', 254),
    );
    $first = capstanMcpReadStructured($firstResponse);
    expect(data_get($first, 'items.0.message_id'))->toBe('legacy-large')
        ->and(data_get($first, 'items.0.content_omitted'))->toBeTrue()
        ->and(data_get($first, 'items.0.omission_reason'))->toBe('message_exceeds_response_budget')
        ->and($first['next_cursor'])->toBeString()
        ->and(strlen($firstResponse->getContent()))->toBeLessThan(1_048_576);

    $second = capstanMcpReadStructured(capstanMcpReadCall('postmaster_messages', [
        'inbox' => 'receiver',
        'limit' => 1,
        'cursor' => $first['next_cursor'],
    ], $token));
    expect(data_get($second, 'items.0.message_id'))->toBe('legacy-small')
        ->and(data_get($second, 'items.0.content_omitted'))->toBeFalse();
});

test('feature gating and unsupported subject types fail closed without changing the tool surface', function (): void {
    $user = capstanUser();
    $token = capstanMcpReadToken($user);
    $unsupported = capstanMcpReadToken(
        $user,
        'capstan-user:'.$user->id,
        SubjectType::ExternalConsumer,
    );

    capstanMcpReadCall('postmaster_spokes', (object) [], $unsupported)
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'This MCP principal is not supported.');

    config(['capstan.features.postmaster' => false]);
    Feature::flushCache();
    expect(capstanMcpReadList($token)->json('result.tools.*.name'))->toHaveCount(2);
    capstanMcpReadCall('postmaster_spokes', (object) [], $token)
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'Postmaster is unavailable.');
    capstanMcpReadCall('postmaster_messages', ['inbox' => 'missing'], $token)
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'Postmaster is unavailable.');
});

test('runtime validation rejects list numeric null unknown malformed and oversized arguments', function (): void {
    $token = capstanMcpReadToken(capstanUser());
    $invalidCalls = [
        ['postmaster_spokes', []],
        ['postmaster_spokes', (object) ['0' => 'numeric-key']],
        ['postmaster_spokes', ['limit' => null]],
        ['postmaster_spokes', ['limit' => 26]],
        ['postmaster_spokes', ['cursor' => 'malformed']],
        ['postmaster_spokes', ['status' => 'unhealthy']],
        ['postmaster_spokes', ['unexpected' => true]],
        ['postmaster_messages', (object) []],
        ['postmaster_messages', ['inbox' => null]],
        ['postmaster_messages', ['inbox' => 'invalid local part']],
        ['postmaster_messages', ['inbox' => 'missing', 'limit' => 51]],
        ['postmaster_messages', ['inbox' => 'missing', 'cursor' => null]],
        ['postmaster_messages', ['inbox' => 'missing', 'unexpected' => true]],
    ];

    foreach ($invalidCalls as [$tool, $arguments]) {
        $response = capstanMcpReadCall($tool, $arguments, $token);
        expect($response->status())->toBeIn([200, 400])
            ->and(strlen($response->getContent()))->toBeLessThan(1_048_576);
        capstanMcpReadError($response);
    }
});

test('a zero argument tool accepts an omitted arguments member', function (): void {
    $token = capstanMcpReadToken(capstanUser());

    capstanMcpReadCallWithoutArguments('postmaster_spokes', $token)
        ->assertOk()
        ->assertJsonPath('result.structuredContent.items', [])
        ->assertJsonPath('result.structuredContent.has_more', false)
        ->assertJsonPath('result.structuredContent.limit', 10);
});

test('unknown argument diagnostics remain bounded for a maximum request id', function (): void {
    $token = capstanMcpReadToken(capstanUser());
    $property = str_repeat('x', 1_048_576);
    $response = capstanMcpReadCall(
        'postmaster_spokes',
        (object) [$property => true],
        $token,
        str_repeat('i', 254),
    );

    expect(capstanMcpReadError($response))->toBe('Unknown tool argument.')
        ->and(strlen($response->getContent()))->toBeLessThan(1_048_576)
        ->and($response->getContent())->not->toContain($property);
});
