<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\Field;
use App\Models\NutrientDiagnosisRequest;
use Illuminate\Support\Facades\Auth;

class NutrientDiagnosisRequestService
{
    /**
     * Create a new nutrient diagnosis request with samples.
     *
     * @param Farm $farm
     * @param array $samples
     * @return NutrientDiagnosisRequest
     */
    public function create(Farm $farm, array $samples): NutrientDiagnosisRequest
    {
        $diagnosisRequest = NutrientDiagnosisRequest::create([
            'user_id' => Auth::id(),
            'farm_id' => $farm->id,
            'status' => 'pending'
        ]);

        foreach ($samples as $sample) {
            $field = Field::find($sample['field_id']);
            $sample['field_area'] = $sample['field_area'] ?? (
                $field && $field->coordinates
                    ? calculate_polygon_area($field->coordinates)
                    : 0
            );

            $diagnosisRequest->samples()->create($sample);
        }

        return $diagnosisRequest->load('samples.field');
    }
}
