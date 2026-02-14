<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LabourShiftScheduleResource;
use App\Http\Resources\LabourShiftScheduleCalendarResource;
use App\Models\Farm;
use App\Models\LabourShiftSchedule;
use App\Models\Labour;
use App\Models\WorkShift;
use Illuminate\Http\Request;
use Carbon\Carbon;

class LabourShiftScheduleController extends Controller
{
    /**
     * Display monthly calendar with shift schedules
     */
    public function index(Request $request, Farm $farm)
    {
        $this->authorize('view', $farm);

        $month = $request->input('month', Carbon::now()->month);
        $year = $request->input('year', Carbon::now()->year);

        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $schedules = LabourShiftSchedule::whereHas('labour', function ($query) use ($farm) {
                $query->where('farm_id', $farm->id);
            })
            ->whereBetween('scheduled_date', [$startDate, $endDate])
            ->with(['labour', 'shift'])
            ->get()
            ->groupBy('scheduled_date');

        return new LabourShiftScheduleCalendarResource($schedules);
    }

    /**
     * Store a newly created shift schedule
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'labour_id' => 'required|exists:labours,id',
            'shift_id' => 'required|exists:work_shifts,id',
            'scheduled_dates' => 'required|array|min:1',
            'scheduled_dates.*' => 'required|shamsi_date',
        ]);

        // Validate labour is shift-based
        $labour = Labour::findOrFail($validated['labour_id']);
        $shift = WorkShift::findOrFail($validated['shift_id']);
        $this->authorize('view', $labour->farm);
        $this->assertSameFarm($labour, $shift);
        if ($labour->work_type !== 'shift_based') {
            return response()->json([
                'error' => 'Labour must be shift-based to assign shifts'
            ], 400);
        }

        // Check for overlapping shifts
        foreach ($validated['scheduled_dates'] as $scheduledDate) {
            $scheduledDate = jalali_to_carbon($scheduledDate);
            $overlapping = $this->checkOverlappingShifts(
                $labour,
                $scheduledDate,
                $shift
            );

            if ($overlapping) {
                return response()->json([
                    'error' => 'Shift overlaps with existing schedule'
                ], 400);
            }
        }

        $schedules = [];
        foreach ($validated['scheduled_dates'] as $scheduledDate) {
            $scheduledDate = jalali_to_carbon($scheduledDate);
            $schedules[] = LabourShiftSchedule::create([
                'labour_id' => $validated['labour_id'],
                'shift_id' => $validated['shift_id'],
                'scheduled_date' => $scheduledDate->toDateString(),
            ]);
        }

        return LabourShiftScheduleResource::collection($schedules);
    }

    /**
     * Display the specified shift schedule
     */
    public function show(LabourShiftSchedule $shift_schedule)
    {
        $this->authorizeScheduleFarm($shift_schedule);

        return new LabourShiftScheduleResource($shift_schedule->load(['labour', 'shift']));
    }

    /**
     * Update the specified shift schedule
     */
    public function update(Request $request, LabourShiftSchedule $shift_schedule)
    {
        $this->authorizeScheduleFarm($shift_schedule);

        $validated = $request->validate([
            'shift_id' => 'sometimes|exists:work_shifts,id',
            'scheduled_date' => 'sometimes|date',
            'status' => 'sometimes|in:scheduled,completed,missed,cancelled',
        ]);

        if (array_key_exists('shift_id', $validated)) {
            $shift = WorkShift::findOrFail($validated['shift_id']);
            $this->assertSameFarm($shift_schedule->labour, $shift);
        }

        $shift_schedule->update($validated);
        $shift_schedule->refresh();
        $shift_schedule->load(['labour', 'shift']);

        return new LabourShiftScheduleResource($shift_schedule);
    }

    /**
     * Remove the specified shift schedule
     */
    public function destroy(LabourShiftSchedule $shift_schedule)
    {
        $this->authorizeScheduleFarm($shift_schedule);

        $shift_schedule->delete();

        return response()->noContent();
    }

    /**
     * Check for overlapping shifts
     */
    private function checkOverlappingShifts(Labour $labour, Carbon $date, WorkShift $newShift): bool
    {
        $existingSchedules = LabourShiftSchedule::where('labour_id', $labour->id)
            ->whereDate('scheduled_date', $date)
            ->where('status', '!=', 'cancelled')
            ->with('shift')
            ->get();

        foreach ($existingSchedules as $schedule) {
            $existingShift = $schedule->shift;

            $newStart = Carbon::createFromFormat('H:i', $newShift->start_time->format('H:i'));
            $newEnd = Carbon::createFromFormat('H:i', $newShift->end_time->format('H:i'));
            if ($newEnd->lt($newStart)) {
                $newEnd->addDay();
            }

            $existingStart = Carbon::createFromFormat('H:i', $existingShift->start_time->format('H:i'));
            $existingEnd = Carbon::createFromFormat('H:i', $existingShift->end_time->format('H:i'));
            if ($existingEnd->lt($existingStart)) {
                $existingEnd->addDay();
            }

            // Check for overlap
            if ($newStart->lt($existingEnd) && $newEnd->gt($existingStart)) {
                return true;
            }
        }

        return false;
    }

    private function authorizeScheduleFarm(LabourShiftSchedule $shiftSchedule): void
    {
        $labourFarm = $shiftSchedule->labour?->farm;
        $shiftFarm = $shiftSchedule->shift?->farm;

        if ($labourFarm && $shiftFarm && $labourFarm->id !== $shiftFarm->id) {
            abort(422, 'Shift and labour must belong to the same farm.');
        }

        $farm = $labourFarm ?? $shiftFarm;
        if ($farm) {
            $this->authorize('view', $farm);
        }
    }

    private function assertSameFarm(Labour $labour, WorkShift $shift): void
    {
        if ($labour->farm_id !== $shift->farm_id) {
            abort(422, 'Shift and labour must belong to the same farm.');
        }
    }
}
