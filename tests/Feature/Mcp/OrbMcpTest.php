<?php

use App\Enums\WorkMilestoneKind;
use App\Models\AmpIdentity;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkDirective;
use App\Models\WorkItem;
use App\Models\WorkTask;
use App\Work\AmpSessionReference;
use App\Work\WorkRepresentation;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

const ORB_ISSUER = 'https://ampcode.com/api/workload-identity';
const ORB_DISCOVERY = ORB_ISSUER.'/.well-known/openid-configuration';
const ORB_JWKS = ORB_ISSUER.'/jwks.json';
const ORB_AUDIENCE = 'https://capstan.example.test/mcp/orb';

beforeEach(function (): void {
    Date::setTestNow('2026-10-25 12:00:00');
    config(['app.url' => 'https://capstan.example.test', 'capstan.orb.oidc_cache_seconds' => 300]);
    Cache::flush();
    orbFakeIdentityProvider();
});

/** @return array{private: OpenSSLAsymmetricKey, jwk: array<string, string>} */
function orbKeyPair(string $name = 'primary'): array
{
    static $pairs = [];
    if (isset($pairs[$name])) {
        return $pairs[$name];
    }

    $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if (! $private instanceof OpenSSLAsymmetricKey) {
        throw new RuntimeException('Could not create an RSA test key.');
    }
    $details = openssl_pkey_get_details($private);
    if (! is_array($details) || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
        throw new RuntimeException('Could not read the RSA test key.');
    }
    $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

    return $pairs[$name] = [
        'private' => $private,
        'jwk' => [
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'orb-'.$name,
            'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e']),
        ],
    ];
}

/** @param list<array<string, string>>|null $keys */
function orbFakeIdentityProvider(?array $keys = null): void
{
    $keys ??= [orbKeyPair()['jwk']];
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake([
        ORB_DISCOVERY => Http::response([
            'issuer' => ORB_ISSUER,
            'jwks_uri' => ORB_JWKS,
            'id_token_signing_alg_values_supported' => ['RS256'],
        ]),
        ORB_JWKS => Http::response(['keys' => $keys]),
    ]);
}

/** @param array<string, mixed> $overrides */
function orbToken(array $overrides = [], string $key = 'primary', string $algorithm = 'RS256'): string
{
    $claims = array_replace([
        'iss' => ORB_ISSUER,
        'aud' => ORB_AUDIENCE,
        'exp' => now()->addMinutes(10)->timestamp,
        'iat' => now()->timestamp,
        'token_use' => 'exchanged',
        'user_id' => 'amp-user-1',
        'project_id' => 'amp-project-1',
        'thread_id' => 'T-orb-thread-1',
        'email' => 'orb-worker@example.test',
        'email_verified' => true,
    ], $overrides);
    if (! array_key_exists('sub', $overrides)) {
        $claims['sub'] = "project:{$claims['project_id']}:user:{$claims['user_id']}:thread:{$claims['thread_id']}";
    }

    $pair = orbKeyPair($key);
    $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $header = $encode(json_encode(['alg' => $algorithm, 'typ' => 'JWT', 'kid' => $pair['jwk']['kid']], JSON_THROW_ON_ERROR));
    $payload = $encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $signingInput = $header.'.'.$payload;
    if ($algorithm === 'RS256') {
        openssl_sign($signingInput, $signature, $pair['private'], OPENSSL_ALGO_SHA256);
    } else {
        $signature = hash_hmac('sha256', $signingInput, 'not-an-asymmetric-key', true);
    }

    return $signingInput.'.'.$encode($signature);
}

function orbTokenWithInvalidSignature(): string
{
    $token = orbToken();
    $last = substr($token, -1);

    return substr($token, 0, -1).($last === 'a' ? 'b' : 'a');
}

/** @param array<string, mixed> $itemAttributes
 * @return array{user: User, team: Team, spoke: Spoke, task: WorkTask, item: WorkItem, reference: AmpSessionReference}
 */
