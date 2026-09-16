<?php

namespace App\Services;

use App\Exceptions\CultivarRequiredForLoadEstimation;
use App\Exceptions\UnsupportedCultivarForLoadEstimation;
use App\Models\CropType;

final class LoadEstimationCultivarResolver
{
    public const FORMULA_VERSION = '1.0';

    /**
     * The CropType record supplies the stable code; it never supplies values.
     *
     * @var array<string, array{bud_to_fruit_cluster_conversion: float, estimated_to_actual_yield_ratio: float}>
     */
    private const COEFFICIENTS = [
        'ahmad-aghaei' => [
            'bud_to_fruit_cluster_conversion' => 0.89,
            'estimated_to_actual_yield_ratio' => 0.92,
        ],
        'akbari' => [
            'bud_to_fruit_cluster_conversion' => 0.82,
            'estimated_to_actual_yield_ratio' => 0.95,
        ],
    ];

    /**
     * @return array{
     *     id: int,
     *     code: string,
     *     name: string,
     *     bud_to_fruit_cluster_conversion: float,
     *     estimated_to_actual_yield_ratio: float,
     *     formula_version: string
     * }
     */
    public function resolve(CropType $cropType): array
    {
        $code = is_string($cropType->load_estimation_code)
            ? trim($cropType->load_estimation_code)
            : '';

        if ($code === '') {
            throw new CultivarRequiredForLoadEstimation();
        }

        if (!array_key_exists($code, self::COEFFICIENTS)) {
            throw new UnsupportedCultivarForLoadEstimation($code);
        }

        return [
            'id' => (int) $cropType->id,
            'code' => $code,
            'name' => (string) $cropType->name,
            ...self::COEFFICIENTS[$code],
            'formula_version' => self::FORMULA_VERSION,
        ];
    }

    /**
     * @return list<string>
     */
    public function supportedCodes(): array
    {
        return array_keys(self::COEFFICIENTS);
    }
}
