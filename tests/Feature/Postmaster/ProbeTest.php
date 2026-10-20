<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Enums\ProbeStatus;
use App\Enums\SpokeLiveness;
use App\Features\Postmaster;
use App\Machine\MachineConditionNotifier;
use App\Models\Envelope;
use App\Models\Inbox;
use App\Models\MachineConditionState;
use App\Models\Spoke;
use App\Models\SpokeProbe;
use App\Postmaster\DatabaseProbeFailureNotifier;
use App\Postmaster\ProbeFailureNotifier;
use App\Postmaster\ProbeManager;
use ArtisanBuild\BuiltForCloud\SystemAuthorityContext;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

const PROBE_SERVER_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

class RecordingProbeFailureNotifier implements ProbeFailureNotifier
{
    /** @var list<array{int, int}> */
    public array $notifications = [];

    public function notify(Spoke $spoke, SpokeProbe $probe): void
    {
        $this->notifications[] = [$spoke->id, $probe->id];
    }
}

beforeEach(function (): void {
    config([
        'capstan.features.postmaster' => true,
        'capstan.postmaster.server_id' => PROBE_SERVER_ID,
        'capstan.postmaster.signing_key' => 'probe-test-signing-key',
        'capstan.postmaster.probe.interval_seconds' => 300,
        'capstan.postmaster.probe.timeout_seconds' => 900,
        'capstan.postmaster.probe.backoff_seconds' => 1800,
    ]);
    Feature::flushCache();

    $this->notifier = new RecordingProbeFailureNotifier;
    $this->app->instance(ProbeFailureNotifier::class, $this->notifier);
});

function probeToken(User $user): string
{
    return capstanBoundBearer($user, CapstanCredentialDeclaration::MACHINE)['token'];
}

function createProbeNotificationsTable(): void
{
    Schema::create('notifications', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->morphs('notifiable');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });
}

test('a correct computed echo passes the probe and marks the spoke green without message side effects', function (): void {
    Date::setTestNow('2026-08-17 12:00:00');
    $user = capstanUser();
    $token = probeToken($user);

    $issued = $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['worker']],
    ])->assertOk()
        ->assertJsonPath('probe_challenge.algorithm', 'sha256');

    $challenge = $issued->json('probe_challenge');
    $probe = SpokeProbe::query()->firstOrFail();
    $spoke = Spoke::query()->firstOrFail();

    expect($challenge['probe_id'])->toBe($probe->probe_id)
        ->and($challenge['nonce'])->toBe($probe->nonce)
        ->and(strlen($challenge['nonce']))->toBe(43)
        ->and($challenge['nonce'])->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($probe->status)->toBe(ProbeStatus::Awaiting)
        ->and($spoke->probe_status)->toBe(SpokeLiveness::Unknown);

    $state = [
        'messages' => Envelope::query()->count(),
        'inboxes' => Inbox::query()->count(),
        'routes' => DB::table('spoke_inboxes')->count(),
        'owner' => Inbox::query()->where('local_part', 'worker')->value('actor_id'),
    ];

    Date::setTestNow('2026-08-17 12:00:01');
    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['worker']],
        'probe_response' => [
            'probe_id' => $challenge['probe_id'],
            'digest' => hash('sha256', $challenge['nonce']),
        ],
    ])->assertOk()->assertJsonMissingPath('probe_challenge');

    $probe->refresh();
    $spoke->refresh();
    expect($probe->status)->toBe(ProbeStatus::Passed)
        ->and($probe->responded_at?->toDateTimeString())->toBe('2026-08-17 12:00:01')
        ->and($spoke->probe_status)->toBe(SpokeLiveness::Green)
        ->and($spoke->probe_failed_at)->toBeNull()
        ->and($this->notifier->notifications)->toBe([])
        ->and(Envelope::query()->count())->toBe($state['messages'])
        ->and(Inbox::query()->count())->toBe($state['inboxes'])
        ->and(DB::table('spoke_inboxes')->count())->toBe($state['routes'])
        ->and(Inbox::query()->where('local_part', 'worker')->value('actor_id'))->toBe($state['owner']);
});

