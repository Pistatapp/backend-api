<?php

namespace Tests\Unit\Services;

use App\Models\CropType;
use App\Models\Field;
use App\Services\LoadEstimationCultivarResolver;
use App\Services\LoadEstimationService;
use App\Exceptions\CultivarRequiredForLoadEstimation;
use App\Exceptions\UnsupportedCultivarForLoadEstimation;
use PHPUnit\Framework\TestCase;

class LoadEstimationServiceTest extends TestCase
{
    public function test_ahmad_aghaei_uses_one_shared_synthetic_bud_count_for_all_conditions(): void
    {
        $service = new LoadEstimationService(new LoadEstimationCultivarResolver());
        $results = $service->calculateResults(
            300,
            100,
            [
                'excellent' => 50,
                'good' => 40,
                'normal' => 30,
                'bad' => 20,
            ],
            0.89,
            0.92,
        );

        $this->assertEqualsWithDelta(12282.0, $results['excellent']['load_grams'], 0.000001);
        $this->assertEqualsWithDelta(12.282, $results['excellent']['load_kg_per_tree'], 0.000001);
        $this->assertEqualsWithDelta(1228.2, $results['excellent']['total_plot_load_kg'], 0.000001);
        $this->assertEqualsWithDelta(9825.6, $results['good']['load_grams'], 0.000001);
        $this->assertEqualsWithDelta(7369.2, $results['normal']['load_grams'], 0.000001);
        $this->assertEqualsWithDelta(4912.8, $results['bad']['load_grams'], 0.000001);
        $this->assertSame(12.282, $results['excellent']['estimated_yield_per_tree_kg']);
        $this->assertSame(1228.2, $results['excellent']['estimated_yield_total_kg']);
    }

    public function test_akbari_uses_its_own_coefficients(): void
    {
        $service = new LoadEstimationService(new LoadEstimationCultivarResolver());
        $results = $service->calculateResults(
            300,
            100,
            ['excellent' => 50, 'good' => 40, 'normal' => 30, 'bad' => 20],
            0.82,
            0.95,
        );

        $this->assertEqualsWithDelta(11685.0, $results['excellent']['load_grams'], 0.000001);
        $this->assertEqualsWithDelta(11.685, $results['excellent']['load_kg_per_tree'], 0.000001);
        $this->assertEqualsWithDelta(1168.5, $results['excellent']['total_plot_load_kg'], 0.000001);
    }

    public function test_resolver_rejects_missing_and_unsupported_codes(): void
    {
        $resolver = new LoadEstimationCultivarResolver();

        $missing = new CropType(['name' => 'بدون رقم']);
        $this->expectException(CultivarRequiredForLoadEstimation::class);
        $resolver->resolve($missing);
    }

    public function test_resolver_rejects_unsupported_code(): void
    {
        $resolver = new LoadEstimationCultivarResolver();
        $unsupported = new CropType([
            'name' => 'رقم ناشناخته',
            'load_estimation_code' => 'unsupported',
        ]);
        // Enterprise staging stores this value in metadata; setting the
        // attribute explicitly keeps the resolver test portable across both
        // legacy-column and enterprise-metadata models.
        $unsupported->setAttribute('load_estimation_code', 'unsupported');

        $this->expectException(UnsupportedCultivarForLoadEstimation::class);
        $resolver->resolve($unsupported);
    }

    public function test_results_are_deterministic_for_identical_inputs(): void
    {
        $service = new LoadEstimationService(new LoadEstimationCultivarResolver());
        $inputs = [300, 100, ['excellent' => 50, 'good' => 40, 'normal' => 30, 'bad' => 20], 0.89, 0.92];

        $this->assertSame(
            $service->calculateResults(...$inputs),
            $service->calculateResults(...$inputs)
        );
    }
}
