<?php

use App\Enums\PageLayout;
use App\Mcp\CapstanServer;
use App\Mcp\ToolResponse;
use App\Models\BrandAsset;
use App\Models\BrandKit;
use App\Models\BrandVersion;
use App\Support\ArtifactRenderOrigin;
use App\Support\BrandKitSchema;
use App\Support\BrandKitService;
use App\Support\PagePublisher;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\Hmac\SigningRootLifecycle;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('g', 32)),
        'app.url' => 'https://app.brand-mcp.test',
        'capstan.artifacts.render_origin' => 'https://render.brand-mcp.test',
    ]);
    Feature::flushCache();
    Storage::fake();
    resolve(SigningRootLifecycle::class)->provision();
});

function brandMcpToken(User $user): string
{
    $token = 'brand-mcp-'.Str::random(40);
    Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => 'capstan-user:'.$user->getKey(),
        'name' => 'Brand MCP test',
        'user_id' => (string) $user->getKey(),
        'abilities' => [],
        'secret_hash' => hash('sha256', $token),
        'status' => CredentialStatus::Active,
    ]);

    return $token;
}

/** @param array<string, mixed>|object $arguments */
function brandMcpCall(string $tool, array|object $arguments, string $token, string $path = '/mcp/write'): TestResponse
{
    return test()->postJson('https://app.brand-mcp.test'.$path, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ], ['Authorization' => 'Bearer '.$token]);
}

/** @return array<string, mixed> */
function brandMcpStructured(TestResponse $response): array
{
    $response->assertOk();
    $content = $response->json('result.structuredContent');
    if (! is_array($content)) {
        throw new RuntimeException($response->getContent());
    }

    return $content;
}

function brandMcpPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
}

test('advertises the complete closed brand tool contract on the correct effect doors', function (): void {
    $token = brandMcpToken(capstanUser(['role' => UserRole::Owner->value]));
    $payload = fn (string $path) => collect($this->postJson($path, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => [],
    ], ['Authorization' => 'Bearer '.$token])->assertOk()->json('result.tools'))->keyBy('name');
    $read = $payload('/mcp');
    $write = $payload('/mcp/write');

    expect($read->keys()->all())->toContain('get_brand_kit', 'list_layouts', 'preview_brand_draft')
        ->and($read->keys()->all())->not->toContain('update_brand_draft', 'upload_brand_asset', 'apply_brand_draft')
        ->and($write->keys()->all())->toContain(
            'get_brand_kit', 'list_layouts', 'preview_brand_draft',
            'update_brand_draft', 'upload_brand_asset', 'apply_brand_draft',
        );

    foreach (['get_brand_kit' => 'content', 'list_layouts' => 'metadata', 'preview_brand_draft' => 'content'] as $name => $classification) {
        expect(data_get($read->get($name), '_meta.effect'))->toBe('read')
            ->and(data_get($read->get($name), '_meta.classification'))->toBe($classification)
            ->and(data_get($read->get($name), 'annotations.readOnlyHint'))->toBeTrue()
            ->and(data_get($read->get($name), 'inputSchema.additionalProperties'))->toBeFalse();
    }
    foreach (['update_brand_draft', 'upload_brand_asset', 'apply_brand_draft'] as $name) {
        expect(data_get($write->get($name), '_meta.effect'))->toBe('write')
            ->and(data_get($write->get($name), 'annotations.readOnlyHint'))->toBeFalse()
            ->and(data_get($write->get($name), 'annotations.idempotentHint'))->toBeTrue()
            ->and(data_get($write->get($name), 'inputSchema.additionalProperties'))->toBeFalse();
    }
    expect(data_get($write->get('update_brand_draft'), 'inputSchema.properties.changes.additionalProperties'))->toBeFalse()
        ->and(data_get($write->get('update_brand_draft'), 'inputSchema.properties.changes.properties.colours.additionalProperties'))->toBeFalse()
        ->and(data_get($write->get('upload_brand_asset'), 'inputSchema.properties.bytes_base64.maxLength'))->toBeInt()
        ->and(data_get($write->get('apply_brand_draft'), 'inputSchema.required'))->toContain('idempotency_key', 'draft_fingerprint')
        ->and((new ReflectionClass(CapstanServer::class))->getAttributes(Instructions::class)[0]->newInstance()->value)
        ->toContain('Capstan never fetches arbitrary URLs', 'only after the user explicitly approves', 'Font uploads are not supported');
});

