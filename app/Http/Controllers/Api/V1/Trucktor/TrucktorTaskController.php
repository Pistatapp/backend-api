<?php

namespace App\Http\Controllers\Api\V1\Trucktor;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTrucktorTaskRequest;
use App\Http\Requests\UpdateTrucktorTaskRequest;
use App\Http\Resources\TrucktorTaskResource;
use App\Models\Trucktor;
use App\Models\TrucktorTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TrucktorTaskController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(TrucktorTask::class);
    }
    /**
     * Display a listing of the resource.
     */
    public function index(Trucktor $trucktor)
    {
        $tasks = $trucktor->tasks()->latest()->get();

        return TrucktorTaskResource::collection($tasks);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTrucktorTaskRequest $request, Trucktor $trucktor)
    {
        $task = $trucktor->tasks()->create([
            'operation_id' => $request->operation_id,
            'field_ids' => $request->field_ids,
            'name' => $request->name,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'description' => $request->description,
            'created_by' => auth()->id(),
        ]);

        Cache::forget('tasks');

        return new TrucktorTaskResource($task);
    }

    /**
     * Display the specified resource.
     */
    public function show(TrucktorTask $trucktorTask)
    {
        return new TrucktorTaskResource($trucktorTask);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTrucktorTaskRequest $request, TrucktorTask $trucktorTask)
    {
        $trucktorTask->update($request->only([
            'name',
            'start_date',
            'end_date',
            'description',
        ]));

        Cache::forget('tasks');

        return new TrucktorTaskResource($trucktorTask);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TrucktorTask $trucktorTask)
    {
        $trucktorTask->delete();

        Cache::forget('tasks');

        return response()->noContent();
    }
}
