<?php

use App\Enums\PageLayout;
use App\Enums\PageShareKind;
use App\Features\Artifacts;
use App\Models\Page;
use App\Models\PageShare;
use App\Models\PageVersion;
use App\Support\PagePublisher;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('g', 32)),
        'app.url' => 'https://app.pages.test',
        'capstan.features.artifacts' => true,
        'capstan.artifacts.render_origin' => 'https://render.pages.test',
        'capstan.pages.max_content_bytes' => 262_144,
    ]);
    Feature::flushCache();
    Storage::fake();
});

function pagesMcpToken(
    User $user,
    SubjectType $subjectType = SubjectType::UserPrincipal,
    ?string $subjectRef = null,
): string {
    $token = 'capstan-pages-mcp-'.Str::random(40);
    Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => $subjectType,
        'subject_ref' => $subjectRef ?? 'capstan-user:'.$user->getKey(),
        'name' => 'Capstan pages MCP test',
        'user_id' => (string) $user->getKey(),
        'abilities' => [],
        'secret_hash' => hash('sha256', $token),
        'status' => CredentialStatus::Active,
    ]);

    return $token;
}

/** @param array<string, mixed>|object $arguments */
function pagesMcpCall(string $tool, array|object $arguments, string $token, string $path = '/mcp/write'): TestResponse
{
    return test()->postJson($path, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ], ['Authorization' => 'Bearer '.$token]);
}

/** @return array<string, mixed> */
function pagesMcpStructured(TestResponse $response): array
{
    $response->assertOk();
    $structured = $response->json('result.structuredContent');
    expect($structured)->toBeArray();

    return $structured;
}

function pagesMcpError(TestResponse $response): string
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

/** @return array<string, mixed> */
function publishPageArguments(string $key, string $title, string $recipe, string $html): array
{
    return [
        'idempotency_key' => $key,
        'title' => $title,
        'description' => 'Description '.Str::random(8),
        'recipe' => $recipe,
        'layout' => PageLayout::Plain->value,
        'content' => $html,
        'content_type' => 'text/html',
    ];
}

test('advertises the four exact page tools through their effect doors', function (): void {
    $token = pagesMcpToken(capstanUser());
    $read = collect(test()->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
    ], ['Authorization' => 'Bearer '.$token])->assertOk()->json('result.tools'))->keyBy('name');
    $write = collect(test()->postJson('/mcp/write', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
    ], ['Authorization' => 'Bearer '.$token])->assertOk()->json('result.tools'))->keyBy('name');

    expect($read->keys()->sort()->values()->all())->toBe([
        'get_brand_kit', 'get_page', 'get_task', 'get_team_work_policy', 'list_escalations', 'list_layouts', 'list_pages', 'list_work', 'machine_budgets', 'postmaster_messages', 'postmaster_spokes', 'preview_brand_draft',
    ])->and($write->keys()->sort()->values()->all())->toBe([
        'ack_postmaster_messages',
        'answer_escalation',
        'apply_brand_draft',
        'cancel_item',
        'cancel_task',
        'create_artifact_share',
        'create_task',
        'get_brand_kit',
        'get_page',
        'get_task',
        'get_team_work_policy',
        'list_escalations',
        'list_layouts',
        'list_pages',
        'list_work',
        'machine_budgets',
        'orchestrate',
        'postmaster_messages',
        'postmaster_spokes',
        'preview_brand_draft',
        'publish_page',
        'queue_item',
        'release_seat',
        'revise_brief',
        'send_postmaster_message',
        'set_team_work_policy',
        'steer',
        'take_seat',
        'update_brand_draft',
        'update_page',
        'upload_brand_asset',
    ])->and(data_get($write->get('publish_page'), '_meta.effect'))->toBe('write')
        ->and(data_get($write->get('update_page'), 'inputSchema.additionalProperties'))->toBeFalse()
        ->and(data_get($read->get('list_pages'), '_meta.effect'))->toBe('read')
        ->and(data_get($read->get('get_page'), '_meta.classification'))->toBe('content')
        ->and(data_get($write->get('publish_page'), 'inputSchema.properties.layout.default'))->toBeNull()
        ->and(data_get($read->get('list_pages'), 'inputSchema.properties.limit.default'))->toBe(10)
        ->and(data_get($read->get('get_page'), 'inputSchema.properties.version_limit.default'))->toBe(10);

    pagesMcpCall('publish_page', (object) [], $token, '/mcp')
        ->assertBadRequest()
        ->assertJsonPath('error.message', 'effect_above_ceiling');
});

