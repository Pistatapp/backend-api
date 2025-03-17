<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTractorTaskRequest;
use App\Http\Requests\UpdateTractorTaskRequest;
use App\Http\Resources\TractorTaskResource;
use App\Models\Tractor;
use App\Models\TractorTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;

class TractorTaskController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(TractorTask::class);
    }
    /**
     * Display a listing of the resource.
     */
    public function index(Tractor $tractor)
    {
        $tasks = $tractor->tasks()->latest()->simplePaginate();

        return TractorTaskResource::collection($tasks);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTractorTaskRequest $request, Tractor $tractor)
    {
        $task = $tractor->tasks()->create([
            'operation_id' => $request->operation_id,
            'field_id' => $request->field_id,
            'date' => $request->date,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'created_by' => $request->user()->id,
        ]);

        Cache::forget('tasks');

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
        $tractorTask->update($request->only([
            'operation_id',
            'field_id',
            'date',
            'start_time',
            'end_time',
        ]));

        Cache::forget('tasks');

        return new TractorTaskResource($tractorTask->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TractorTask $tractorTask)
    {
        $tractorTask->delete();

        Cache::forget('tasks');

        return response()->json([], JsonResponse::HTTP_GONE);
    }
}