test('a wrong digest fails immediately and notifies exactly once', function (): void {
    Date::setTestNow('2026-08-17 12:00:00');
    $user = capstanUser();
    $token = probeToken($user);
    $challenge = $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->json('probe_challenge');

    Date::setTestNow('2026-08-17 12:00:01');
    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => [
            'probe_id' => $challenge['probe_id'],
            'digest' => str_repeat('0', 64),
        ],
    ])->assertOk()->assertJsonMissingPath('probe_challenge');

    $probe = SpokeProbe::query()->firstOrFail();
    $spoke = Spoke::query()->firstOrFail();
    expect($probe->status)->toBe(ProbeStatus::Failed)
        ->and($probe->responded_at?->toDateTimeString())->toBe('2026-08-17 12:00:01')
        ->and($spoke->probe_status)->toBe(SpokeLiveness::Red)
        ->and($spoke->probe_failed_at?->toDateTimeString())->toBe('2026-08-17 12:00:01')
        ->and($this->notifier->notifications)->toBe([[$spoke->id, $probe->id]]);

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => [
            'probe_id' => $challenge['probe_id'],
            'digest' => str_repeat('0', 64),
        ],
    ])->assertOk();

    expect($this->notifier->notifications)->toHaveCount(1);
});

test('the production probe notifier persists one failure and one successful recovery for the machine owner', function (): void {
    Date::setTestNow('2026-10-20 12:00:00');
    config(['capstan.postmaster.probe.backoff_seconds' => 0]);
    $this->app->forgetInstance(ProbeFailureNotifier::class);
    $this->app->bind(ProbeFailureNotifier::class, DatabaseProbeFailureNotifier::class);
    $owner = capstanUser(['name' => 'Probe owner']);
    $token = probeToken($owner);
    $first = $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->json('probe_challenge');

    Date::setTestNow('2026-10-20 12:00:01');
    $second = $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => ['probe_id' => $first['probe_id'], 'digest' => str_repeat('0', 64)],
    ])->assertOk()->json('probe_challenge');
    expect($second)->toBeArray()
        ->and($owner->fresh()->notifications)->toHaveCount(1)
        ->and($owner->fresh()->notifications->sole()->data['state_transition'])->toBe('started');

    Date::setTestNow('2026-10-20 12:00:02');
    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => [
            'probe_id' => $second['probe_id'],
            'digest' => hash('sha256', $second['nonce']),
        ],
    ])->assertOk();

    expect($owner->fresh()->notifications)->toHaveCount(2)
        ->and($owner->fresh()->notifications->pluck('data.state_transition')->sort()->values()->all())
        ->toBe(['recovered', 'started']);
});

test('an HTTP first-red notification failure rolls back and the identical response retries once', function (): void {
    Date::setTestNow('2026-10-20 12:00:00');
    $this->app->forgetInstance(ProbeFailureNotifier::class);
    $this->app->bind(ProbeFailureNotifier::class, DatabaseProbeFailureNotifier::class);
    $owner = capstanUser(['name' => 'HTTP durable probe owner']);
    $token = probeToken($owner);
    $challenge = $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->json('probe_challenge');
    $response = [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => ['probe_id' => $challenge['probe_id'], 'digest' => str_repeat('0', 64)],
    ];

    Schema::drop('notifications');
    try {
        Date::setTestNow('2026-10-20 12:00:01');
        $this->withToken($token)->postJson('/api/v1/poll', $response)->assertServerError();
        expect(SpokeProbe::query()->where('probe_id', $challenge['probe_id'])->sole()->status)->toBe(ProbeStatus::Awaiting)
            ->and(Spoke::query()->sole()->probe_status)->toBe(SpokeLiveness::Unknown)
            ->and(MachineConditionState::query()->where('finding_code', MachineConditionNotifier::PROBE_FAILURE)->doesntExist())->toBeTrue();
    } finally {
        if (! Schema::hasTable('notifications')) {
            createProbeNotificationsTable();
        }
    }

    $this->withToken($token)->postJson('/api/v1/poll', $response)->assertOk();
    $this->withToken($token)->postJson('/api/v1/poll', $response)->assertOk();
    expect(SpokeProbe::query()->where('probe_id', $challenge['probe_id'])->sole()->status)->toBe(ProbeStatus::Failed)
        ->and(Spoke::query()->sole()->probe_status)->toBe(SpokeLiveness::Red)
        ->and($owner->fresh()->notifications)->toHaveCount(1)
        ->and($owner->fresh()->notifications->sole()->data['state_transition'])->toBe('started')
        ->and(MachineConditionState::query()->where('finding_code', MachineConditionNotifier::PROBE_FAILURE)->sole()->active)->toBeTrue();
});

