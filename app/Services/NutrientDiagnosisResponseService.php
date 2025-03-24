<?php

namespace App\Services;

use App\Models\NutrientDiagnosisRequest;
use App\Notifications\NutrientDiagnosisResponse;
use Illuminate\Http\UploadedFile;

class NutrientDiagnosisResponseService
{
    /**
     * Handle the response to a nutrient diagnosis request.
     *
     * @param NutrientDiagnosisRequest $request
     * @param string $description
     * @param UploadedFile $attachment
     * @return void
     */
    public function handle(NutrientDiagnosisRequest $request, string $description, UploadedFile $attachment): void
    {
        // Store the attachment
        $path = $attachment->store('nutrient-diagnosis', 'public');

        // Update the request
        $request->update([
            'status' => 'completed',
            'response_description' => $description,
            'response_attachment' => $path
        ]);

        // Notify the request creator
        $request->user->notify(new NutrientDiagnosisResponse($request));
    }
}