function orbClaim(array $itemAttributes = []): array
{
    $user = capstanUser([
        'email' => 'orb-worker@example.test',
        'email_verified_at' => now(),
    ]);
    $team = Team::query()->create(['name' => 'Orb team', 'slug' => 'orb-'.Str::lower(Str::random(8))]);
    DB::table('team_user')->insert([
        'team_id' => $team->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $user->id,
        'credential_id' => (string) Str::uuid7(),
        'credential_backed' => true,
    ]);
    $task = WorkTask::query()->create([
        'team_id' => $team->id,
        'title' => 'Test-created orb task '.Str::random(8),
        'required_capabilities' => [],
        'timebox_minutes' => 12,
        'creator_actor' => (string) $user->id,
    ]);
    $task->briefRevisions()->create([
        'sequence' => 1,
        'content' => 'Test-created brief '.Str::random(8),
        'author_actor' => (string) $user->id,
    ]);
    $reference = AmpSessionReference::make(
        'amp-project-1',
        'T-orb-thread-1',
        'https://ampcode.com/threads/T-orb-thread-1',
    );
    $item = $task->items()->create(array_replace([
        'role' => 'builder',
        'state' => 'delivered',
        'fence' => 4,
        'claimant_actor' => (string) $user->id,
        'claimant_spoke_id' => $spoke->id,
        'claimed_at' => now()->subMinute(),
        'lease_until' => now()->addMinutes(5),
        'handed_off_at' => now()->subSeconds(30),
        'handed_off_session_ref' => $reference->encode(),
        'amp_project_id' => $reference->projectId,
        'amp_thread_id' => $reference->threadId,
        'launch_nonce' => (string) Str::uuid7(),
    ], $itemAttributes));

    return compact('user', 'team', 'spoke', 'task', 'item', 'reference');
}

/** @param array<string, mixed>|object $arguments */
function orbCall(string $tool, array|object $arguments, string $token): TestResponse
{
    return test()->postJson('/mcp/orb', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ], ['Authorization' => 'Bearer '.$token]);
}

function orbList(string $token, string $path = '/mcp/orb'): TestResponse
{
    return test()->postJson($path, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
    ], ['Authorization' => 'Bearer '.$token]);
}

test('canonical Amp session references round trip and representations publish only the thread URL', function (): void {
    $claim = orbClaim();
    $encoded = $claim['reference']->encode();
    $parsed = AmpSessionReference::parse($encoded);

    expect($parsed->projectId)->toBe('amp-project-1')
        ->and($parsed->threadId)->toBe('T-orb-thread-1')
        ->and($parsed->threadUrl)->toBe('https://ampcode.com/threads/T-orb-thread-1')
        ->and($parsed->encode())->toBe($encoded);

    $wire = resolve(WorkRepresentation::class)->item($claim['item']->fresh());
    expect($wire['thread_url'])->toBe($parsed->threadUrl)
        ->and($wire)->not->toHaveKey('handed_off_session_ref');
});

test('session references reject malformed noncanonical duplicate and mismatched values', function (string $reference): void {
    expect(fn () => AmpSessionReference::parse($reference))->toThrow(InvalidArgumentException::class);
})->with(function (): array {
    $encode = fn (string $json): string => AmpSessionReference::PREFIX.rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

    return [
        ['amp:v1:not+base64'],
        [$encode('{"project_id":"p","thread_id":"T-1","thread_url":"https://ampcode.com/threads/T-1","thread_id":"T-1"}')],
        [$encode('{ "project_id":"p","thread_id":"T-1","thread_url":"https://ampcode.com/threads/T-1"}')],
        [$encode('{"project_id":"p","thread_id":"T-1","thread_url":"https://ampcode.com/threads/T-2"}')],
        [$encode('{"project_id":"p","thread_id":"T-1","thread_url":"https://user@ampcode.com/threads/T-1"}')],
        [AmpSessionReference::PREFIX.str_repeat('a', AmpSessionReference::MAX_LENGTH)],
    ];
});

test('a valid exact claim can list and call only the three orb tools', function (): void {
    $claim = orbClaim();
    $token = orbToken();

    $tools = collect(orbList($token)->assertOk()->json('result.tools'))->pluck('name')->sort()->values()->all();
    expect($tools)->toBe(['work_brief', 'work_progress', 'work_started']);

    $brief = orbCall('work_brief', (object) [], $token)->assertOk();
    expect($brief->json('result.structuredContent.revision.content'))->toBe($claim['task']->briefRevisions()->sole()->content)
        ->and($claim['item']->fresh()->first_fetched_at)->not->toBeNull();

    foreach (['get_task', 'orchestrate', 'upload_work_evidence', 'steer', 'work_finished'] as $excluded) {
        $response = orbCall($excluded, (object) [], $token);
        expect($response->status())->toBeIn([200, 400]);
        expect($response->getContent())->not->toContain($claim['reference']->encode());
    }
});

