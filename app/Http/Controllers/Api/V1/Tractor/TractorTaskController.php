<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Http\Requests\FilterTractorTaskRequest;
use App\Http\Requests\StoreTractorTaskRequest;
use App\Http\Requests\UpdateTractorTaskRequest;
use App\Http\Resources\TractorTaskResource;
use App\Jobs\CalculateTaskGpsMetricsJob;
use App\Models\Field;
use App\Models\Tractor;
use App\Models\TractorTask;
use App\Notifications\TractorTaskCreated;
use App\Services\TractorReportFilterService;
use App\Services\TractorTaskService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class TractorTaskController extends Controller
{
    public function __construct(
        private TractorReportFilterService $reportFilterService,
        private TractorTaskService $tractorTaskService
    ) {
        $this->authorizeResource(TractorTask::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Tractor $tractor)
    {
        $request->validate([
            'date' => 'required|shamsi_date',
        ]);

        $date = jalali_to_carbon($request->query('date'));
        $tasks = $this->tractorTaskService->getAllTasksForDate($tractor, $date);

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
            'date' => $validated['date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'created_by' => $request->user()->id,
        ]);

        $task->syncTaskableItems(getModelClass($validated['taskable_type']), $validated['taskable_ids']);

        $farmAdmins = $tractor->farm->admins;
        $driver = $tractor->driver;

        // Send notifications to farm admins
        Notification::send($farmAdmins, new TractorTaskCreated($task));

        // Send notification to driver
        Notification::send($driver, new TractorTaskCreated($task));

        // Dispatch GPS metrics calculation if task end time has passed
        $taskEndDateTime = $task->getEndDateTime();
        $isTaskPassed = now()->greaterThan($taskEndDateTime);

        CalculateTaskGpsMetricsJob::dispatchIf($isTaskPassed, $task);

        return new TractorTaskResource(
            $task->refresh()->load(['taskableItems.taskable', 'operation'])
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(TractorTask $tractorTask)
    {
        $tractorTask->load('tractor.driver', 'taskableItems.taskable', 'operation', 'creator');

        return new TractorTaskResource($tractorTask);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTractorTaskRequest $request, TractorTask $tractorTask)
    {
        $validated = $request->validated();

        $gpsMetricsInputsChanged = $this->tractorTaskGpsMetricsInputsChanged($tractorTask, $validated);

        $tractorTask->update($this->tractorTaskUpdateAttributesFromValidated($validated));
        $tractorTask->syncTaskableItems(getModelClass($validated['taskable_type']), $validated['taskable_ids']);
        $this->syncTractorTaskValidatedData($tractorTask, $validated);

        if ($gpsMetricsInputsChanged) {
            $this->dispatchCalculateTaskGpsMetricsIfTaskEnded($tractorTask);
        }

        return new TractorTaskResource($tractorTask->fresh([
            'taskableItems.taskable',
            'operation',
        ]));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function tractorTaskGpsMetricsInputsChanged(TractorTask $tractorTask, array $validated): bool
    {
        return ! $this->taskableSelectionMatches($tractorTask, $validated)
            || ! $tractorTask->date->isSameDay($validated['date'])
            || $tractorTask->start_time->format('H:i:s') !== Carbon::parse($validated['start_time'])->format('H:i:s')
            || $tractorTask->end_time->format('H:i:s') !== Carbon::parse($validated['end_time'])->format('H:i:s');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function tractorTaskUpdateAttributesFromValidated(array $validated): array
    {
        return [
            'operation_id' => $validated['operation_id'],
            'date' => $validated['date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function taskableSelectionMatches(TractorTask $task, array $validated): bool
    {
        $existingType = $task->taskableItems()->value('taskable_type');
        if ($existingType !== getModelClass($validated['taskable_type'])) {
            return false;
        }

        $existing = $task->taskableItems()->pluck('taskable_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $incoming = collect($validated['taskable_ids'])->map(fn ($id) => (int) $id)->sort()->values()->all();

        return $existing === $incoming;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function syncTractorTaskValidatedData(TractorTask $tractorTask, array $validated): void
    {
        if (! array_key_exists('data', $validated) || ! is_array($validated['data'])) {
            return;
        }

        $dataUpdates = [];
        foreach ($validated['data'] as $key => $value) {
            $dataUpdates["data->{$key}"] = $value;
        }

        if (! empty($dataUpdates)) {
            $tractorTask->update($dataUpdates);
        }
    }

    private function dispatchCalculateTaskGpsMetricsIfTaskEnded(TractorTask $tractorTask): void
    {
        $tractorTask->refresh();

        $taskEndDateTime = $tractorTask->getEndDateTime();

        CalculateTaskGpsMetricsJob::dispatchIf(
            now()->greaterThan($taskEndDateTime),
            $tractorTask
        );
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

        return new TractorTaskResource($tractorTask->fresh(['taskableItems.taskable', 'operation']));
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
     * Filter tractor tasks based on various criteria.
     */
    public function filterTasks(FilterTractorTaskRequest $request)
    {
        $validated = $request->validated();

        // Verify user has access to the tractor's farm
        $tractor = Tractor::findOrFail($validated['tractor_id']);
        if (! $tractor->farm->users->contains($request->user())) {
            abort(403, 'Unauthorized access to this tractor.');
        }

        $query = TractorTask::query()
            ->with(['operation', 'taskableItems.taskable', 'creator', 'tractor.driver', 'operation'])
            ->where('tractor_id', $validated['tractor_id'])
            ->whereBetween('date', [$validated['start_date'], $validated['end_date']]);

        // Filter by fields if provided
        if (! empty($validated['fields'])) {
            $fieldModel = Field::class;
            $query->where(function ($q) use ($validated, $fieldModel) {
                foreach ($validated['fields'] as $fieldId) {
                    $q->orWhereHas('taskableItems', function ($subQ) use ($fieldId, $fieldModel) {
                        $subQ->where('taskable_type', $fieldModel)
                            ->where('taskable_id', $fieldId);
                    });
                }
            });
        }

        // Filter by operations if provided
        if (! empty($validated['operations'])) {
            $query->whereIn('operation_id', $validated['operations']);
        }

        $tasks = $query->orderBy('date', 'asc')
            ->orderBy('start_time', 'asc')
            ->paginate($request->input('per_page', 50));

        return TractorTaskResource::collection($tasks);
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
            'operation' => 'nullable|exists:operations,id',
        ]);

        // Verify user has access to the tractor's farm
        $tractor = Tractor::findOrFail($validated['tractor_id']);
        if (! $tractor->farm->users->contains($request->user())) {
            abort(403, 'Unauthorized access to this tractor.');
        }

        $data = $this->reportFilterService->filter($validated);

        return response()->json(['data' => $data]);
    }
}