test('a scheduled first-red notification failure rolls back and remains retryable', function (): void {
    Date::setTestNow('2026-10-20 12:00:00');
    $this->app->forgetInstance(ProbeFailureNotifier::class);
    $this->app->bind(ProbeFailureNotifier::class, DatabaseProbeFailureNotifier::class);
    $owner = capstanUser(['name' => 'Sweep durable probe owner']);
    $this->withToken(probeToken($owner))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk();

    Schema::drop('notifications');
    try {
        Date::setTestNow('2026-10-20 12:15:00');
        $this->artisan('postmaster:probe-sweep')
            ->expectsOutput('Failed 0 overdue probe(s).')
            ->assertSuccessful();
        expect(SpokeProbe::query()->sole()->status)->toBe(ProbeStatus::Awaiting)
            ->and(Spoke::query()->sole()->probe_status)->toBe(SpokeLiveness::Unknown)
            ->and(MachineConditionState::query()->where('finding_code', MachineConditionNotifier::PROBE_FAILURE)->doesntExist())->toBeTrue();
    } finally {
        if (! Schema::hasTable('notifications')) {
            createProbeNotificationsTable();
        }
    }

    $this->artisan('postmaster:probe-sweep')
        ->expectsOutput('Failed 1 overdue probe(s).')
        ->assertSuccessful();
    $this->artisan('postmaster:probe-sweep')
        ->expectsOutput('Failed 0 overdue probe(s).')
        ->assertSuccessful();
    expect(SpokeProbe::query()->sole()->status)->toBe(ProbeStatus::Failed)
        ->and(Spoke::query()->sole()->probe_status)->toBe(SpokeLiveness::Red)
        ->and($owner->fresh()->notifications)->toHaveCount(1)
        ->and($owner->fresh()->notifications->sole()->data['state_transition'])->toBe('started');
});

test('a recovery notification failure rolls back green state and the identical response retries once', function (): void {
    Date::setTestNow('2026-10-20 12:00:00');
    config(['capstan.postmaster.probe.backoff_seconds' => 0]);
    $this->app->forgetInstance(ProbeFailureNotifier::class);
    $this->app->bind(ProbeFailureNotifier::class, DatabaseProbeFailureNotifier::class);
    $owner = capstanUser(['name' => 'Recovery durable probe owner']);
    $token = probeToken($owner);
    $failed = $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->json('probe_challenge');
    $recovery = $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => ['probe_id' => $failed['probe_id'], 'digest' => str_repeat('0', 64)],
    ])->assertOk()->json('probe_challenge');
    $response = [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => [
            'probe_id' => $recovery['probe_id'],
            'digest' => hash('sha256', $recovery['nonce']),
        ],
    ];

    $startedNotification = (array) DB::table('notifications')->sole();
    Schema::drop('notifications');
    try {
        $this->withToken($token)->postJson('/api/v1/poll', $response)->assertServerError();
        expect(SpokeProbe::query()->where('probe_id', $recovery['probe_id'])->sole()->status)->toBe(ProbeStatus::Awaiting)
            ->and(Spoke::query()->sole()->probe_status)->toBe(SpokeLiveness::Red)
            ->and(Spoke::query()->sole()->probe_failed_at)->not->toBeNull()
            ->and(MachineConditionState::query()->where('finding_code', MachineConditionNotifier::PROBE_FAILURE)->sole()->active)->toBeTrue();
    } finally {
        if (! Schema::hasTable('notifications')) {
            createProbeNotificationsTable();
            DB::table('notifications')->insert($startedNotification);
        }
    }

    $this->withToken($token)->postJson('/api/v1/poll', $response)->assertOk();
    $this->withToken($token)->postJson('/api/v1/poll', $response)->assertOk();
    expect(SpokeProbe::query()->where('probe_id', $recovery['probe_id'])->sole()->status)->toBe(ProbeStatus::Passed)
        ->and(Spoke::query()->sole()->probe_status)->toBe(SpokeLiveness::Green)
        ->and(Spoke::query()->sole()->probe_failed_at)->toBeNull()
        ->and(MachineConditionState::query()->where('finding_code', MachineConditionNotifier::PROBE_FAILURE)->sole()->active)->toBeFalse()
        ->and($owner->fresh()->notifications)->toHaveCount(2)
        ->and($owner->fresh()->notifications->pluck('data.state_transition')->sort()->values()->all())->toBe(['recovered', 'started']);
});

