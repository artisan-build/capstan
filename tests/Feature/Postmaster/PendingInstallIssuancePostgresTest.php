<?php

use App\Models\Team;
use App\Postmaster\GeneratedOnboardingSnippet;
use App\Postmaster\OnboardingSnippet;
use App\Postmaster\PendingInstallLinks;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

test('postgres serializes concurrent installer issuance for one actor', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Dedicated PostgreSQL installer issuance contention proof.');
    }

    if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
        $this->markTestSkipped('The PostgreSQL contention proof requires pcntl and Unix sockets.');
    }

    DB::connection()->rollBack();
    config(['app.url' => 'https://capstan.example']);
    $operator = capstanUser();
    $actorId = (string) $operator->getKey();
    $authorizationIds = [
        1 => (string) Str::uuid(),
        2 => (string) Str::uuid(),
    ];
    $now = now();
    foreach ($authorizationIds as $worker => $authorizationId) {
        DB::table('credential_authorizations')->insert([
            'id' => $authorizationId,
            'flow' => 'device',
            'device_code_hash' => hash('sha256', 'postgres-device-'.$worker.'-'.Str::random(16)),
            'user_code_hash' => hash('sha256', 'postgres-user-'.$worker.'-'.Str::random(16)),
            'status' => 'pending',
            'app_purpose' => 'postmaster.concurrent-issuance',
            'protocol_purpose' => 'postmaster.concurrent-issuance',
            'subject_type' => 'user',
            'subject_ref' => $actorId,
            'installation_ref' => 'test-installation',
            'application_ref' => 'test-application',
            'audience' => 'test-audience',
            'ownership' => 'user',
            'abilities' => '[]',
            'initiating_user_id' => $actorId,
            'browser_session_nonce_hash' => hash('sha256', 'postgres-browser-'.$worker.'-'.Str::random(16)),
            'profile_code_ttl_seconds' => 600,
            'profile_initial_poll_interval' => 5,
            'base_interval' => 5,
            'effective_interval' => 5,
            'expires_at' => $now->addMinutes(10),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
    $children = [];
    $parentLockOpen = false;

    try {
        foreach ([1, 2] as $worker) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            if ($sockets === false) {
                throw new RuntimeException('Could not create installer issuance sockets.');
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Could not fork an installer issuance worker.');
            }

            if ($pid === 0) {
                fclose($sockets[0]);
                DB::purge();
                $backendPid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
                fwrite($sockets[1], 'ready:'.$backendPid."\n");
                $release = fgets($sockets[1]);

                try {
                    if ($release !== "go\n") {
                        throw new RuntimeException('The installer issuance barrier was not released.');
                    }

                    $snippet = new class($authorizationIds[$worker], $worker) extends OnboardingSnippet
                    {
                        public function __construct(
                            private readonly string $authorizationId,
                            private readonly int $worker,
                        ) {}

                        public function generateWithAuthorization(Request $request, string $actorId): GeneratedOnboardingSnippet
                        {
                            return new GeneratedOnboardingSnippet(
                                "#!/bin/sh\n(\nprintf '%s\\n' 'worker-{$this->worker}'\n)\n",
                                $this->authorizationId,
                                [
                                    'team_id' => Team::default()->id,
                                    'enabled' => true,
                                    'roles' => ['builder'],
                                    'capabilities' => [],
                                    'role_providers' => [],
                                    'concurrency_ceiling' => 1,
                                ],
                            );
                        }
                    };
                    $install = (new PendingInstallLinks($snippet))->issue(
                        Request::create('/postmaster', 'POST'),
                        $actorId,
                    );
                    $response = [
                        'outcome' => 'issued',
                        'command' => $install->command,
                        'completed_at' => hrtime(true),
                    ];
                } catch (Throwable $exception) {
                    $response = [
                        'worker_error' => $exception::class,
                        'message' => $exception->getMessage(),
                        'completed_at' => hrtime(true),
                    ];
                }

                fwrite($sockets[1], json_encode($response, JSON_THROW_ON_ERROR)."\n");
                fclose($sockets[1]);
                exit(0);
            }

            fclose($sockets[1]);
            $children[] = ['pid' => $pid, 'socket' => $sockets[0]];
        }

        $backendPids = [];

        foreach ($children as $child) {
            $ready = fgets($child['socket']);

            if (! is_string($ready) || preg_match('/\Aready:([1-9][0-9]*)\n\z/D', $ready, $matches) !== 1) {
                throw new RuntimeException('An installer issuance worker did not reach the barrier.');
            }

            $backendPids[] = (int) $matches[1];
        }

        DB::connection()->beginTransaction();
        $parentLockOpen = true;
        User::query()->whereKey($actorId)->lockForUpdate()->firstOrFail();

        foreach ($children as $child) {
            fwrite($child['socket'], "go\n");
        }

        $overlapObserved = false;
        $deadline = microtime(true) + 5;

        do {
            $blocked = DB::table('pg_stat_activity')
                ->whereIn('pid', $backendPids)
                ->where('wait_event_type', 'Lock')
                ->count();

            if ($blocked === 2) {
                $overlapObserved = true;
                break;
            }

            Sleep::usleep(10_000);
        } while (microtime(true) < $deadline);

        DB::connection()->commit();
        $parentLockOpen = false;
        $responses = [];

        foreach ($children as $child) {
            pcntl_waitpid($child['pid'], $status);
            $line = fgets($child['socket']);
            fclose($child['socket']);

            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0 || ! is_string($line)) {
                throw new RuntimeException('An installer issuance worker did not exit cleanly.');
            }

            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                throw new RuntimeException('An installer issuance worker returned an invalid response.');
            }

            $responses[] = $decoded;
        }

        DB::purge();
        DB::reconnect();
        usort($responses, static fn (array $first, array $second): int => $first['completed_at'] <=> $second['completed_at']);
        $firstCommand = $responses[0]['command'] ?? '';
        $firstUrl = (string) str((string) $firstCommand)->betweenFirst("'", "'");
        $firstPath = parse_url($firstUrl, PHP_URL_PATH);

        expect($overlapObserved)->toBeTrue()
            ->and(collect($responses)->pluck('outcome')->all())->toBe(['issued', 'issued'])
            ->and(DB::table('postmaster_installs')->where('actor_id', $actorId)->whereNull('used_at')->count())->toBe(1)
            ->and($firstPath)->toBeString();

        $this->get($firstPath)
            ->assertGone()
            ->assertSeeText('This install link was already used or has expired. Generate a new one.');
    } finally {
        if ($parentLockOpen) {
            DB::connection()->rollBack();
        }

        foreach ($children as $child) {
            if (is_resource($child['socket'])) {
                fclose($child['socket']);
            }
        }

        DB::purge();
        DB::reconnect();
        DB::table('postmaster_installs')->where('actor_id', $actorId)->delete();
        DB::table('credential_authorizations')->whereIn('id', $authorizationIds)->delete();
        User::query()->whereKey($actorId)->delete();
        DB::connection()->beginTransaction();
    }
});
