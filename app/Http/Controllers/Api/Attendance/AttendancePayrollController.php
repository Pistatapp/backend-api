<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceMonthlyPayrollResource;
use App\Models\AttendanceMonthlyPayroll;
use App\Jobs\GenerateMonthlyPayrollJob;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AttendancePayrollController extends Controller
{
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'user_id' => 'nullable|exists:users,id',
        ]);

        $fromDate = Carbon::parse($validated['from_date']);
        $toDate = Carbon::parse($validated['to_date']);

        GenerateMonthlyPayrollJob::dispatch(
            $fromDate,
            $toDate,
            $validated['user_id'] ?? null
        );

        return response()->json([
            'message' => 'Payroll generation job queued successfully',
        ], 202);
    }

    public function index(Request $request)
    {
        $query = AttendanceMonthlyPayroll::with('user.profile');

        if ($request->has('from_date')) {
            $query->where('created_at', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->where('created_at', '<=', $request->to_date);
        }
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->has('month')) {
            $query->where('month', $request->month);
        }
        if ($request->has('year')) {
            $query->where('year', $request->year);
        }

        $payrolls = $query->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->paginate();
        return AttendanceMonthlyPayrollResource::collection($payrolls);
    }

    public function show(AttendanceMonthlyPayroll $attendanceMonthlyPayroll)
    {
        $attendanceMonthlyPayroll->load('user.profile');
        return new AttendanceMonthlyPayrollResource($attendanceMonthlyPayroll);
    }
}
