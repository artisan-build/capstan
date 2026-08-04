<?php

use App\Enums\ArtifactVisibility;
use App\Models\Artifact;
use App\Models\Team;
use App\Support\ArtifactRenderOrigin;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.url' => 'https://app.capstan.test',
        'capstan.features.artifacts' => true,
        'capstan.artifacts.render_origin' => 'https://artifacts.capstan.test',
    ]);
    Feature::flushCache();
    Storage::fake();
});

function uuidKeyedArtifact(string $content, ArtifactVisibility $visibility = ArtifactVisibility::SignedUrl): Artifact
{
    [$contentHash, $storageKey] = Artifact::storeBlob($content);

    return Artifact::factory()->create([
        'visibility' => $visibility,
        'expires_at' => now()->addHour(),
        'content_type' => 'text/html',
        'size_bytes' => strlen($content),
        'content_hash' => $contentHash,
        'storage_key' => $storageKey,
    ]);
}

test('artifacts are keyed by a uuid version 7 primary key', function (): void {
    $artifact = Artifact::factory()->create();

    expect($artifact->id)->toBeString()
        ->and(Str::isUuid($artifact->id))->toBeTrue()
        // The version nibble is the first hex digit of the third group.
        ->and($artifact->id[14])->toBe('7')
        ->and($artifact->getIncrementing())->toBeFalse()
        ->and($artifact->getKeyType())->toBe('string')
        ->and($artifact->fresh()?->id)->toBe($artifact->id);
});

test('artifact ids sort lexically in creation order', function (): void {
    $first = Artifact::factory()->create();
    $second = Artifact::factory()->create();

    expect(strcmp($first->id, $second->id))->toBeLessThan(0);
});

test('share and content routes resolve a uuid-keyed artifact and refuse an unknown uuid', function (): void {
    $artifact = uuidKeyedArtifact('<html><body>uuid keyed artifact</body></html>');
    $origin = app(ArtifactRenderOrigin::class);

    $this->get($origin->signedViewerUrl($artifact))->assertOk();
    $this->get($origin->signedContentUrl($artifact))->assertOk();

    $unknownShareUrl = URL::temporarySignedRoute('artifacts.share', now()->addHour(), ['artifact' => (string) Str::uuid7()]);
    $this->get($unknownShareUrl)->assertNotFound();

    $unknownContentPath = URL::temporarySignedRoute('artifacts.content', now()->addHour(), ['artifact' => (string) Str::uuid7()], false);
    $this->get('https://artifacts.capstan.test'.$unknownContentPath)->assertNotFound();
});

test('team grants pivot on the uuid artifact id and cascade when the artifact is deleted', function (): void {
    $artifact = uuidKeyedArtifact('<html><body>team granted artifact</body></html>', ArtifactVisibility::OrgAuth);
    $team = Team::default();

    $artifact->teams()->sync([$team->id]);

    expect($artifact->teams()->pluck('teams.id')->all())->toBe([$team->id]);
    $this->assertDatabaseHas('artifact_team', [
        'artifact_id' => $artifact->id,
        'team_id' => $team->id,
    ]);

    $artifact->delete();

    $this->assertDatabaseMissing('artifact_team', ['artifact_id' => $artifact->id]);
});