test('publish and update are durable idempotent and retain immutable version metadata', function (): void {
    $owner = capstanUser();
    $token = pagesMcpToken($owner);
    $title = 'Published '.Str::random(8);
    $firstRecipe = 'Use source one '.Str::random(8);
    $secondRecipe = 'Use source two '.Str::random(8);
    $secondDescription = 'Description v2 '.Str::random(8);
    $firstHtml = '<html><body>first '.Str::random(10).'</body></html>';
    $secondHtml = '<html><body>second '.Str::random(10).'</body></html>';
    $publish = publishPageArguments('publish-once', $title, $firstRecipe, $firstHtml);

    $accepted = pagesMcpStructured(pagesMcpCall('publish_page', $publish, $token));
    $replayed = pagesMcpStructured(pagesMcpCall('publish_page', $publish, $token));
    $page = Page::query()->sole();
    $first = PageVersion::query()->sole();
    $immutable = $first->only(['id', 'version_number', 'content_hash', 'storage_key', 'refresh_recipe', 'layout']);

    expect($accepted['outcome'])->toBe('accepted')
        ->and($replayed['outcome'])->toBe('already_accepted')
        ->and($replayed['page'])->toBe($accepted['page'])
        ->and(data_get($accepted, 'page.id'))->toBe($page->id)
        ->and(data_get($accepted, 'page.url'))->toBe("https://app.pages.test/pages/{$page->id}")
        ->and($page->owner_user_id)->toBe((string) $owner->getKey())
        ->and($first->publisher_user_id)->toBe((string) $owner->getKey())
        ->and($first->publisher_source)->toBe('mcp')
        ->and($first->publisher_tool)->toBe('publish_page')
        ->and(Storage::disk()->get($first->storage_key))->toBe($firstHtml)
        ->and(Storage::disk()->visibility($first->storage_key))->toBe('private')
        ->and(Page::query()->count())->toBe(1)
        ->and(PageVersion::query()->count())->toBe(1)
        ->and(DB::table('mcp_write_claims')->count())->toBe(1)
        ->and(DB::table('mcp_write_effects')->count())->toBe(1)
        ->and(Storage::disk()->allFiles('artifacts'))->toHaveCount(1);

    $changedPublish = [...$publish, 'content' => '<html><body>changed publish</body></html>'];
    expect(pagesMcpStructured(pagesMcpCall('publish_page', $changedPublish, $token)))
        ->toBe(['outcome' => 'refused', 'reason' => 'idempotency_key_reused']);

    $update = [
        'idempotency_key' => 'update-once',
        'page_id' => $page->id,
        'title' => $title.' v2',
        'description' => $secondDescription,
        'recipe' => $secondRecipe,
        'layout' => PageLayout::Dashboard->value,
        'content' => $secondHtml,
        'content_type' => 'text/html',
    ];
    $updated = pagesMcpStructured(pagesMcpCall('update_page', $update, $token));
    $updateReplay = pagesMcpStructured(pagesMcpCall('update_page', $update, $token));
    $page->refresh();
    $second = $page->latestVersion()->firstOrFail();

    expect($updated['outcome'])->toBe('accepted')
        ->and($updateReplay['outcome'])->toBe('already_accepted')
        ->and($page->title)->toBe($title.' v2')
        ->and($page->description)->toBe($secondDescription)
        ->and($page->refresh_recipe)->toBe($secondRecipe)
        ->and($page->layout)->toBe(PageLayout::Dashboard)
        ->and($page->version_count)->toBe(2)
        ->and($second->version_number)->toBe(2)
        ->and($second->publisher_tool)->toBe('update_page')
        ->and($second->refresh_recipe)->toBe($secondRecipe)
        ->and(Page::query()->count())->toBe(1)
        ->and(PageVersion::query()->count())->toBe(2)
        ->and(DB::table('mcp_write_claims')->count())->toBe(2)
        ->and(DB::table('mcp_write_effects')->count())->toBe(2)
        ->and(Storage::disk()->allFiles('artifacts'))->toHaveCount(2)
        ->and($first->fresh()?->only(array_keys($immutable)))->toBe($immutable);

    $changedUpdate = [...$update, 'content' => '<html><body>changed update</body></html>'];
    expect(pagesMcpStructured(pagesMcpCall('update_page', $changedUpdate, $token)))
        ->toBe(['outcome' => 'refused', 'reason' => 'idempotency_key_reused'])
        ->and(PageVersion::query()->count())->toBe(2)
        ->and(DB::table('mcp_write_claims')->count())->toBe(2)
        ->and(DB::table('mcp_write_effects')->count())->toBe(2);

    $thirdHtml = '<html><body>third carry-forward '.Str::random(10).'</body></html>';
    $carryUpdate = [
        'idempotency_key' => 'update-carry-forward',
        'page_id' => $page->id,
        'content' => $thirdHtml,
        'content_type' => 'text/html',
    ];
    $carried = pagesMcpStructured(pagesMcpCall('update_page', $carryUpdate, $token));
    $carriedReplay = pagesMcpStructured(pagesMcpCall('update_page', $carryUpdate, $token));
    $page->refresh();
    $third = $page->latestVersion()->firstOrFail();

    expect($carried['outcome'])->toBe('accepted')
        ->and($carriedReplay['outcome'])->toBe('already_accepted')
        ->and($page->title)->toBe($title.' v2')
        ->and($page->description)->toBe($secondDescription)
        ->and($page->refresh_recipe)->toBe($secondRecipe)
        ->and($page->layout)->toBe(PageLayout::Dashboard)
        ->and($third->version_number)->toBe(3)
        ->and($third->refresh_recipe)->toBe($secondRecipe)
        ->and($third->layout)->toBe(PageLayout::Dashboard)
        ->and(Storage::disk()->get($third->storage_key))->toBe($thirdHtml)
        ->and(Page::query()->count())->toBe(1)
        ->and(PageVersion::query()->count())->toBe(3)
        ->and(DB::table('mcp_write_claims')->count())->toBe(3)
        ->and(DB::table('mcp_write_effects')->count())->toBe(3)
        ->and(Storage::disk()->allFiles('artifacts'))->toHaveCount(3);

    $detail = pagesMcpStructured(pagesMcpCall('get_page', ['page_id' => $page->id], $token, '/mcp'));
    expect(data_get($detail, 'page.content'))->toBe($thirdHtml)
        ->and(data_get($detail, 'page.recipe'))->toBe($secondRecipe)
        ->and(data_get($detail, 'page.version_count'))->toBe(3)
        ->and(collect(data_get($detail, 'page.versions'))->pluck('number')->all())->toBe([1, 2, 3])
        ->and(collect(data_get($detail, 'page.versions'))->every(fn (array $version): bool => ! array_key_exists('recipe', $version)))->toBeTrue();
});