test('started is idempotent and progress extends the deadline with frozen stall semantics', function (): void {
    $claim = orbClaim();
    $token = orbToken();

    $started = orbCall('work_started', (object) [], $token)->assertOk();
    expect($claim['item']->milestones()->where('kind', WorkMilestoneKind::Started)->count())->toBe(1)
        ->and($claim['item']->fresh()->lease_until?->equalTo(now()->addMinutes(12)))->toBeTrue();
    Date::setTestNow(now()->addMinute());
    orbCall('work_started', (object) [], $token)->assertOk()
        ->assertJsonPath('result.structuredContent.milestone_id', $started->json('result.structuredContent.milestone_id'));
    expect($claim['item']->milestones()->where('kind', WorkMilestoneKind::Started)->count())->toBe(1);

    $claim['item']->forceFill(['stalled_at' => now()->subSecond()])->save();
    $directive = WorkDirective::query()->create([
        'work_item_id' => $claim['item']->id,
        'claimant_spoke_id' => $claim['spoke']->id,
        'fence' => $claim['item']->fence,
        'sequence' => 1,
        'kind' => 'nudge',
        'reason' => 'stall',
        'payload' => [],
        'status' => 'pending',
        'issued_at' => now()->subSecond(),
    ]);
    $summary = 'Test-created progress '.Str::random(12);
    orbCall('work_progress', ['summary' => $summary], $token)->assertOk();
    $item = $claim['item']->fresh();
    expect($item->last_progress)->toBe($summary)
        ->and($item->lease_until?->equalTo(now()->addMinutes(12)))->toBeTrue()
        ->and($item->stalled_at)->toBeNull()
        ->and($item->milestones()->where('kind', WorkMilestoneKind::Note)->sole()->payload['summary'])->toBe($summary)
        ->and($directive->fresh()->outcome)->toBe(['result' => 'resolved_by_progress']);
});

test('brief fetch resolves a stall directive by fetch without becoming a stall-clearing receipt', function (): void {
    $claim = orbClaim(['stalled_at' => now()->subMinute()]);
    $directive = WorkDirective::query()->create([
        'work_item_id' => $claim['item']->id, 'claimant_spoke_id' => $claim['spoke']->id,
        'fence' => $claim['item']->fence, 'sequence' => 1, 'kind' => 'nudge', 'reason' => 'stall',
        'payload' => [], 'status' => 'pending', 'issued_at' => now()->subMinute(),
    ]);

    orbCall('work_brief', (object) [], orbToken())->assertOk();

    expect($directive->fresh()->outcome)->toBe(['result' => 'resolved_by_fetch'])
        ->and($claim['item']->fresh()->stalled_at)->not->toBeNull()
        ->and($claim['item']->milestones()->count())->toBe(0);
});

test('enumeration-sensitive token failures have a positive control using the same claim', function (array $overrides): void {
    orbClaim();
    orbList(orbToken())->assertOk();
    orbList(orbToken($overrides))->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
})->with([
    'unknown thread' => [['thread_id' => 'T-unknown']],
    'wrong Amp user' => [['user_id' => 'amp-user-conflict']],
    'expired token' => [['exp' => 1]],
    'wrong issuer' => [['iss' => 'https://issuer.example.test']],
    'wrong audience' => [['aud' => 'https://capstan.example.test/mcp/other']],
]);

test('token claim and signature failures are closed', function (Closure $token): void {
    orbClaim();
    orbList($token())->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
})->with([
    'invalid signature' => [orbTokenWithInvalidSignature(...)],
    'unsupported algorithm' => [fn (): string => orbToken([], 'primary', 'HS256')],
    'wrong token use' => [fn (): string => orbToken(['token_use' => 'id'])],
    'unverified email claim' => [fn (): string => orbToken(['email_verified' => false])],
    'project mismatch' => [fn (): string => orbToken(['project_id' => 'amp-project-other'])],
    'malformed subject' => [fn (): string => orbToken(['sub' => 'user:amp-user-1'])],
]);

