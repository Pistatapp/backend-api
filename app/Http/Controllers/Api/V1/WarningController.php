<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateWarningRequest;
use App\Models\Warning;
use App\Services\WarningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarningController extends Controller
{
    public function __construct(
        protected WarningService $warningService
    ) {}

    /**
     * Get warnings based on the related-to parameter
     */
    public function index(Request $request): JsonResponse
    {
        $relatedTo = $request->query('related-to');
        if (!$relatedTo) {
            return response()->json(['message' => 'related-to parameter is required'], 400);
        }

        $warnings = $this->warningService->getWarningsByRelatedTo($relatedTo);
        $workingEnvironment = $request->user()->preferences['working_environment'] ?? null;
        $userWarnings = Warning::where('farm_id', $workingEnvironment)->get();

        $result = collect($warnings)->map(function ($warning, $key) use ($userWarnings) {
            $userWarning = $userWarnings->where('key', $key)->first();

            return [
                'key' => $key,
                'setting_message' => $this->warningService->formatSettingMessage(
                    $key,
                    $userWarning?->parameters ?? []
                ),
                'enabled' => $userWarning?->enabled ?? false,
                'parameters' => $userWarning?->parameters ?? [],
                'setting_message_parameters' => $warning['setting-message-parameters'],
                'type' => $warning['type'] ?? $userWarning?->type ?? 'one-time'
            ];
        })->values();

        return response()->json(['data' => $result]);
    }

    /**
     * Update or create a warning setting
     */
    public function store(UpdateWarningRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (!$this->warningService->validateParameters($validated['key'], $validated['parameters'] ?? [])) {
            return response()->json(['message' => 'Invalid parameters provided'], 422);
        }

        $warningDefinition = $this->warningService->getWarningDefinition($validated['key']);

        $warning = Warning::updateOrCreate(
            [
                'farm_id' => $request->user()->preferences['working_environment'],
                'key' => $validated['key']
            ],
            [
                'enabled' => $validated['enabled'],
                'parameters' => $validated['parameters'] ?? [],
                'type' => $validated['type'] ?? $warningDefinition['type'] ?? 'one-time'
            ]
        );

        return response()->json([
            'message' => 'Warning settings updated successfully',
            'warning' => $warning
        ]);
    }
}