test('list get and update disclose no private page data to another principal', function (): void {
    $alice = capstanUser();
    $bob = capstanUser();
    $aliceToken = pagesMcpToken($alice);
    $bobToken = pagesMcpToken($bob);
    $title = 'Alice only '.Str::random(10);
    $recipe = 'Alice recipe '.Str::random(10);
    $html = '<html><body>Alice secret '.Str::random(10).'</body></html>';
    pagesMcpCall('publish_page', publishPageArguments('alice-page', $title, $recipe, $html), $aliceToken)->assertOk();
    $page = Page::query()->sole();
    $claims = DB::table('mcp_write_claims')->count();
    $effects = DB::table('mcp_write_effects')->count();

    $aliceList = pagesMcpStructured(pagesMcpCall('list_pages', (object) [], $aliceToken, '/mcp'));
    $aliceGet = pagesMcpStructured(pagesMcpCall('get_page', ['page_id' => $page->id], $aliceToken, '/mcp'));
    expect(data_get($aliceList, 'items.0.id'))->toBe($page->id)
        ->and($aliceList['limit'])->toBe(10)
        ->and($aliceList['has_more'])->toBeFalse()
        ->and(data_get($aliceGet, 'page.content'))->toBe($html)
        ->and(data_get($aliceGet, 'page.recipe'))->toBe($recipe)
        ->and(data_get($aliceGet, 'page.version_limit'))->toBe(10);

    $bobList = pagesMcpCall('list_pages', (object) [], $bobToken, '/mcp')->assertOk();
    $bobGet = pagesMcpCall('get_page', ['page_id' => $page->id], $bobToken, '/mcp')->assertOk();
    $foreignUpdate = pagesMcpCall('update_page', [
        'idempotency_key' => 'foreign-update',
        'page_id' => $page->id,
        'content' => '<html><body>Bob overwrite</body></html>',
        'content_type' => 'text/html',
    ], $bobToken)->assertOk();

    $bobList->assertJsonCount(0, 'result.structuredContent.items');
    $bobGet->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'Page not found.');
    expect($foreignUpdate->json('result.structuredContent'))->toBe([
        'outcome' => 'refused', 'reason' => 'page_not_found',
    ]);

    foreach ([$bobList, $bobGet, $foreignUpdate] as $response) {
        foreach ([$page->id, $title, $recipe, $html] as $privateValue) {
            expect($response->getContent())->not->toContain($privateValue);
        }
    }

    expect(PageVersion::query()->count())->toBe(1)
        ->and(DB::table('mcp_write_claims')->count())->toBe($claims)
        ->and(DB::table('mcp_write_effects')->count())->toBe($effects)
        ->and(Storage::disk()->allFiles('artifacts'))->toHaveCount(1);
});