test('first use refuses a missing inactive or unverified local account without writing a link', function (string $state): void {
    $claim = orbClaim();
    match ($state) {
        'missing' => $claim['user']->delete(),
        'inactive' => $claim['user']->forceFill(['status' => 'inactive'])->save(),
        'unverified' => $claim['user']->forceFill(['email_verified_at' => null])->save(),
    };

    orbList(orbToken())->assertUnauthorized();
    expect(AmpIdentity::query()->count())->toBe(0);
})->with(['missing', 'inactive', 'unverified']);

test('discovery and JWKS failures are closed', function (string $failure): void {
    orbClaim();
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake([
        ORB_DISCOVERY => $failure === 'discovery' ? Http::response([], 503) : Http::response([
            'issuer' => ORB_ISSUER, 'jwks_uri' => ORB_JWKS, 'id_token_signing_alg_values_supported' => ['RS256'],
        ]),
        ORB_JWKS => Http::response([], 503),
    ]);

    orbList(orbToken())->assertUnauthorized();
})->with(['discovery', 'jwks']);

test('foreign or redirecting discovery and JWKS responses are refused without following them', function (string $failure): void {
    orbClaim();
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake(match ($failure) {
        'foreign jwks' => [ORB_DISCOVERY => Http::response([
            'issuer' => ORB_ISSUER,
            'jwks_uri' => 'https://keys.example.test/jwks.json',
            'id_token_signing_alg_values_supported' => ['RS256'],
        ])],
        'discovery redirect' => [
            ORB_DISCOVERY => Http::response('', 302, ['Location' => 'https://keys.example.test/discovery']),
        ],
        'jwks redirect' => [
            ORB_DISCOVERY => Http::response([
                'issuer' => ORB_ISSUER,
                'jwks_uri' => ORB_JWKS,
                'id_token_signing_alg_values_supported' => ['RS256'],
            ]),
            ORB_JWKS => Http::response('', 302, ['Location' => 'https://keys.example.test/jwks.json']),
        ],
    });

    orbList(orbToken())->assertUnauthorized();

    Http::assertSentCount($failure === 'jwks redirect' ? 2 : 1);
})->with(['foreign jwks', 'discovery redirect', 'jwks redirect']);

test('an unknown key refreshes JWKS once and still requires a valid signature', function (): void {
    $claim = orbClaim();
    $rotated = orbKeyPair('rotated');
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake([
        ORB_DISCOVERY => Http::response([
            'issuer' => ORB_ISSUER, 'jwks_uri' => ORB_JWKS, 'id_token_signing_alg_values_supported' => ['RS256'],
        ]),
        ORB_JWKS => Http::sequence()
            ->push(['keys' => [orbKeyPair()['jwk']]])
            ->push(['keys' => [$rotated['jwk']]]),
    ]);

    orbList(orbToken([], 'rotated'))->assertOk();
    Http::assertSentCount(3);
    expect(AmpIdentity::query()->where('user_id', $claim['user']->id)->exists())->toBeTrue();
});

test('repeated unknown keys share one JWKS refresh cooldown', function (): void {
    orbClaim();

    orbList(orbToken([], 'unknown-one'))->assertUnauthorized();
    orbList(orbToken([], 'unknown-two'))->assertUnauthorized();

    Http::assertSentCount(3);
});

test('duplicate matching keys and malformed RSA keys are refused', function (string $failure): void {
    orbClaim();
    $key = orbKeyPair()['jwk'];
    orbFakeIdentityProvider($failure === 'duplicate'
        ? [$key, $key]
        : [[...$key, 'n' => 'not-valid-base64url+']]);

    orbList(orbToken())->assertUnauthorized();
    Http::assertSentCount(2);
})->with(['duplicate', 'malformed']);

test('future and incorrectly typed token times are refused', function (array $overrides): void {
    orbClaim();
    orbList(orbToken())->assertOk();
    orbList(orbToken($overrides))->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
})->with([
    'future issued at' => [['iat' => now()->addSeconds(61)->timestamp]],
    'future not before' => [['nbf' => now()->addSeconds(61)->timestamp]],
    'string expiry' => [['exp' => (string) now()->addMinutes(10)->timestamp]],
    'array issued at' => [['iat' => [now()->timestamp]]],
    'string not before' => [['nbf' => (string) now()->timestamp]],
]);

