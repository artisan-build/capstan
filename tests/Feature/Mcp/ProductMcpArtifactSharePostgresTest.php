<?php

use App\Enums\ArtifactVisibility;
use App\Mcp\DurableWriteCoordinator;
use App\Mcp\OwnerSubject;
use App\Models\Artifact;
use App\Support\ArtifactCreator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

test('postgres serializes simultaneous artifact share submissions without duplicate effects', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Dedicated PostgreSQL race proof.');
    }

    if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
        $this->markTestSkipped('The PostgreSQL race proof requires pcntl and Unix sockets.');
    }

    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
        'app.url' => 'https://app.capstan.test',
        'capstan.artifacts.render_origin' => 'https://artifacts.capstan.test',
    ]);
    Storage::fake();
    DB::connection()->rollBack();

    /**
     * @param  list<string>  $contents
     * @return list<array<string, mixed>>
     */
    $race = function (string $key, array $contents): array {
        $startAt = microtime(true) + 0.75;
        $children = [];

        foreach ($contents as $content) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            if ($sockets === false) {
                throw new RuntimeException('Could not create race result sockets.');
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Could not fork race worker.');
            }

            if ($pid === 0) {
                fclose($sockets[0]);
                DB::purge();

                while (microtime(true) < $startAt) {
                    Sleep::usleep(1_000);
                }

                try {
                    $owner = new OwnerSubject('user_principal', 'capstan-user:postgres-race', 'postgres-race');
                    $response = resolve(DurableWriteCoordinator::class)->run(
                        owner: $owner,
                        tool: 'create_artifact_share',
                        idempotencyKey: $key,
                        intent: [
                            'content' => $content,
                            'content_type' => 'text/html',
                            'visibility' => ArtifactVisibility::SignedUrl->value,
                            'expires_at' => null,
                        ],
                        targetType: 'artifact',
                        operation: function (string $targetId) use ($content, $owner): array {
                            $creator = resolve(ArtifactCreator::class);
                            $artifact = $creator->create(
                                actorId: $owner->actorId,
                                content: $content,
                                contentType: 'text/html',
                                visibility: ArtifactVisibility::SignedUrl,
                                expiresAt: null,
                                id: $targetId,
                            );

                            return ['artifact' => $creator->representation($artifact)];
                        },
                    );
                } catch (Throwable $exception) {
                    $response = ['worker_error' => $exception::class, 'message' => $exception->getMessage()];
                }

                fwrite($sockets[1], json_encode($response, JSON_THROW_ON_ERROR)."\n");
                fclose($sockets[1]);
                exit(0);
            }

            fclose($sockets[1]);
            $children[] = ['pid' => $pid, 'socket' => $sockets[0]];
        }

        $responses = [];

        foreach ($children as $child) {
            pcntl_waitpid($child['pid'], $status);
            $line = fgets($child['socket']);
            fclose($child['socket']);

            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0 || ! is_string($line)) {
                throw new RuntimeException('A race worker did not exit cleanly.');
            }

            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                throw new RuntimeException('A race worker returned an invalid response.');
            }

            $responses[] = $decoded;
        }

        DB::purge();
        DB::reconnect();

        return $responses;
    };

    try {
        $sameContent = '<html><body>PostgreSQL same intent</body></html>';
        $same = $race('postgres-same-intent', [$sameContent, $sameContent]);
        $sameOutcomes = collect($same)->pluck('outcome')->sort()->values()->all();

        expect($sameOutcomes)->toBe(['accepted', 'already_accepted'])
            ->and(collect($same)->pluck('artifact.id')->unique()->count())->toBe(1)
            ->and(Artifact::query()->count())->toBe(1)
            ->and(DB::table('mcp_write_claims')->count())->toBe(1)
            ->and(DB::table('mcp_write_effects')->count())->toBe(1)
            ->and(DB::table('artifact_team')->count())->toBe(1)
            ->and(Storage::disk()->allFiles('artifacts'))->toHaveCount(1);

        $different = $race('postgres-different-intent', [
            '<html><body>PostgreSQL intent A</body></html>',
            '<html><body>PostgreSQL intent B</body></html>',
        ]);

        expect(collect($different)->pluck('outcome')->sort()->values()->all())->toBe(['accepted', 'refused'])
            ->and(collect($different)->firstWhere('outcome', 'refused'))
            ->toBe(['outcome' => 'refused', 'reason' => 'idempotency_key_reused'])
            ->and(Artifact::query()->count())->toBe(2)
            ->and(DB::table('mcp_write_claims')->count())->toBe(2)
            ->and(DB::table('mcp_write_effects')->count())->toBe(2)
            ->and(DB::table('artifact_team')->count())->toBe(2)
            ->and(Storage::disk()->allFiles('artifacts'))->toHaveCount(2);
    } finally {
        DB::table('mcp_write_effects')->delete();
        DB::table('mcp_write_claims')->delete();
        DB::table('artifact_team')->delete();
        Artifact::query()->delete();
        DB::table('teams')->delete();
        Storage::disk()->deleteDirectory('artifacts');
        DB::connection()->beginTransaction();
    }
});
