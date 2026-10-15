<?php

use App\Enums\PageLayout;
use App\Models\Page;
use App\Models\PageVersion;
use App\Support\PagePublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

test('postgres serializes genuinely overlapping page appends without losing immutable versions', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Dedicated PostgreSQL page append contention proof.');
    }

    if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
        $this->markTestSkipped('The PostgreSQL contention proof requires pcntl and Unix sockets.');
    }

    Storage::fake();
    DB::connection()->rollBack();

    $ownerId = 'postgres-page-owner';
    $initialHtml = '<html><body>PostgreSQL initial '.Str::random(12).'</body></html>';
    $initialRecipe = 'PostgreSQL initial recipe '.Str::random(12);
    $page = resolve(PagePublisher::class)->publish(
        pageId: (string) Str::uuid(),
        ownerUserId: $ownerId,
        title: 'PostgreSQL contention '.Str::random(8),
        description: 'Test-created contention page',
        refreshRecipe: $initialRecipe,
        layout: PageLayout::Plain,
        content: $initialHtml,
        contentType: 'text/html',
    );
    $first = $page->latestVersion()->firstOrFail();
    $firstMetadata = $first->only([
        'id',
        'page_id',
        'version_number',
        'content_type',
        'size_bytes',
        'content_hash',
        'storage_key',
        'publisher_user_id',
        'publisher_source',
        'publisher_tool',
        'refresh_recipe',
        'layout',
    ]);
    $contenders = [
        [
            'version_id' => (string) Str::uuid(),
            'content' => '<html><body>PostgreSQL contender A '.Str::random(12).'</body></html>',
            'recipe' => 'PostgreSQL recipe A '.Str::random(12),
        ],
        [
            'version_id' => (string) Str::uuid(),
            'content' => '<html><body>PostgreSQL contender B '.Str::random(12).'</body></html>',
            'recipe' => 'PostgreSQL recipe B '.Str::random(12),
        ],
    ];
    $children = [];
    $parentLockOpen = false;

    try {
        foreach ($contenders as $contender) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            if ($sockets === false) {
                throw new RuntimeException('Could not create page contention sockets.');
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Could not fork a page contention worker.');
            }

            if ($pid === 0) {
                fclose($sockets[0]);
                DB::purge();
                $backendPid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
                fwrite($sockets[1], 'ready:'.$backendPid."\n");
                $release = fgets($sockets[1]);

                try {
                    if ($release !== "go\n") {
                        throw new RuntimeException('The page contention barrier was not released.');
                    }

                    $updated = resolve(PagePublisher::class)->update(
                        pageId: $page->id,
                        ownerUserId: $ownerId,
                        versionId: $contender['version_id'],
                        replacements: ['refresh_recipe' => $contender['recipe']],
                        content: $contender['content'],
                        contentType: 'text/html',
                    );
                    $response = [
                        'outcome' => 'settled',
                        'version_id' => $contender['version_id'],
                        'latest_version_id' => $updated->latest_version_id,
                    ];
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

        $backendPids = [];

        foreach ($children as $child) {
            $ready = fgets($child['socket']);

            if (! is_string($ready) || preg_match('/\Aready:([1-9][0-9]*)\n\z/D', $ready, $matches) !== 1) {
                throw new RuntimeException('A page contention worker did not reach the barrier.');
            }

            $backendPids[] = (int) $matches[1];
        }

        DB::connection()->beginTransaction();
        $parentLockOpen = true;
        Page::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();

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
                throw new RuntimeException('A page contention worker did not exit cleanly.');
            }

            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                throw new RuntimeException('A page contention worker returned an invalid response.');
            }

            $responses[] = $decoded;
        }

        DB::purge();
        DB::reconnect();
        $page = Page::query()->whereKey($page->id)->with('versions')->firstOrFail();
        $versions = $page->versions->sortBy('version_number')->values();
        $latest = $versions->last();
        $storedContents = $versions->mapWithKeys(
            fn (PageVersion $version): array => [$version->id => Storage::disk()->get($version->storage_key)],
        );
        $expectedVersionIds = [$first->id, ...array_column($contenders, 'version_id')];
        $latestContender = collect($contenders)->firstWhere('version_id', $latest?->id);

        expect($overlapObserved)->toBeTrue()
            ->and(collect($responses)->pluck('outcome')->all())->toBe(['settled', 'settled'])
            ->and($versions->pluck('version_number')->all())->toBe([1, 2, 3])
            ->and($versions->pluck('version_number')->unique()->count())->toBe(3)
            ->and($versions->pluck('id')->unique()->count())->toBe(3)
            ->and($versions->pluck('id')->all())->toEqualCanonicalizing($expectedVersionIds)
            ->and($page->version_count)->toBe(3)
            ->and($latest)->toBeInstanceOf(PageVersion::class)
            ->and($latest?->version_number)->toBe(3)
            ->and($page->latest_version_id)->toBe($latest?->id)
            ->and($latestContender)->toBeArray()
            ->and($storedContents->get($latest?->id))->toBe($latestContender['content'] ?? null)
            ->and($storedContents->values()->all())->toEqualCanonicalizing([
                $initialHtml,
                $contenders[0]['content'],
                $contenders[1]['content'],
            ])
            ->and($first->fresh()?->only(array_keys($firstMetadata)))->toBe($firstMetadata)
            ->and(Storage::disk()->get($first->storage_key))->toBe($initialHtml)
            ->and(Page::query()->count())->toBe(1)
            ->and(PageVersion::query()->count())->toBe(3)
            ->and(Storage::disk()->allFiles('artifacts'))->toHaveCount(3)
            ->and(collect(Storage::disk()->allFiles('artifacts'))->sort()->values()->all())
            ->toBe($versions->pluck('storage_key')->sort()->values()->all());

        foreach ($contenders as $contender) {
            $version = $versions->firstWhere('id', $contender['version_id']);
            expect($version)->toBeInstanceOf(PageVersion::class)
                ->and($version?->refresh_recipe)->toBe($contender['recipe'])
                ->and($storedContents->get($contender['version_id']))->toBe($contender['content']);
        }
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
        Page::query()->delete();
        Storage::disk()->deleteDirectory('artifacts');
        DB::connection()->beginTransaction();
    }
});