test('internal grants paginate through list and allow get but never update then disappear on revoke', function (): void {
    $alice = capstanUser();
    $bob = capstanUser();
    $aliceToken = pagesMcpToken($alice);
    $bobToken = pagesMcpToken($bob);
    $publisher = resolve(PagePublisher::class);
    $owned = $publisher->publish(
        pageId: (string) Str::uuid(),
        ownerUserId: (string) $alice->getKey(),
        title: 'Alice owned '.Str::random(8),
        description: null,
        refreshRecipe: 'Alice owned recipe',
        layout: PageLayout::Plain,
        content: '<html><body>Alice owned content</body></html>',
        contentType: 'text/html',
    );
    $sharedPages = collect(range(1, 2))->map(fn (int $number): Page => $publisher->publish(
        pageId: (string) Str::uuid(),
        ownerUserId: (string) $bob->getKey(),
        title: 'Bob shared '.$number.' '.Str::random(8),
        description: 'Shared description '.$number,
        refreshRecipe: 'Shared recipe '.$number,
        layout: PageLayout::Plain,
        content: '<html><body>Shared content '.$number.'</body></html>',
        contentType: 'text/html',
    ));
    $shares = $sharedPages->map(fn (Page $page): PageShare => PageShare::query()->create([
        'page_id' => $page->id,
        'created_by_user_id' => (string) $bob->getKey(),
        'kind' => PageShareKind::Internal,
        'internal_recipient_user_id' => (string) $alice->getKey(),
    ]));
    $seen = [];
    $cursor = null;

    do {
        $arguments = ['limit' => 1];
        if ($cursor !== null) {
            $arguments['cursor'] = $cursor;
        }
        $result = pagesMcpStructured(pagesMcpCall('list_pages', $arguments, $aliceToken, '/mcp'));
        $seen = [...$seen, ...collect($result['items'])->pluck('id')->all()];
        $cursor = $result['next_cursor'];
    } while ($cursor !== null);

    $expected = [$owned->id, ...$sharedPages->pluck('id')->all()];
    sort($expected);
    expect($seen)->toBe($expected)->and(array_unique($seen))->toHaveCount(3);

    $shared = $sharedPages->firstOrFail();
    $detail = pagesMcpStructured(pagesMcpCall('get_page', ['page_id' => $shared->id], $aliceToken, '/mcp'));
    expect(data_get($detail, 'page.recipe'))->toBe('Shared recipe 1')
        ->and(data_get($detail, 'page.content'))->toBe('<html><body>Shared content 1</body></html>');

    $versionCount = PageVersion::query()->count();
    expect(pagesMcpStructured(pagesMcpCall('update_page', [
        'idempotency_key' => 'internal-share-cannot-update',
        'page_id' => $shared->id,
        'content' => '<html><body>forbidden update</body></html>',
        'content_type' => 'text/html',
    ], $aliceToken)))->toBe(['outcome' => 'refused', 'reason' => 'page_not_found'])
        ->and(PageVersion::query()->count())->toBe($versionCount);

    $shares->firstOrFail()->forceFill(['revoked_at' => now()])->save();
    $afterList = pagesMcpStructured(pagesMcpCall('list_pages', ['limit' => 25], $aliceToken, '/mcp'));
    $afterGet = pagesMcpCall('get_page', ['page_id' => $shared->id], $aliceToken, '/mcp')->assertOk();
    expect(collect($afterList['items'])->pluck('id')->all())->not->toContain($shared->id);
    $afterGet->assertJsonPath('result.isError', true)->assertJsonPath('result.content.0.text', 'Page not found.');

    expect(data_get(pagesMcpStructured(pagesMcpCall('get_page', ['page_id' => $sharedPages->last()->id], $aliceToken, '/mcp')), 'page.id'))
        ->toBe($sharedPages->last()->id);
    expect(data_get(pagesMcpStructured(pagesMcpCall('get_page', ['page_id' => $shared->id], $bobToken, '/mcp')), 'page.id'))
        ->toBe($shared->id);
});

