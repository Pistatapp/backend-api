<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTractorTaskRequest;
use App\Http\Requests\UpdateTractorTaskRequest;
use App\Http\Resources\TractorTaskResource;
use App\Models\Tractor;
use App\Models\TractorTask;
use Illuminate\Http\Request;
use App\Services\TractorReportFilterService;
use Illuminate\Support\Facades\Auth;

class TractorTaskController extends Controller
{
    public function __construct(
        private TractorReportFilterService $reportFilterService
    ) {
        $this->authorizeResource(TractorTask::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Tractor $tractor)
    {
        $query = $tractor->tasks()->latest();

        if ($request->has('date')) {
            $date = jalali_to_carbon($request->query('date'))->toDateString();
            $query->forDate($date);

            return TractorTaskResource::collection($query->get());
        }

        $tasks = $query->simplePaginate();

        return TractorTaskResource::collection($tasks);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTractorTaskRequest $request, Tractor $tractor)
    {
        $validated = $request->validated();

        $task = $tractor->tasks()->create([
            'operation_id' => $validated['operation_id'],
            'field_id' => $validated['field_id'],
            'date' => $validated['date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'created_by' => Auth::id(),
        ]);

        return new TractorTaskResource($task);
    }

    /**
     * Display the specified resource.
     */
    public function show(TractorTask $tractorTask)
    {
        return new TractorTaskResource($tractorTask);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTractorTaskRequest $request, TractorTask $tractorTask)
    {
        $validated = $request->validated();

        $updateData = [
            'operation_id' => $validated['operation_id'],
            'field_id' => $validated['field_id'],
            'date' => $validated['date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
        ];

        // Apply base updates first
        $tractorTask->update($updateData);

        // Apply JSON data fields using dot-notation so it respects fillable keys
        if (array_key_exists('data', $validated) && is_array($validated['data'])) {
            $dataUpdates = [];
            foreach ($validated['data'] as $key => $value) {
                $dataUpdates["data->{$key}"] = $value;
            }
            if (!empty($dataUpdates)) {
                $tractorTask->update($dataUpdates);
            }
        }

        return new TractorTaskResource($tractorTask->fresh());
    }

    /**
     * Partially update the data attributes of a tractor task.
     */
    public function patchData(Request $request, TractorTask $tractorTask)
    {
        $this->authorize('update', $tractorTask);

        $validated = $request->validate([
            'consumed_water' => 'nullable|numeric|min:0',
            'consumed_fertilizer' => 'nullable|numeric|min:0',
            'consumed_poison' => 'nullable|numeric|min:0',
            'operation_area' => 'nullable|numeric|min:0',
            'workers_count' => 'nullable|integer|min:0',
        ]);

        // Update JSON attributes via dot-notation to match fillable keys
        $dataUpdates = [];
        foreach ($validated as $key => $value) {
            $dataUpdates["data->{$key}"] = $value;
        }
        $tractorTask->update($dataUpdates);

        return new TractorTaskResource($tractorTask->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TractorTask $tractorTask)
    {
        $tractorTask->delete();

        return response()->noContent();
    }

    /**
     * Filter tractor reports by tractor and date.
     */
    public function filterReports(Request $request)
    {
        $validated = $request->validate([
            'tractor_id' => 'required|exists:tractors,id',
            'date' => 'required_without:period|shamsi_date',
            'period' => 'required_without:date|in:month,year,specific_month,persian_year',
            'month' => 'required_if:period,specific_month|shamsi_date',
            'year' => 'required_if:period,persian_year|regex:/^\d{4}$/',
            'operation' => 'nullable|exists:operations,id'
        ]);

        $data = $this->reportFilterService->filter($validated);

        return response()->json(['data' => $data]);
    }
}
