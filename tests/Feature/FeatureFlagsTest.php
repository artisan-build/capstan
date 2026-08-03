<?php

namespace Tests\Feature;

use App\Features\Artifacts;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class FeatureFlagsTest extends TestCase
{
    public function test_artifacts_feature_resolves_from_config_when_enabled(): void
    {
        config(['capstan.features.artifacts' => true]);
        Feature::flushCache();

        $this->assertTrue(Feature::active(Artifacts::class));
    }

    public function test_artifacts_feature_resolves_from_config_when_disabled(): void
    {
        config(['capstan.features.artifacts' => false]);
        Feature::flushCache();

        $this->assertFalse(Feature::active(Artifacts::class));
    }
}