test('list pages paginates every owned compact item with actor and query bound cursors', function (): void {
    $alice = capstanUser();
    $bob = capstanUser();
    $aliceToken = pagesMcpToken($alice);
    $bobToken = pagesMcpToken($bob);
    $publisher = resolve(PagePublisher::class);
    $ownedIds = [];

    foreach (range(1, 7) as $index) {
        $page = $publisher->publish(
            pageId: (string) Str::uuid(),
            ownerUserId: (string) $alice->getKey(),
            title: 'Owned list page '.$index,
            description: str_repeat('description-'.$index, 100),
            refreshRecipe: str_repeat('recipe-'.$index, 200),
            layout: PageLayout::Plain,
            content: '<html><body>Owned '.$index.'</body></html>',
            contentType: 'text/html',
        );
        $ownedIds[] = $page->id;
    }

    $foreign = $publisher->publish(
        pageId: (string) Str::uuid(),
        ownerUserId: (string) $bob->getKey(),
        title: 'Foreign list page',
        description: 'Foreign description',
        refreshRecipe: 'Foreign recipe',
        layout: PageLayout::Plain,
        content: '<html><body>Foreign list content</body></html>',
        contentType: 'text/html',
    );
    $seen = [];
    $cursor = null;
    $firstCursor = null;
    $pageQueries = [];
    DB::listen(function ($query) use (&$pageQueries): void {
        if (str_contains($query->sql, 'from "pages"')) {
            $pageQueries[] = $query->sql;
        }
    });

    do {
        $arguments = ['limit' => 2];

        if ($cursor !== null) {
            $arguments['cursor'] = $cursor;
        }

        $result = pagesMcpStructured(pagesMcpCall('list_pages', $arguments, $aliceToken, '/mcp'));
        $items = $result['items'];
        expect($items)->toHaveCount($result['has_more'] ? 2 : 1);

        foreach ($items as $item) {
            expect(array_keys($item))->toBe(['id', 'url', 'title', 'layout', 'version_count', 'created_at', 'updated_at']);
            $seen[] = $item['id'];
        }

        $cursor = $result['next_cursor'];
        $firstCursor ??= $cursor;
    } while ($cursor !== null);

    sort($ownedIds);
    expect($seen)->toBe($ownedIds)
        ->and(array_unique($seen))->toHaveCount(7)
        ->and($seen)->not->toContain($foreign->id)
        ->and($pageQueries)->not->toBeEmpty()
        ->and(collect($pageQueries)->every(fn (string $sql): bool => str_contains($sql, 'limit 3')))->toBeTrue()
        ->and($firstCursor)->toBeString();

    expect(pagesMcpError(pagesMcpCall('list_pages', ['limit' => 2, 'cursor' => $firstCursor.'x'], $aliceToken, '/mcp')))
        ->toBe('The cursor is invalid for this request.')
        ->and(pagesMcpError(pagesMcpCall('list_pages', ['limit' => 3, 'cursor' => $firstCursor], $aliceToken, '/mcp')))
        ->toBe('The cursor is invalid for this request.')
        ->and(pagesMcpError(pagesMcpCall('list_pages', ['limit' => 2, 'cursor' => $firstCursor], $bobToken, '/mcp')))
        ->toBe('The cursor is invalid for this request.');
});

