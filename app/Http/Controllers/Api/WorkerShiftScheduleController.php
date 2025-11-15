<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkerShiftScheduleResource;
use App\Http\Resources\WorkerShiftScheduleCalendarResource;
use App\Models\Farm;
use App\Models\WorkerShiftSchedule;
use App\Models\Employee;
use App\Models\WorkShift;
use Illuminate\Http\Request;
use Carbon\Carbon;

class WorkerShiftScheduleController extends Controller
{
    /**
     * Display monthly calendar with shift schedules
     */
    public function index(Request $request, Farm $farm)
    {
        $month = $request->input('month', Carbon::now()->month);
        $year = $request->input('year', Carbon::now()->year);

        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $schedules = WorkerShiftSchedule::whereHas('employee', function ($query) use ($farm) {
                $query->where('farm_id', $farm->id);
            })
            ->whereBetween('scheduled_date', [$startDate, $endDate])
            ->with(['employee', 'shift'])
            ->get()
            ->groupBy('scheduled_date');

        return new WorkerShiftScheduleCalendarResource($schedules);
    }

    /**
     * Store a newly created shift schedule
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'shift_id' => 'required|exists:work_shifts,id',
            'scheduled_date' => 'required|date',
        ]);

        // Validate employee is shift-based
        $employee = Employee::findOrFail($validated['employee_id']);
        if ($employee->work_type !== 'shift_based') {
            return response()->json([
                'error' => 'Employee must be shift-based to assign shifts'
            ], 400);
        }

        // Check for overlapping shifts
        $scheduledDate = Carbon::parse($validated['scheduled_date']);
        $shift = WorkShift::findOrFail($validated['shift_id']);

        $overlapping = $this->checkOverlappingShifts(
            $employee,
            $scheduledDate,
            $shift
        );

        if ($overlapping) {
            return response()->json([
                'error' => 'Shift overlaps with existing schedule'
            ], 400);
        }

        $schedule = WorkerShiftSchedule::create($validated);

        return new WorkerShiftScheduleResource($schedule->load(['employee', 'shift']));
    }

    /**
     * Display the specified shift schedule
     */
    public function show(WorkerShiftSchedule $shift_schedule)
    {
        return new WorkerShiftScheduleResource($shift_schedule->load(['employee', 'shift']));
    }

    /**
     * Update the specified shift schedule
     */
    public function update(Request $request, WorkerShiftSchedule $shift_schedule)
    {
        $validated = $request->validate([
            'shift_id' => 'sometimes|exists:work_shifts,id',
            'scheduled_date' => 'sometimes|date',
            'status' => 'sometimes|in:scheduled,completed,missed,cancelled',
        ]);

        $shift_schedule->update($validated);
        $shift_schedule->refresh();
        $shift_schedule->load(['employee', 'shift']);

        return new WorkerShiftScheduleResource($shift_schedule);
    }

    /**
     * Remove the specified shift schedule
     */
    public function destroy(WorkerShiftSchedule $shift_schedule)
    {
        $shift_schedule->delete();

        return response()->noContent();
    }

    /**
     * Check for overlapping shifts
     */
    private function checkOverlappingShifts(Employee $employee, Carbon $date, WorkShift $newShift): bool
    {
        $existingSchedules = WorkerShiftSchedule::where('employee_id', $employee->id)
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
}
