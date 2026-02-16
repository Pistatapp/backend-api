<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceShiftScheduleResource;
use App\Http\Resources\AttendanceShiftScheduleCalendarResource;
use App\Models\Farm;
use App\Models\AttendanceShiftSchedule;
use App\Models\User;
use App\Models\WorkShift;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AttendanceShiftScheduleController extends Controller
{
    public function index(Request $request, Farm $farm)
    {
        $this->authorize('view', $farm);

        $month = $request->input('month', Carbon::now()->month);
        $year = $request->input('year', Carbon::now()->year);

        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $schedules = AttendanceShiftSchedule::whereHas('user.attendanceTracking', function ($query) use ($farm) {
            $query->where('farm_id', $farm->id)->where('enabled', true);
        })
            ->whereBetween('scheduled_date', [$startDate, $endDate])
            ->with(['user.profile', 'shift'])
            ->get()
            ->groupBy('scheduled_date');

        return new AttendanceShiftScheduleCalendarResource($schedules);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'shift_id' => 'required|exists:work_shifts,id',
            'scheduled_dates' => 'required|array|min:1',
            'scheduled_dates.*' => 'required|shamsi_date',
        ]);

        $user = User::with('attendanceTracking')->findOrFail($validated['user_id']);
        $shift = WorkShift::findOrFail($validated['shift_id']);

        $tracking = $user->attendanceTracking;
        if (! $tracking || ! $tracking->enabled) {
            return response()->json(['error' => 'User must have attendance tracking enabled'], 400);
        }
        if ($tracking->work_type !== 'shift_based') {
            return response()->json(['error' => 'User must be shift-based to assign shifts'], 400);
        }
        if ($tracking->farm_id !== $shift->farm_id) {
            return response()->json(['error' => 'User and shift must belong to the same farm'], 400);
        }

        $this->authorize('view', $shift->farm);

        foreach ($validated['scheduled_dates'] as $scheduledDate) {
            $scheduledDate = jalali_to_carbon($scheduledDate);
            $overlapping = $this->checkOverlappingShifts($user, $scheduledDate, $shift);
            if ($overlapping) {
                return response()->json(['error' => 'Shift overlaps with existing schedule'], 400);
            }
        }

        $schedules = [];
        foreach ($validated['scheduled_dates'] as $scheduledDate) {
            $scheduledDate = jalali_to_carbon($scheduledDate);
            $schedules[] = AttendanceShiftSchedule::create([
                'user_id' => $validated['user_id'],
                'shift_id' => $validated['shift_id'],
                'scheduled_date' => $scheduledDate->toDateString(),
            ]);
        }

        return AttendanceShiftScheduleResource::collection($schedules);
    }

    public function show(AttendanceShiftSchedule $shift_schedule)
    {
        $this->authorizeScheduleFarm($shift_schedule);
        return new AttendanceShiftScheduleResource($shift_schedule->load(['user.profile', 'shift']));
    }

    public function update(Request $request, AttendanceShiftSchedule $shift_schedule)
    {
        $this->authorizeScheduleFarm($shift_schedule);

        $validated = $request->validate([
            'shift_id' => 'sometimes|exists:work_shifts,id',
            'scheduled_date' => 'sometimes|date',
            'status' => 'sometimes|in:scheduled,completed,missed,cancelled',
        ]);

        if (array_key_exists('shift_id', $validated)) {
            $shift = WorkShift::findOrFail($validated['shift_id']);
            $tracking = $shift_schedule->user?->attendanceTracking;
            if ($tracking && $tracking->farm_id !== $shift->farm_id) {
                abort(422, 'Shift and user must belong to the same farm.');
            }
        }

        $shift_schedule->update($validated);
        return new AttendanceShiftScheduleResource($shift_schedule->fresh()->load(['user.profile', 'shift']));
    }

    public function destroy(AttendanceShiftSchedule $shift_schedule)
    {
        $this->authorizeScheduleFarm($shift_schedule);
        $shift_schedule->delete();
        return response()->noContent();
    }

    private function checkOverlappingShifts(User $user, Carbon $date, WorkShift $newShift): bool
    {
        $existingSchedules = AttendanceShiftSchedule::where('user_id', $user->id)
            ->whereDate('scheduled_date', $date)
            ->where('status', '!=', 'cancelled')
            ->with('shift')
            ->get();

        foreach ($existingSchedules as $schedule) {
            $existingShift = $schedule->shift;
            if (! $existingShift) {
                continue;
            }

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

            if ($newStart->lt($existingEnd) && $newEnd->gt($existingStart)) {
                return true;
            }
        }

        return false;
    }

    private function authorizeScheduleFarm(AttendanceShiftSchedule $schedule): void
    {
        $userFarmId = $schedule->user?->attendanceTracking?->farm_id;
        $shiftFarmId = $schedule->shift?->farm_id;

        if ($userFarmId && $shiftFarmId && $userFarmId !== $shiftFarmId) {
            abort(422, 'Shift and user must belong to the same farm.');
        }

        $farmId = $userFarmId ?? $shiftFarmId;
        if ($farmId) {
            $this->authorize('view', Farm::find($farmId));
        }
    }
}