test('malformed probe responses return the api validation envelope without changing probe state', function (): void {
    $user = capstanUser();
    $token = probeToken($user);
    $challenge = $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->json('probe_challenge');

    $malformed = [
        ['probe_id' => $challenge['probe_id'], 'digest' => 'too-short'],
        ['probe_id' => $challenge['probe_id']],
        ['not', 'an', 'object'],
        null,
    ];

    foreach ($malformed as $response) {
        $this->withToken($token)->postJson('/api/v1/poll', [
            'presence' => ['ready_inboxes' => []],
            'probe_response' => $response,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
    }

    $probe = SpokeProbe::query()->firstOrFail();
    expect($probe->status)->toBe(ProbeStatus::Awaiting)
        ->and($probe->responded_at)->toBeNull()
        ->and($this->notifier->notifications)->toBe([]);
});

test('unknown and foreign probe responses are ignored without changing another spoke', function (): void {
    $alice = capstanUser();
    $bob = capstanUser();
    $aliceToken = probeToken($alice);
    $bobToken = probeToken($bob);
    $aliceChallenge = $this->withToken($aliceToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->json('probe_challenge');
    $bobChallenge = $this->withToken($bobToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->json('probe_challenge');

    $this->withToken($bobToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => [
            'probe_id' => $aliceChallenge['probe_id'],
            'digest' => hash('sha256', $aliceChallenge['nonce']),
        ],
    ])->assertOk()->assertJsonPath('probe_challenge', $bobChallenge);

    $this->withToken($bobToken)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => [
            'probe_id' => (string) Str::ulid(),
            'digest' => str_repeat('0', 64),
        ],
    ])->assertOk()->assertJsonPath('probe_challenge', $bobChallenge);

    expect(SpokeProbe::query()->where('status', ProbeStatus::Awaiting->value)->count())->toBe(2)
        ->and(Spoke::query()->where('probe_status', SpokeLiveness::Unknown->value)->count())->toBe(2)
        ->and($this->notifier->notifications)->toBe([]);
});

test('the scheduled sweep detects a spoke that stopped polling and is idempotent', function (): void {
    Date::setTestNow('2026-08-17 12:00:00');
    $user = capstanUser();
    $this->withToken(probeToken($user))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk();

    Date::setTestNow('2026-08-17 12:15:00');
    $this->artisan('postmaster:probe-sweep')
        ->expectsOutput('Failed 1 overdue probe(s).')
        ->assertSuccessful();

    $probe = SpokeProbe::query()->firstOrFail();
    $spoke = Spoke::query()->firstOrFail();
    expect($probe->status)->toBe(ProbeStatus::Failed)
        ->and($probe->responded_at)->toBeNull()
        ->and($spoke->probe_status)->toBe(SpokeLiveness::Red)
        ->and($spoke->probe_failed_at?->toDateTimeString())->toBe('2026-08-17 12:15:00')
        ->and($this->notifier->notifications)->toBe([[$spoke->id, $probe->id]]);

    $this->artisan('postmaster:probe-sweep')
        ->expectsOutput('Failed 0 overdue probe(s).')
        ->assertSuccessful();

    expect($this->notifier->notifications)->toHaveCount(1);
});

test('a lost response is recovered by replaying the same challenge until it passes in window', function (): void {
    Date::setTestNow('2026-08-17 12:00:00');
    $user = capstanUser();
    $token = probeToken($user);
    $challenge = $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->json('probe_challenge');

    foreach (['12:01:00', '12:05:00', '12:10:00', '12:14:59'] as $time) {
        Date::setTestNow("2026-08-17 {$time}");
        $this->withToken($token)->postJson('/api/v1/poll', [
            'presence' => ['ready_inboxes' => []],
        ])->assertOk()->assertJsonPath('probe_challenge', $challenge);
    }

    expect(SpokeProbe::query()->count())->toBe(1);

    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => [
            'probe_id' => $challenge['probe_id'],
            'digest' => hash('sha256', $challenge['nonce']),
        ],
    ])->assertOk();

    expect(SpokeProbe::query()->where('probe_id', $challenge['probe_id'])->firstOrFail()->status)->toBe(ProbeStatus::Passed)
        ->and(Spoke::query()->firstOrFail()->probe_status)->toBe(SpokeLiveness::Green);

    expect(SpokeProbe::query()->count())->toBe(2);
});

