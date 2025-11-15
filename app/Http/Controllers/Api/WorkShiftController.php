<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkShiftResource;
use App\Models\Farm;
use App\Models\WorkShift;
use Illuminate\Http\Request;
use Carbon\Carbon;

class WorkShiftController extends Controller
{
    /**
     * Display a listing of work shifts for a farm
     */
    public function index(Farm $farm)
    {
        $shifts = $farm->workShifts()->orderBy('start_time')->get();
        return WorkShiftResource::collection($shifts);
    }

    /**
     * Store a newly created work shift
     */
    public function store(Request $request, Farm $farm)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'work_hours' => 'required|numeric|min:0|max:24',
        ]);

        // Calculate work hours if not provided
        if (!isset($validated['work_hours'])) {
            $start = Carbon::createFromFormat('H:i', $validated['start_time']);
            $end = Carbon::createFromFormat('H:i', $validated['end_time']);
            if ($end->lt($start)) {
                $end->addDay();
            }
            $validated['work_hours'] = $start->diffInHours($end);
        }

        $shift = $farm->workShifts()->create($validated);

        return new WorkShiftResource($shift);
    }

    /**
     * Display the specified work shift
     */
    public function show(WorkShift $workShift)
    {
        return new WorkShiftResource($workShift);
    }

    /**
     * Update the specified work shift
     */
    public function update(Request $request, WorkShift $workShift)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i',
            'work_hours' => 'sometimes|numeric|min:0|max:24',
        ]);

        $workShift->update($validated);

        return new WorkShiftResource($workShift->fresh());
    }

    /**
     * Remove the specified work shift
     */
    public function destroy(WorkShift $workShift)
    {
        // Check if shift has any scheduled workers
        if ($workShift->shiftSchedules()->exists()) {
            return response()->json([
                'error' => 'Cannot delete shift with scheduled workers'
            ], 400);
        }

        $workShift->delete();

        return response()->noContent();
    }
}