test('get page paginates complete compact history while repeating current rebuild data', function (): void {
    $alice = capstanUser();
    $bob = capstanUser();
    $aliceToken = pagesMcpToken($alice);
    $bobToken = pagesMcpToken($bob);
    $publisher = resolve(PagePublisher::class);
    $recipe = str_repeat('history recipe '.Str::random(4), 2_000);
    $page = $publisher->publish(
        pageId: (string) Str::uuid(),
        ownerUserId: (string) $alice->getKey(),
        title: 'History page '.Str::random(8),
        description: 'History description',
        refreshRecipe: $recipe,
        layout: PageLayout::Report,
        content: '<html><body>history 1</body></html>',
        contentType: 'text/html',
    );

    foreach (range(2, 12) as $number) {
        $page = $publisher->update(
            pageId: $page->id,
            ownerUserId: (string) $alice->getKey(),
            versionId: (string) Str::uuid(),
            replacements: [],
            content: '<html><body>history '.$number.'</body></html>',
            contentType: 'text/html',
        );
    }

    $otherPage = $publisher->publish(
        pageId: (string) Str::uuid(),
        ownerUserId: (string) $alice->getKey(),
        title: 'Other Alice page',
        description: null,
        refreshRecipe: 'Other recipe',
        layout: PageLayout::Plain,
        content: '<html><body>other</body></html>',
        contentType: 'text/html',
    );
    $bobPage = $publisher->publish(
        pageId: (string) Str::uuid(),
        ownerUserId: (string) $bob->getKey(),
        title: 'Bob cursor page',
        description: null,
        refreshRecipe: 'Bob recipe',
        layout: PageLayout::Plain,
        content: '<html><body>bob</body></html>',
        contentType: 'text/html',
    );
    $seen = [];
    $cursor = null;
    $firstCursor = null;

    do {
        $arguments = ['page_id' => $page->id, 'version_limit' => 3];

        if ($cursor !== null) {
            $arguments['version_cursor'] = $cursor;
        }

        $result = pagesMcpStructured(pagesMcpCall('get_page', $arguments, $aliceToken, '/mcp'))['page'];
        expect($result['content'])->toBe('<html><body>history 12</body></html>')
            ->and($result['recipe'])->toBe($recipe)
            ->and($result['version_count'])->toBe(12)
            ->and($result['version_limit'])->toBe(3);

        foreach ($result['versions'] as $version) {
            expect($version)->not->toHaveKey('recipe');
            $seen[] = $version['number'];
        }

        $cursor = $result['next_version_cursor'];
        $firstCursor ??= $cursor;
    } while ($cursor !== null);

    expect($seen)->toBe(range(1, 12))
        ->and(array_unique($seen))->toHaveCount(12)
        ->and($firstCursor)->toBeString();

    expect(pagesMcpError(pagesMcpCall('get_page', [
        'page_id' => $page->id,
        'version_limit' => 3,
        'version_cursor' => $firstCursor.'x',
    ], $aliceToken, '/mcp')))->toBe('The cursor is invalid for this request.')
        ->and(pagesMcpError(pagesMcpCall('get_page', [
            'page_id' => $page->id,
            'version_limit' => 4,
            'version_cursor' => $firstCursor,
        ], $aliceToken, '/mcp')))->toBe('The cursor is invalid for this request.')
        ->and(pagesMcpError(pagesMcpCall('get_page', [
            'page_id' => $otherPage->id,
            'version_limit' => 3,
            'version_cursor' => $firstCursor,
        ], $aliceToken, '/mcp')))->toBe('The cursor is invalid for this request.')
        ->and(pagesMcpError(pagesMcpCall('get_page', [
            'page_id' => $bobPage->id,
            'version_limit' => 3,
            'version_cursor' => $firstCursor,
        ], $bobToken, '/mcp')))->toBe('The cursor is invalid for this request.');
});