test('reads a complete brand authoring contract without exposing stored bytes or storage keys', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $token = brandMcpToken($owner);
    $service = resolve(BrandKitService::class);
    $asset = $service->uploadAsset($owner, 'logo', brandMcpPng());
    $service->selectAsset($owner, 'logo_light', $asset->id);
    $service->applyDraft($owner);
    $service->updateDraft($owner, ['display_name' => 'Draft '.Str::random(8)]);

    $response = brandMcpCall('get_brand_kit', (object) [], $token, '/mcp');
    $kit = brandMcpStructured($response);
    expect($kit)->toHaveKeys(['defaults', 'current', 'draft', 'current_version', 'versions', 'next_cursor', 'has_more', 'limit', 'contract'])
        ->and($kit['contract']['colour_tokens'])->toBe(BrandKitSchema::COLOUR_TOKENS)
        ->and($kit['contract']['font_stacks'])->toBe(['system-sans', 'system-serif', 'monospace'])
        ->and(collect($kit['contract']['layouts'])->pluck('name')->all())->toBe(PageLayout::values())
        ->and($kit['contract']['component_classes'])->toContain('capstan-card', 'capstan-stat', 'capstan-table', 'capstan-badge', 'capstan-callout', 'capstan-chart-series-5')
        ->and($kit['contract']['assets']['fonts_supported'])->toBeFalse()
        ->and($response->getContent())->not->toContain($asset->storage_key, base64_encode(brandMcpPng()), 'bytes_base64');
});

test('brand writes are idempotent and refuse changed intent without duplicate draft asset or version effects', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $token = brandMcpToken($owner);
    $update = ['idempotency_key' => 'brand-draft-once', 'changes' => [
        'display_name' => 'MCP draft '.Str::random(8),
        'fonts' => ['heading' => ['type' => 'stack', 'value' => 'system-serif']],
    ]];

    $accepted = brandMcpStructured(brandMcpCall('update_brand_draft', $update, $token));
    $replayed = brandMcpStructured(brandMcpCall('update_brand_draft', $update, $token));
    $changed = $update;
    $changed['changes']['display_name'] = 'Changed intent';
    expect($accepted['outcome'])->toBe('accepted')
        ->and($replayed['outcome'])->toBe('already_accepted')
        ->and(brandMcpStructured(brandMcpCall('update_brand_draft', $changed, $token)))
        ->toBe(['outcome' => 'refused', 'reason' => 'idempotency_key_reused'])
        ->and(BrandKit::query()->count())->toBe(1);

    $upload = [
        'idempotency_key' => 'brand-asset-once',
        'kind' => 'logo',
        'bytes_base64' => base64_encode(brandMcpPng()),
    ];
    $uploaded = brandMcpStructured(brandMcpCall('upload_brand_asset', $upload, $token));
    $uploadReplay = brandMcpStructured(brandMcpCall('upload_brand_asset', $upload, $token));
    expect($uploaded['outcome'])->toBe('accepted')
        ->and($uploadReplay['outcome'])->toBe('already_accepted')
        ->and($uploadReplay['asset'])->toBe($uploaded['asset'])
        ->and(BrandAsset::query()->count())->toBe(1)
        ->and(DB::table('mcp_write_claims')->where('tool', 'upload_brand_asset')->value('target_id'))->toBe($uploaded['asset']['id'])
        ->and(Storage::disk()->allFiles())->toHaveCount(1);

    brandMcpStructured(brandMcpCall('update_brand_draft', [
        'idempotency_key' => 'select-upload',
        'changes' => ['assets' => ['logo_light' => $uploaded['asset']['id']]],
    ], $token));
    $apply = [
        'idempotency_key' => 'apply-once',
        'draft_fingerprint' => resolve(BrandKitService::class)->draftFingerprint(),
    ];
    $applied = brandMcpStructured(brandMcpCall('apply_brand_draft', $apply, $token));
    $applyReplay = brandMcpStructured(brandMcpCall('apply_brand_draft', $apply, $token));
    expect($applied['outcome'])->toBe('accepted')
        ->and($applyReplay['outcome'])->toBe('already_accepted')
        ->and($applyReplay['version'])->toBe($applied['version'])
        ->and(BrandVersion::query()->count())->toBe(1);

    brandMcpStructured(brandMcpCall('update_brand_draft', [
        'idempotency_key' => 'different-draft',
        'changes' => ['display_name' => 'Different approved intent '.Str::random(8)],
    ], $token));
    $differentFingerprint = resolve(BrandKitService::class)->draftFingerprint();
    expect($differentFingerprint)->not->toBe($apply['draft_fingerprint'])
        ->and(brandMcpStructured(brandMcpCall('apply_brand_draft', [
            'idempotency_key' => $apply['idempotency_key'],
            'draft_fingerprint' => $differentFingerprint,
        ], $token)))->toBe(['outcome' => 'refused', 'reason' => 'idempotency_key_reused'])
        ->and(BrandVersion::query()->count())->toBe(1)
        ->and(resolve(BrandKitService::class)->draftFingerprint())->toBe($differentFingerprint);
});

