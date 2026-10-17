<?php

namespace Tests\Feature;

use App\Features\Artifacts;
use App\Features\Work;
use Illuminate\Support\Env;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class FeatureFlagsTest extends TestCase
{
    /**
     * The artifacts feature resolves from the flag AND a usable render origin,
     * so both cases below configure a separate render host explicitly. Without
     * that, these tests would be asserting the flag's effect while silently
     * depending on whatever render origin the environment happened to supply.
     * The render-origin half of the contract is covered in
     * tests/Feature/Artifacts/FeatureRequiresRenderOriginTest.php.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://app.capstan.test',
            'capstan.artifacts.render_origin' => 'https://artifacts.capstan.test',
        ]);
    }

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

    public function test_work_feature_has_a_false_code_default_without_inheriting_the_environment(): void
    {
        $environment = Env::getRepository();
        $original = $environment->get('CAPSTAN_FEATURE_WORK');
        $environment->clear('CAPSTAN_FEATURE_WORK');

        try {
            /** @var array{features: array{work: bool}} $freshConfig */
            $freshConfig = require config_path('capstan.php');

            $this->assertFalse($freshConfig['features']['work']);
        } finally {
            if ($original !== null) {
                $environment->set('CAPSTAN_FEATURE_WORK', $original);
            }
        }
    }

    public function test_work_feature_respects_explicit_config(): void
    {
        config(['capstan.features.work' => false]);
        Feature::flushCache();

        $this->assertFalse(Feature::active(Work::class));

        config(['capstan.features.work' => true]);
        Feature::flushCache();

        $this->assertTrue(Feature::active(Work::class));

        config(['capstan.features.work' => false]);
        Feature::flushCache();

        $this->assertFalse(Feature::active(Work::class));
    }
}
