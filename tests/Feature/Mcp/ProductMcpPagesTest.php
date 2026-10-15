<?php

use App\Enums\PageLayout;
use App\Features\Artifacts;
use App\Models\Page;
use App\Models\PageVersion;
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
        'get_page', 'list_pages', 'postmaster_messages', 'postmaster_spokes',
    ])->and($write->keys()->sort()->values()->all())->toBe([
        'ack_postmaster_messages',
        'create_artifact_share',
        'get_page',
        'list_pages',
        'postmaster_messages',
        'postmaster_spokes',
        'publish_page',
        'send_postmaster_message',
        'update_page',
    ])->and(data_get($write->get('publish_page'), '_meta.effect'))->toBe('write')
        ->and(data_get($write->get('update_page'), 'inputSchema.additionalProperties'))->toBeFalse()
        ->and(data_get($read->get('list_pages'), '_meta.effect'))->toBe('read')
        ->and(data_get($read->get('get_page'), '_meta.classification'))->toBe('content');

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
        'description' => '',
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
        ->and($page->description)->toBe('')
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

    $detail = pagesMcpStructured(pagesMcpCall('get_page', ['page_id' => $page->id], $token, '/mcp'));
    expect(data_get($detail, 'page.content'))->toBe($secondHtml)
        ->and(collect(data_get($detail, 'page.versions'))->pluck('number')->all())->toBe([1, 2])
        ->and(collect(data_get($detail, 'page.versions'))->pluck('recipe')->all())->toBe([$firstRecipe, $secondRecipe]);
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
        ->and(data_get($aliceGet, 'page.content'))->toBe($html)
        ->and(data_get($aliceGet, 'page.recipe'))->toBe($recipe)
        ->and(data_get($aliceGet, 'page.versions.0.recipe'))->toBe($recipe);

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
        expect($response->getContent())->not->toContain($page->id, $title, $recipe, $html);
    }

    expect(PageVersion::query()->count())->toBe(1)
        ->and(DB::table('mcp_write_claims')->count())->toBe($claims)
        ->and(DB::table('mcp_write_effects')->count())->toBe($effects)
        ->and(Storage::disk()->allFiles('artifacts'))->toHaveCount(1);
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
    pagesMcpCall('publish_page', publishPageArguments('unsupported', 'Title', 'Recipe', 'test'), $unsupported)
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'This MCP principal is not supported.');

    expect(DB::table('mcp_write_claims')->count())->toBe(0)
        ->and(DB::table('mcp_write_effects')->count())->toBe(0)
        ->and(Page::query()->count())->toBe(0)
        ->and(PageVersion::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);

    foreach ([false, true, true] as $index => $flag) {
        config([
            'capstan.features.artifacts' => $flag,
            'capstan.artifacts.render_origin' => match ($index) {
                0 => 'https://render.pages.test',
                1 => null,
                default => 'https://app.pages.test',
            },
        ]);
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