test('fresh apply keys refuse stale approved fingerprints without changing brand state', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $token = brandMcpToken($owner);
    $service = resolve(BrandKitService::class);
    $service->updateDraft($owner, ['display_name' => 'Approved draft '.Str::random(8)]);
    $staleFingerprint = $service->draftFingerprint();
    $service->updateDraft($owner, ['display_name' => 'Changed draft '.Str::random(8)]);
    $before = BrandKit::query()->sole()->only(['current_version_id', 'current_version_number', 'draft']);

    $outcome = brandMcpStructured(brandMcpCall('apply_brand_draft', [
        'idempotency_key' => 'fresh-stale-fingerprint',
        'draft_fingerprint' => $staleFingerprint,
    ], $token));

    expect($outcome)->toBe(['outcome' => 'refused', 'reason' => 'brand_draft_changed'])
        ->and(BrandVersion::query()->count())->toBe(0)
        ->and(BrandKit::query()->sole()->only(['current_version_id', 'current_version_number', 'draft']))->toBe($before);
});

test('brand preview is signed isolated stale on mutation and shows every layout while ordinary pages use only applied state', function (): void {
    Date::setTestNow('2026-10-16 12:00:00');
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $token = brandMcpToken($owner);
    $page = resolve(PagePublisher::class)->publish(
        pageId: (string) Str::uuid(),
        ownerUserId: (string) $owner->getKey(),
        title: 'Preview isolation '.Str::random(8),
        description: null,
        refreshRecipe: 'Test recipe',
        layout: PageLayout::Report,
        content: '<html><head></head><body>existing page</body></html>',
        contentType: 'text/html',
    );
    $pageUrl = resolve(ArtifactRenderOrigin::class)->signedPageContentUrl($page, $page->latestVersion);
    $draftColour = '#123abc';
    brandMcpStructured(brandMcpCall('update_brand_draft', [
        'idempotency_key' => 'preview-draft',
        'changes' => ['colours' => ['light' => ['primary' => $draftColour]]],
    ], $token));

    $ordinaryPage = $this->get($pageUrl);
    expect($ordinaryPage->getStatusCode())->toBe(200, 'ordinary page status');
    expect($ordinaryPage->streamedContent())->not->toContain($draftColour);
    $preview = brandMcpStructured(brandMcpCall('preview_brand_draft', (object) [], $token, '/mcp'));
    $url = $preview['preview_url'];
    $previewRequest = Request::create($url);
    $fingerprint = basename((string) parse_url($url, PHP_URL_PATH));
    expect(parse_url($url, PHP_URL_HOST))->toBe('render.brand-mcp.test')
        ->and($preview['draft_fingerprint'])->toBe($fingerprint)
        ->and(URL::hasValidSignature($previewRequest, false))->toBeTrue()
        ->and(resolve(BrandKitService::class)->previewConfiguration($fingerprint))->not->toBeNull();
    $rendered = $this->get($url);
    expect($rendered->getStatusCode())->toBe(200, 'preview status');
    $rendered->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeaderMissing('Set-Cookie');
    expect($rendered->content())->toContain(
        'data-testid="brand-preview-layout-plain"',
        'data-testid="brand-preview-layout-report"',
        'data-testid="brand-preview-layout-dashboard"',
        'data-testid="brand-preview-layout-brief"',
        '--capstan-color-primary:'.$draftColour,
        'capstan-card',
    )->not->toContain('existing page')
        ->and($rendered->headers->get('Content-Security-Policy'))->toContain("font-src 'none'", "connect-src 'none'", "form-action 'none'");

    $this->get($url.'&tampered=1')->assertNotFound();
    brandMcpStructured(brandMcpCall('update_brand_draft', [
        'idempotency_key' => 'preview-mutation',
        'changes' => ['tone_notes' => 'mutated '.Str::random(8)],
    ], $token));
    $this->get($url)->assertNotFound();

    $freshUrl = brandMcpStructured(brandMcpCall('preview_brand_draft', (object) [], $token, '/mcp'))['preview_url'];
    Date::setTestNow(now()->addMinutes(6));
    $this->get($freshUrl)->assertNotFound();
});

