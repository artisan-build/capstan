<?php

namespace Tests\Feature;

use App\Features\Artifacts;
use App\Features\Postmaster;
use App\Features\Work;
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

    public function test_product_surfaces_are_always_on_and_obsolete_config_cannot_disable_them(): void
    {
        config([
            'capstan.features.artifacts' => false,
            'capstan.features.postmaster' => false,
            'capstan.features.work' => false,
        ]);
        Feature::flushCache();

        $this->assertTrue(Feature::active(Artifacts::class));
        $this->assertTrue(Feature::active(Postmaster::class));
        $this->assertTrue(Feature::active(Work::class));
    }

    public function test_obsolete_feature_config_is_not_declared(): void
    {
        /** @var array<string, mixed> $freshConfig */
        $freshConfig = require config_path('capstan.php');

        $this->assertArrayNotHasKey('features', $freshConfig);
    }
}