test('a failed spoke observes backoff before another challenge', function (): void {
    config(['capstan.postmaster.probe.interval_seconds' => 3600]);
    Date::setTestNow('2026-08-17 12:00:00');
    $user = capstanUser();
    $token = probeToken($user);
    $challenge = $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->json('probe_challenge');

    Date::setTestNow('2026-08-17 12:00:01');
    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => [
            'probe_id' => $challenge['probe_id'],
            'digest' => str_repeat('0', 64),
        ],
    ])->assertOk()->assertJsonMissingPath('probe_challenge');

    Date::setTestNow('2026-08-17 12:30:00');
    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->assertJsonMissingPath('probe_challenge');

    Date::setTestNow('2026-08-17 12:30:01');
    $next = $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->assertJsonPath('probe_challenge.algorithm', 'sha256')->json('probe_challenge');

    Date::setTestNow('2026-08-17 12:30:02');
    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => [
            'probe_id' => $next['probe_id'],
            'digest' => str_repeat('0', 64),
        ],
    ])->assertOk();

    expect($this->notifier->notifications)->toHaveCount(1);
});

test('an expired probe cannot be redeemed with a correct digest', function (): void {
    config(['capstan.postmaster.probe.timeout_seconds' => 60]);
    Date::setTestNow('2026-08-17 12:00:00');
    $user = capstanUser();
    $token = probeToken($user);
    $challenge = $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->json('probe_challenge');

    Date::setTestNow('2026-08-17 12:01:01');
    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => [
            'probe_id' => $challenge['probe_id'],
            'digest' => hash('sha256', $challenge['nonce']),
        ],
    ])->assertOk();

    $probe = SpokeProbe::query()->firstOrFail();
    $spoke = Spoke::query()->firstOrFail();
    expect($probe->status)->toBe(ProbeStatus::Awaiting)
        ->and($probe->responded_at)->toBeNull()
        ->and($spoke->probe_status)->toBe(SpokeLiveness::Unknown)
        ->and($spoke->probe_failed_at)->toBeNull();
});

test('a red spoke recovers to green after passing a new probe', function (): void {
    Date::setTestNow('2026-08-17 12:00:00');
    $user = capstanUser();
    $token = probeToken($user);
    $failed = $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->json('probe_challenge');

    Date::setTestNow('2026-08-17 12:00:01');
    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => [
            'probe_id' => $failed['probe_id'],
            'digest' => str_repeat('0', 64),
        ],
    ])->assertOk();

    $spoke = Spoke::query()->firstOrFail();
    expect($spoke->probe_status)->toBe(SpokeLiveness::Red)
        ->and($spoke->probe_failed_at)->not->toBeNull();

    Date::setTestNow('2026-08-17 12:30:01');
    $recovery = $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->json('probe_challenge');

    Date::setTestNow('2026-08-17 12:30:02');
    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => [
            'probe_id' => $recovery['probe_id'],
            'digest' => hash('sha256', $recovery['nonce']),
        ],
    ])->assertOk();

    $recoveredProbe = SpokeProbe::query()->where('probe_id', $recovery['probe_id'])->firstOrFail();
    $spoke->refresh();
    expect($recoveredProbe->status)->toBe(ProbeStatus::Passed)
        ->and($recoveredProbe->responded_at?->toDateTimeString())->toBe('2026-08-17 12:30:02')
        ->and($spoke->probe_status)->toBe(SpokeLiveness::Green)
        ->and($spoke->probe_failed_at)->toBeNull();

    Date::setTestNow('2026-08-17 12:35:01');
    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->assertJsonPath('probe_challenge.algorithm', 'sha256');
});