test('failed MCP asset persistence leaves no brand row or private file', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $token = brandMcpToken($owner);
    BrandAsset::creating(fn () => throw new RuntimeException('Test-created asset row failure'));

    $outcome = brandMcpStructured(brandMcpCall('upload_brand_asset', [
        'idempotency_key' => 'failed-asset',
        'kind' => 'logo',
        'bytes_base64' => base64_encode(brandMcpPng()),
    ], $token));

    expect($outcome)->toBe(['outcome' => 'outcome_unknown'])
        ->and(BrandAsset::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([])
        ->and(DB::table('mcp_write_effects')->count())->toBe(0);
});

test('members inactive and offboarded managers are refused before brand claims rows or files', function (): void {
    $actors = [
        capstanUser(['role' => UserRole::Member->value]),
        capstanUser(['role' => UserRole::Admin->value, 'status' => 'inactive']),
    ];
    $offboarded = capstanUser(['role' => UserRole::Owner->value]);
    OffboardedSubject::query()->create([
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => 'capstan-user:'.$offboarded->getKey(),
        'user_id' => (string) $offboarded->getKey(),
        'offboarded_at' => now(),
    ]);
    $actors[] = $offboarded;

    foreach ($actors as $actor) {
        $token = brandMcpToken($actor);
        foreach ([
            ['update_brand_draft', ['idempotency_key' => 'deny-update-'.$actor->id, 'changes' => ['display_name' => 'Denied']]],
            ['upload_brand_asset', ['idempotency_key' => 'deny-upload-'.$actor->id, 'kind' => 'logo', 'bytes_base64' => base64_encode(brandMcpPng())]],
            ['apply_brand_draft', ['idempotency_key' => 'deny-apply-'.$actor->id, 'draft_fingerprint' => str_repeat('a', 64)]],
            ['preview_brand_draft', (object) []],
        ] as [$tool, $arguments]) {
            $response = brandMcpCall($tool, $arguments, $token);
            expect($response->getStatusCode())->toBeIn([200, 401]);
            if ($response->getStatusCode() === 200) {
                $response->assertJsonPath('result.isError', true);
            }
        }
    }

    expect(DB::table('mcp_write_claims')->count())->toBe(0)
        ->and(BrandKit::query()->count())->toBe(0)
        ->and(BrandVersion::query()->count())->toBe(0)
        ->and(BrandAsset::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);
});

