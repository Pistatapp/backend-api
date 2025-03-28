<?php

namespace App\Services;

use App\Models\CropType;

class LoadEstimationService
{
    /**
     * Update the load estimation table for a crop type.
     *
     * @param CropType $cropType
     * @param array $rows
     * @return void
     */
    public function updateTable(CropType $cropType, array $rows): void
    {
        $cropType->loadEstimationTable()->updateOrCreate(
            ['crop_type_id' => $cropType->id],
            ['rows' => $rows]
        );
    }

    /**
     * Calculate the estimated yields for all conditions.
     *
     * @param int $averageBudCount
     * @param int $treeCount
     * @param array $loadEstimationTableRows
     * @return array
     */
    public function calculateEstimatedYields(int $averageBudCount, int $treeCount, array $loadEstimationTableRows): array
    {
        $conditions = ['excellent', 'good', 'normal', 'bad'];
        $estimatedYields = [];

        foreach ($conditions as $condition) {
            foreach ($loadEstimationTableRows as $row) {
                if ($row['condition'] === $condition) {
                    $estimatedYields[$condition] = $this->calculateEstimatedYield(
                        $averageBudCount,
                        $treeCount,
                        $row
                    );
                    break;
                }
            }
        }

        return $estimatedYields;
    }

    /**
     * Calculate the estimated yield for a single condition.
     *
     * @param int $averageBudCount
     * @param int $treeCount
     * @param array $conditionRow
     * @return array
     */
    private function calculateEstimatedYield(int $averageBudCount, int $treeCount, array $conditionRow): array
    {
        $estimatedYieldForSingleTreeGrams = $averageBudCount * $conditionRow['fruit_cluster_weight']
            * $conditionRow['bud_to_fruit_conversion']
            * $conditionRow['estimated_to_actual_yield_ratio'];

        $estimatedYieldForSingleTreeKg = $estimatedYieldForSingleTreeGrams / 1000;
        $estimatedYield = $estimatedYieldForSingleTreeKg * $treeCount;

        return [
            'estimated_yield_per_tree_kg' => round($estimatedYieldForSingleTreeKg),
            'estimated_yield_total_kg' => round($estimatedYield),
        ];
    }
}