test('an expired challenge does not block a later challenge and only the sweep fails it', function (): void {
    config(['capstan.postmaster.probe.timeout_seconds' => 60]);
    Date::setTestNow('2026-08-17 12:00:00');
    $user = capstanUser();
    $token = probeToken($user);
    $firstId = $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->json('probe_challenge.probe_id');

    Date::setTestNow('2026-08-17 12:05:00');
    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->assertJsonPath('probe_challenge.algorithm', 'sha256');

    expect(SpokeProbe::query()->where('probe_id', $firstId)->firstOrFail()->status)->toBe(ProbeStatus::Awaiting)
        ->and(Spoke::query()->firstOrFail()->probe_status)->toBe(SpokeLiveness::Unknown)
        ->and(SpokeProbe::query()->count())->toBe(2);

    $this->artisan('postmaster:probe-sweep')
        ->expectsOutput('Failed 1 overdue probe(s).')
        ->assertSuccessful();

    expect(SpokeProbe::query()->where('probe_id', $firstId)->firstOrFail()->status)->toBe(ProbeStatus::Failed)
        ->and(SpokeProbe::query()->where('status', ProbeStatus::Awaiting->value)->count())->toBe(1)
        ->and(Spoke::query()->firstOrFail()->probe_status)->toBe(SpokeLiveness::Red)
        ->and($this->notifier->notifications)->toHaveCount(1);
});

test('sweeping a stale probe cannot overwrite a newer successful probe', function (): void {
    Date::setTestNow('2026-08-17 12:00:00');
    $user = capstanUser();
    $token = probeToken($user);
    $staleId = $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->json('probe_challenge.probe_id');

    Date::setTestNow('2026-08-17 12:15:00');
    $newer = $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->json('probe_challenge');

    Date::setTestNow('2026-08-17 12:15:05');
    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => [
            'probe_id' => $newer['probe_id'],
            'digest' => hash('sha256', $newer['nonce']),
        ],
    ])->assertOk();

    Date::setTestNow('2026-08-17 12:15:30');
    $this->artisan('postmaster:probe-sweep')
        ->expectsOutput('Failed 1 overdue probe(s).')
        ->assertSuccessful();

    expect(SpokeProbe::query()->where('probe_id', $staleId)->firstOrFail()->status)->toBe(ProbeStatus::Failed)
        ->and(SpokeProbe::query()->where('probe_id', $newer['probe_id'])->firstOrFail()->status)->toBe(ProbeStatus::Passed)
        ->and(Spoke::query()->firstOrFail()->probe_status)->toBe(SpokeLiveness::Green)
        ->and(Spoke::query()->firstOrFail()->probe_failed_at)->toBeNull()
        ->and($this->notifier->notifications)->toBe([]);

    Date::setTestNow('2026-08-17 12:20:00');
    $this->withToken($token)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->assertJsonPath('probe_challenge.algorithm', 'sha256');
});

test('a throwing notifier rolls back one sweep candidate without stopping unrelated candidates', function (): void {
    $notifier = new class implements ProbeFailureNotifier
    {
        public int $calls = 0;

        /** @var list<int> */
        public array $notifications = [];

        public function notify(Spoke $spoke, SpokeProbe $probe): void
        {
            $this->calls++;
            if ($this->calls === 1) {
                throw new RuntimeException('Notifier unavailable.');
            }

            $this->notifications[] = $probe->id;
        }
    };
    $this->app->instance(ProbeFailureNotifier::class, $notifier);
    Log::spy();
    Date::setTestNow('2026-08-17 12:00:00');

    foreach ([capstanUser(), capstanUser()] as $user) {
        $this->withToken(probeToken($user))->postJson('/api/v1/poll', [
            'presence' => ['ready_inboxes' => []],
        ])->assertOk();
    }

    Date::setTestNow('2026-08-17 12:15:00');
    $this->artisan('postmaster:probe-sweep')
        ->expectsOutput('Failed 1 overdue probe(s).')
        ->assertSuccessful();

    expect(SpokeProbe::query()->where('status', ProbeStatus::Awaiting->value)->count())->toBe(1)
        ->and(SpokeProbe::query()->where('status', ProbeStatus::Failed->value)->count())->toBe(1)
        ->and(Spoke::query()->where('probe_status', SpokeLiveness::Unknown->value)->count())->toBe(1)
        ->and(Spoke::query()->where('probe_status', SpokeLiveness::Red->value)->count())->toBe(1)
        ->and($notifier->notifications)->toHaveCount(1);
    Log::shouldHaveReceived('error')->once();

    $this->artisan('postmaster:probe-sweep')
        ->expectsOutput('Failed 1 overdue probe(s).')
        ->assertSuccessful();
    expect(SpokeProbe::query()->where('status', ProbeStatus::Failed->value)->count())->toBe(2)
        ->and(Spoke::query()->where('probe_status', SpokeLiveness::Red->value)->count())->toBe(2)
        ->and($notifier->notifications)->toHaveCount(2);
});