test('feature off and malformed brand calls fail without mutation or disclosure', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $token = brandMcpToken($owner);

    foreach ([
        ['update_brand_draft', ['idempotency_key' => 'unknown', 'changes' => ['display_name' => 'No'], 'unknown' => true]],
        ['update_brand_draft', ['idempotency_key' => 'nested', 'changes' => ['colours' => ['light' => ['unknown' => '#fff']]]]],
        ['upload_brand_asset', ['idempotency_key' => 'font', 'kind' => 'font', 'bytes_base64' => base64_encode(brandMcpPng())]],
        ['upload_brand_asset', ['idempotency_key' => 'bad-base64', 'kind' => 'logo', 'bytes_base64' => str_repeat('A', 17)]],
    ] as [$tool, $arguments]) {
        $response = brandMcpCall($tool, $arguments, $token);
        expect($response->status())->toBeIn([200, 400]);
    }
    expect(DB::table('mcp_write_claims')->count())->toBe(0)
        ->and(BrandKit::query()->count())->toBe(0)
        ->and(BrandAsset::query()->count())->toBe(0);

    config(['capstan.artifacts.render_origin' => null]);
    Feature::flushCache();
    foreach (['get_brand_kit', 'list_layouts', 'preview_brand_draft'] as $tool) {
        brandMcpCall($tool, (object) [], $token, '/mcp')->assertOk()->assertJsonPath('result.isError', true);
    }
    foreach ([
        ['update_brand_draft', ['idempotency_key' => 'off-update', 'changes' => ['display_name' => 'Off']]],
        ['upload_brand_asset', ['idempotency_key' => 'off-upload', 'kind' => 'logo', 'bytes_base64' => base64_encode(brandMcpPng())]],
        ['apply_brand_draft', ['idempotency_key' => 'off-apply', 'draft_fingerprint' => str_repeat('a', 64)]],
    ] as [$tool, $arguments]) {
        brandMcpCall($tool, $arguments, $token)->assertOk()->assertJsonPath('result.isError', true);
    }
    expect(DB::table('mcp_write_claims')->count())->toBe(0)
        ->and(BrandKit::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);
});

test('brand history pages stay bounded and signed continuation reaches every older version exactly once', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $token = brandMcpToken($owner);
    $configuration = resolve(BrandKitSchema::class)->defaults();
    BrandKit::query()->create([
        'installation_key' => 'installation',
        'current_version_number' => 0,
    ]);
    $rows = [];
    $currentId = null;
    for ($version = 1; $version <= 800; $version++) {
        $currentId = (string) Str::uuid();
        $rows[] = [
            'id' => $currentId,
            'brand_kit_key' => 'installation',
            'version_number' => $version,
            'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR),
            'applied_by_user_id' => (string) $owner->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (count($rows) === 100) {
            DB::table('brand_versions')->insert($rows);
            $rows = [];
        }
    }
    BrandKit::query()->whereKey('installation')->update([
        'current_version_id' => $currentId,
        'current_version_number' => 800,
    ]);

    $numbers = [];
    $cursor = null;
    $firstCursor = null;
    do {
        $arguments = ['limit' => 25];
        if ($cursor !== null) {
            $arguments['cursor'] = $cursor;
        }
        $page = brandMcpStructured(brandMcpCall('get_brand_kit', $arguments, $token, '/mcp'));
        expect(ToolResponse::fits($page))->toBeTrue()
            ->and(count($page['versions']))->toBeLessThanOrEqual(25);
        $numbers = [...$numbers, ...array_column($page['versions'], 'version_number')];
        $cursor = $page['next_cursor'];
        $firstCursor ??= $cursor;
    } while ($cursor !== null);

    expect($numbers)->toBe(range(800, 1))
        ->and(array_values(array_unique($numbers)))->toBe($numbers)
        ->and($firstCursor)->toBeString();

    brandMcpCall('get_brand_kit', ['limit' => 24, 'cursor' => $firstCursor], $token, '/mcp')
        ->assertOk()
        ->assertJsonPath('result.isError', true);
    brandMcpCall('get_brand_kit', ['limit' => 25, 'cursor' => $firstCursor.'x'], $token, '/mcp')
        ->assertOk()
        ->assertJsonPath('result.isError', true);
});