test('unknown and non-object tool arguments are refused without changing work', function (): void {
    $claim = orbClaim(['stalled_at' => now()->subMinute()]);
    $lease = $claim['item']->lease_until;

    $unknown = orbCall('work_started', ['item_id' => $claim['item']->id], orbToken());
    $list = orbCall('work_progress', ['not-an-object'], orbToken());

    expect($unknown->json('result.structuredContent'))->toBeNull()
        ->and($list->json('result.structuredContent'))->toBeNull()
        ->and($claim['item']->milestones()->count())->toBe(0)
        ->and($claim['item']->fresh()->lease_until?->equalTo($lease))->toBeTrue()
        ->and($claim['item']->fresh()->stalled_at)->not->toBeNull();
});

test('invalid progress summaries do not change claim state', function (mixed $summary): void {
    $claim = orbClaim(['stalled_at' => now()->subMinute(), 'last_progress' => 'Existing progress']);
    $lease = $claim['item']->lease_until;
    $directive = WorkDirective::query()->create([
        'work_item_id' => $claim['item']->id,
        'claimant_spoke_id' => $claim['spoke']->id,
        'fence' => $claim['item']->fence,
        'sequence' => 1,
        'kind' => 'nudge',
        'reason' => 'stall',
        'payload' => [],
        'status' => 'pending',
        'issued_at' => now()->subMinute(),
    ]);

    $response = orbCall('work_progress', ['summary' => $summary], orbToken());

    expect($response->json('result.structuredContent'))->toBeNull()
        ->and($claim['item']->milestones()->count())->toBe(0)
        ->and($claim['item']->fresh()->last_progress)->toBe('Existing progress')
        ->and($claim['item']->fresh()->lease_until?->equalTo($lease))->toBeTrue()
        ->and($claim['item']->fresh()->stalled_at)->not->toBeNull()
        ->and($directive->fresh()->status)->toBe('pending');
})->with([
    'empty' => [''],
    'whitespace' => ['   '],
    'oversized' => [str_repeat('x', WorkItem::LAST_PROGRESS_MAX_LENGTH + 1)],
    'non-string' => [123],
]);

test('claim user team and spoke authority are checked on every request', function (Closure $change): void {
    $claim = orbClaim();
    $token = orbToken();
    orbList($token)->assertOk();
    $change($claim);
    orbList($token)->assertUnauthorized();
})->with([
    'cross team' => [fn (array $claim) => DB::table('team_user')->where('team_id', $claim['team']->id)->where('user_id', $claim['user']->id)->delete()],
    'inactive user' => [fn (array $claim) => $claim['user']->forceFill(['status' => 'inactive'])->save()],
    'unverified user' => [fn (array $claim) => $claim['user']->forceFill(['email_verified_at' => null])->save()],
    'retired spoke' => [fn (array $claim) => $claim['spoke']->forceFill(['retired_at' => now()])->save()],
    'non-authoritative spoke' => [fn (array $claim) => $claim['spoke']->forceFill(['actor_id' => '999999'])->save()],
    'expired lease' => [fn (array $claim) => $claim['item']->forceFill(['lease_until' => now()->subSecond()])->save()],
    'cancelled task' => [fn (array $claim) => $claim['task']->forceFill(['cancelled_at' => now()])->save()],
    'rotated claim' => [fn (array $claim) => $claim['item']->forceFill([
        'fence' => $claim['item']->fence + 1,
        'handed_off_session_ref' => null,
        'launch_nonce' => (string) Str::uuid7(),
    ])->save()],
]);

test('a fence or nonce rotation between authentication and the tool write cannot renew or append', function (string $field): void {
    $claim = orbClaim();
    $lease = $claim['item']->lease_until;
    AmpIdentity::created(function () use ($claim, $field): void {
        WorkItem::query()->whereKey($claim['item']->id)->update($field === 'fence'
            ? ['fence' => $claim['item']->fence + 1]
            : ['launch_nonce' => (string) Str::uuid7()]);
    });

    try {
        $response = orbCall('work_started', (object) [], orbToken())->assertOk();
    } finally {
        AmpIdentity::flushEventListeners();
    }

    $response->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'Work claim is unavailable.');
    expect($claim['item']->milestones()->count())->toBe(0)
        ->and($claim['item']->fresh()->lease_until?->equalTo($lease))->toBeTrue();
})->with(['fence', 'launch_nonce']);

