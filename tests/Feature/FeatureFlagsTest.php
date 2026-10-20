<?php

namespace Tests\Feature;

use App\Auth\CapstanCredentialDeclaration;
use App\Features\Artifacts;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class FeatureFlagsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://app.capstan.test',
            'capstan.artifacts.render_origin' => 'https://artifacts.capstan.test',
        ]);
    }

    public function test_artifacts_remains_gated_only_by_its_runtime_requirements(): void
    {
        config(['capstan.features.artifacts' => false]);
        Feature::flushCache();

        $this->assertTrue(Feature::active(Artifacts::class));
    }

    public function test_obsolete_feature_config_is_not_declared(): void
    {
        /** @var array<string, mixed> $freshConfig */
        $freshConfig = require config_path('capstan.php');

        $this->assertArrayNotHasKey('features', $freshConfig);
    }

    public function test_public_credential_purpose_surfaces_only_offer_artifact_ingest_and_machine(): void
    {
        /** @var array<string, mixed> $freshConfig */
        $freshConfig = require config_path('built-for-cloud.php');

        $this->assertSame([
            CapstanCredentialDeclaration::ARTIFACT_INGEST => CredentialPurpose::Consumption->value,
            CapstanCredentialDeclaration::MACHINE => CredentialPurpose::Mcp->value,
        ], $freshConfig['credentials']['app_purposes']);
        $this->assertSame([
            CapstanCredentialDeclaration::ARTIFACT_INGEST,
            CapstanCredentialDeclaration::MACHINE,
        ], $freshConfig['ui']['credential_purposes']);
    }
}
