<?php

namespace App\Services;

use App\Exceptions\CultivarRequiredForLoadEstimation;
use App\Exceptions\CropRequiredForLoadEstimation;
use App\Exceptions\CultivarProductMismatchForLoadEstimation;
use App\Models\CropType;
use App\Models\Field;
use InvalidArgumentException;

class LoadEstimationService
{
    public const FORMULA_VERSION = LoadEstimationCultivarResolver::FORMULA_VERSION;
    public const MAX_AVERAGE_BUD_COUNT = 10_000;
    public const MAX_TREE_COUNT = 1_000_000;
    public const MAX_CLUSTER_WEIGHT_GRAMS = 10_000;

    private const CONDITIONS = ['excellent', 'good', 'normal', 'bad'];

    public function __construct(
        private LoadEstimationCultivarResolver $cultivarResolver
    ) {
    }

    /**
     * Keep the legacy configuration endpoint operational. These rows are not
     * used by the farm calculation anymore.
     */
    public function updateTable(CropType $cropType, array $rows): void
    {
        $cropType->loadEstimationTable()->updateOrCreate(
            ['crop_type_id' => $cropType->id],
            ['rows' => $rows]
        );
    }

    /**
     * Calculate a traceable estimate from the Field's persisted CropType code.
     *
     * @param array{excellent: float|int, good: float|int, normal: float|int, bad: float|int} $clusterWeights
     * @return array<string, mixed>
     */
    public function estimate(
        Field $field,
        int $averageBudCount,
        int $treeCount,
        array $clusterWeights
    ): array {
        $cropType = $field->relationLoaded('cropType')
            ? $field->cropType
            : $field->cropType()->first();

        if (!$cropType) {
            throw new CultivarRequiredForLoadEstimation();
        }

        $farm = $field->relationLoaded('farm')
            ? $field->farm
            : $field->farm()->with('crop')->first();
        $crop = $farm?->relationLoaded('crop') ? $farm->crop : $farm?->crop()->first();

        if (!$crop) {
            throw new CropRequiredForLoadEstimation();
        }

        if ((int) $cropType->crop_id !== (int) $crop->id) {
            throw new CultivarProductMismatchForLoadEstimation();
        }

        $cultivar = $this->cultivarResolver->resolve($cropType);
        $results = $this->calculateResults(
            $averageBudCount,
            $treeCount,
            $clusterWeights,
            $cultivar['bud_to_fruit_cluster_conversion'],
            $cultivar['estimated_to_actual_yield_ratio'],
        );

        return [
            'farm_id' => (int) $field->farm_id,
            'field_id' => (int) $field->id,
            'crop' => [
                'id' => (int) $crop->id,
                'name' => (string) $crop->name,
            ],
            'cultivar' => [
                'id' => $cultivar['id'],
                'code' => $cultivar['code'],
                'name' => $cultivar['name'],
            ],
            'coefficients' => [
                'bud_to_fruit_cluster_conversion' => $cultivar['bud_to_fruit_cluster_conversion'],
                'estimated_to_actual_yield_ratio' => $cultivar['estimated_to_actual_yield_ratio'],
            ],
            'inputs' => [
                'average_bud_count' => $averageBudCount,
                'tree_count' => $treeCount,
                'cluster_weight_grams' => array_map('floatval', $clusterWeights),
            ],
            'results' => $results,
            // Additive aliases keep the existing Android response contract valid
            // while clients migrate to data.results.*.
            'excellent' => $results['excellent'],
            'good' => $results['good'],
            'normal' => $results['normal'],
            'bad' => $results['bad'],
            'formula_version' => $cultivar['formula_version'],
        ];
    }

    /**
     * @param array<string, float|int> $clusterWeights
     * @return array<string, array<string, float>>
     */
    public function calculateResults(
        int $averageBudCount,
        int $treeCount,
        array $clusterWeights,
        float $budToFruitClusterConversion,
        float $estimatedToActualYieldRatio
    ): array {
        $results = [];

        foreach (self::CONDITIONS as $condition) {
            if (!array_key_exists($condition, $clusterWeights)) {
                throw new InvalidArgumentException("Missing cluster weight for {$condition}.");
            }

            $loadGrams = (float) $clusterWeights[$condition]
                * $averageBudCount
                * $budToFruitClusterConversion
                * $estimatedToActualYieldRatio;
            $loadKgPerTree = $loadGrams / 1000;
            $totalPlotLoadKg = $loadKgPerTree * $treeCount;

            $results[$condition] = [
                'load_grams' => $loadGrams,
                'load_kg_per_tree' => $loadKgPerTree,
                'total_plot_load_kg' => $totalPlotLoadKg,
                // Backward-compatible aliases now preserve the exact value.
                'estimated_yield_per_tree_kg' => $loadKgPerTree,
                'estimated_yield_total_kg' => $totalPlotLoadKg,
            ];
        }

        return $results;
    }
}