test('page writes reject real response overflow before durable state and accept a retrievable large boundary', function (): void {
    $owner = capstanUser();
    $token = pagesMcpToken($owner);
    $overflow = publishPageArguments(
        'overflow-publish',
        'Overflow title '.Str::random(8),
        str_repeat("\x01", 60_000),
        '<html><body>'.str_repeat('"\\', 1_000).'</body></html>',
    );
    $overflow['description'] = str_repeat("\x01", 60_000);
    pagesMcpCall('publish_page', $overflow, $token)
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'The page exceeds the MCP response budget.');

    $multibyte = publishPageArguments('multibyte-metadata', 'Multibyte', str_repeat('🙂', 20_000), 'test');
    pagesMcpCall('publish_page', $multibyte, $token)
        ->assertOk()
        ->assertJsonPath('result.isError', true);

    expect(Page::query()->count())->toBe(0)
        ->and(PageVersion::query()->count())->toBe(0)
        ->and(DB::table('mcp_write_claims')->count())->toBe(0)
        ->and(DB::table('mcp_write_effects')->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);

    $largeHtml = '<html><body>'.str_repeat('a', 250_000).'</body></html>';
    $accepted = pagesMcpStructured(pagesMcpCall('publish_page', publishPageArguments(
        'large-boundary',
        'Large retrievable page',
        'Large page recipe',
        $largeHtml,
    ), $token));
    $page = Page::query()->sole();
    expect($accepted['outcome'])->toBe('accepted');

    foreach (range(2, 24) as $versionNumber) {
        $page = resolve(PagePublisher::class)->update(
            pageId: $page->id,
            ownerUserId: (string) $owner->getKey(),
            versionId: (string) Str::uuid(),
            replacements: [],
            content: '<html><body>boundary filler '.$versionNumber.'</body></html>',
            contentType: 'text/html',
        );
    }

    $boundaryUpdate = pagesMcpStructured(pagesMcpCall('update_page', [
        'idempotency_key' => 'large-boundary-v25',
        'page_id' => $page->id,
        'content' => $largeHtml,
        'content_type' => 'text/html',
    ], $token));
    $boundaryDetail = pagesMcpStructured(pagesMcpCall('get_page', [
        'page_id' => $page->id,
        'version_limit' => 25,
    ], $token, '/mcp'));
    expect($boundaryUpdate['outcome'])->toBe('accepted')
        ->and(data_get($boundaryDetail, 'page.content'))->toBe($largeHtml)
        ->and(data_get($boundaryDetail, 'page.version_count'))->toBe(25)
        ->and(data_get($boundaryDetail, 'page.versions'))->toHaveCount(25)
        ->and(data_get($boundaryDetail, 'page.has_more_versions'))->toBeFalse();

    $counts = [
        PageVersion::query()->count(),
        DB::table('mcp_write_claims')->count(),
        DB::table('mcp_write_effects')->count(),
        count(Storage::disk()->allFiles('artifacts')),
    ];
    pagesMcpCall('update_page', [
        'idempotency_key' => 'overflow-update',
        'page_id' => $page->id,
        'description' => str_repeat("\x01", 60_000),
        'recipe' => str_repeat("\x01", 60_000),
        'content' => '<html><body>'.str_repeat('"\\', 1_000).'</body></html>',
        'content_type' => 'text/html',
    ], $token)
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'The page exceeds the MCP response budget.');

    expect([
        PageVersion::query()->count(),
        DB::table('mcp_write_claims')->count(),
        DB::table('mcp_write_effects')->count(),
        count(Storage::disk()->allFiles('artifacts')),
    ])->toBe($counts);
});