test('probe ids are protected by a database unique constraint', function (): void {
    $user = capstanUser();
    $this->withToken(probeToken($user))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk();

    $probe = SpokeProbe::query()->firstOrFail();
    DB::table('spoke_probes')->insertOrIgnore([
        'spoke_id' => $probe->spoke_id,
        'probe_id' => $probe->probe_id,
        'nonce' => str_repeat('a', 43),
        'status' => ProbeStatus::Awaiting->value,
        'issued_at' => now(),
        'expires_at' => now()->addMinute(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(SpokeProbe::query()->count())->toBe(1);
});

test('the overdue probe sweep is scheduled every minute', function (): void {
    $scheduled = collect(resolve(Schedule::class)->events())->contains(fn ($event): bool => str_contains($event->command, 'postmaster:probe-sweep')
        && $event->expression === '* * * * *'
        && $event->expiresAt === 5);

    expect($scheduled)->toBeTrue();
});

test('the probe sweep runs with system authority and never synthesizes a human principal', function (): void {
    $context = resolve(SystemAuthorityContext::class);
    $manager = new class($context) extends ProbeManager
    {
        public bool $authorityWasActive = false;

        public mixed $principal = 'not-observed';

        public function __construct(private readonly SystemAuthorityContext $context) {}

        public function fail(Spoke $spoke, SpokeProbe $probe, CarbonImmutable $now, bool $responded): bool
        {
            $this->authorityWasActive = $this->context->active();
            $this->principal = auth()->user();

            return parent::fail($spoke, $probe, $now, $responded);
        }
    };
    $this->app->instance(ProbeManager::class, $manager);
    $spoke = Spoke::query()->create([
        'actor_id' => 'test-created-actor',
        'credential_id' => (string) Str::uuid(),
        'last_polled_at' => now()->subHour(),
    ]);
    SpokeProbe::query()->create([
        'spoke_id' => $spoke->id,
        'probe_id' => (string) Str::ulid(),
        'nonce' => str_repeat('a', 43),
        'status' => ProbeStatus::Awaiting,
        'issued_at' => now()->subHour(),
        'expires_at' => now()->subMinute(),
    ]);

    expect($context->active())->toBeFalse()
        ->and(auth()->user())->toBeNull();

    $this->artisan('postmaster:probe-sweep')->assertSuccessful();

    expect($manager->authorityWasActive)->toBeTrue()
        ->and($manager->principal)->toBeNull()
        ->and($context->active())->toBeFalse()
        ->and(auth()->user())->toBeNull();
});

test('probe timeouts are clamped to the sweep cadence', function (): void {
    config(['capstan.postmaster.probe.timeout_seconds' => 0]);
    Date::setTestNow('2026-08-17 12:00:00');
    $user = capstanUser();
    $this->withToken(probeToken($user))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk();

    $probe = SpokeProbe::query()->firstOrFail();
    expect($probe->expires_at->diffInSeconds($probe->issued_at, true))->toBe(60.0);
});

test('obsolete feature config cannot prevent a probe or spoke', function (): void {
    config(['capstan.features.postmaster' => false]);
    Feature::flushCache();
    $user = capstanUser();

    $this->withToken(probeToken($user))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->assertJsonPath('probe_challenge.algorithm', 'sha256');

    expect(Feature::active(Postmaster::class))->toBeTrue()
        ->and(Spoke::query()->count())->toBe(1)
        ->and(SpokeProbe::query()->count())->toBe(1);
});