test('more than one live claim for the same Amp project and thread is refused', function (): void {
    $first = orbClaim();
    $second = $first['task']->items()->create([
        'role' => 'reviewer',
        'state' => 'delivered',
        'fence' => 2,
        'handed_off_session_ref' => $first['reference']->encode(),
        'claimant_actor' => (string) $first['user']->id,
        'claimant_spoke_id' => $first['spoke']->id,
        'claimed_at' => now()->subMinute(),
        'lease_until' => now()->addMinutes(5),
        'handed_off_at' => now()->subSeconds(10),
        'amp_project_id' => $first['reference']->projectId,
        'amp_thread_id' => $first['reference']->threadId,
        'launch_nonce' => (string) Str::uuid7(),
    ]);
    expect($second->id)->not->toBe($first['item']->id);

    orbList(orbToken())->assertUnauthorized();
    expect(AmpIdentity::query()->count())->toBe(0);
});

test('claim lookup executes against the indexed exact Amp project and thread', function (): void {
    orbClaim();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });

    orbList(orbToken())->assertOk();

    $lookup = collect($queries)->first(fn (array $query): bool => str_contains($query['sql'], 'amp_project_id')
        && str_contains($query['sql'], 'amp_thread_id') && str_contains($query['sql'], 'work_items'));
    expect($lookup)->not->toBeNull()
        ->and($lookup['bindings'])->toContain('amp-project-1')
        ->and($lookup['bindings'])->toContain('T-orb-thread-1');

    $index = collect(Schema::getIndexes('work_items'))->firstWhere('name', 'work_items_amp_claim_lookup_index');
    expect($index)->not->toBeNull()
        ->and($index['columns'])->toBe(['amp_project_id', 'amp_thread_id'])
        ->and($index['unique'])->toBeFalse();
});

test('first use linking is conflict safe authoritative and cannot rebind', function (): void {
    $claim = orbClaim();
    orbList(orbToken())->assertOk();
    $link = AmpIdentity::query()->sole();
    expect($link->amp_user_id)->toBe('amp-user-1')->and($link->user_id)->toBe($claim['user']->id);

    $other = capstanUser(['email' => 'other-orb@example.test', 'email_verified_at' => now()]);
    $claim['user']->forceFill(['email' => 'changed@example.test'])->save();
    orbList(orbToken(['email' => $other->email]))->assertOk();
    expect(AmpIdentity::query()->sole()->user_id)->toBe($claim['user']->id);

    orbList(orbToken(['user_id' => 'amp-user-2', 'email' => $claim['user']->email]))->assertUnauthorized();
    expect(AmpIdentity::query()->count())->toBe(1);
});

test('malformed delivered Amp references are refused and never published', function (): void {
    $claim = orbClaim(['handed_off_session_ref' => AmpSessionReference::PREFIX.'malformed']);
    orbList(orbToken())->assertUnauthorized();
    $wire = resolve(WorkRepresentation::class)->item($claim['item']->fresh());
    expect($wire)->not->toHaveKey('handed_off_session_ref')
        ->and($wire)->not->toHaveKey('thread_url');
});

test('orb and product or machine authentication doors do not cross', function (): void {
    $claim = orbClaim();
    $orbToken = orbToken();
    orbList($orbToken)->assertOk();
    orbList($orbToken, '/mcp')->assertUnauthorized();
    $this->postJson('/api/v1/work/'.$claim['team']->id.'/claim', [], ['Authorization' => 'Bearer '.$orbToken])->assertUnauthorized();

    $productToken = 'product-'.Str::random(40);
    Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => 'user_principal',
        'subject_ref' => 'capstan-user:'.$claim['user']->id,
        'name' => 'Orb isolation product token',
        'user_id' => (string) $claim['user']->id,
        'abilities' => [],
        'secret_hash' => hash('sha256', $productToken),
        'status' => CredentialStatus::Active,
    ]);
    $product = orbList($productToken, '/mcp')->assertOk();
    $productNames = collect($product->json('result.tools'))->pluck('name');
    expect($productNames)->toContain('get_task')
        ->and($productNames)->not->toContain('work_brief')
        ->and($productNames)->not->toContain('work_started')
        ->and($productNames)->not->toContain('work_progress');
    orbList($productToken)->assertUnauthorized();
});