test('invalid page writes and unavailable feature configurations leave no durable or blob state', function (): void {
    config(['capstan.pages.max_content_bytes' => 4]);
    $token = pagesMcpToken(capstanUser());
    $valid = publishPageArguments('invalid', 'Title', 'Recipe', 'test');

    foreach ([
        (object) [],
        [...$valid, 'unexpected' => true],
        [...$valid, 'title' => ''],
        [...$valid, 'recipe' => ''],
        [...$valid, 'content' => ''],
        [...$valid, 'content' => 'ééa'],
        [...$valid, 'layout' => 'poster'],
        [...$valid, 'content_type' => 'text/plain'],
    ] as $arguments) {
        $response = pagesMcpCall('publish_page', $arguments, $token);
        expect($response->status())->toBeIn([200, 400]);
    }

    expect(Page::query()->count())->toBe(0)
        ->and(PageVersion::query()->count())->toBe(0)
        ->and(DB::table('mcp_write_claims')->count())->toBe(0)
        ->and(DB::table('mcp_write_effects')->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);

    $unsupported = pagesMcpToken(
        capstanUser(),
        SubjectType::ExternalConsumer,
        'external-consumer:test-pages',
    );
    config(['app.debug' => false]);
    pagesMcpCall('publish_page', publishPageArguments('unsupported', 'Title', 'Recipe', 'test'), $unsupported)
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'This MCP principal is not supported.');

    expect(DB::table('mcp_write_claims')->count())->toBe(0)
        ->and(DB::table('mcp_write_effects')->count())->toBe(0)
        ->and(Page::query()->count())->toBe(0)
        ->and(PageVersion::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);

    foreach ([null, 'https://app.pages.test'] as $index => $renderOrigin) {
        config(['capstan.artifacts.render_origin' => $renderOrigin]);
        Feature::flushCache();
        expect(Feature::active(Artifacts::class))->toBeFalse();
        pagesMcpCall('publish_page', publishPageArguments('closed-'.$index, 'Closed', 'Recipe', 'test'), $token)
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'Pages are unavailable.');
    }

    expect(Page::query()->count())->toBe(0)
        ->and(PageVersion::query()->count())->toBe(0)
        ->and(DB::table('mcp_write_claims')->count())->toBe(0)
        ->and(DB::table('mcp_write_effects')->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);
});
