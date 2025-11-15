<?php

namespace App\Http\Controllers\Api\Worker;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkerMonthlyPayrollResource;
use App\Models\WorkerMonthlyPayroll;
use App\Models\Employee;
use App\Jobs\GenerateMonthlyPayrollJob;
use Illuminate\Http\Request;
use Carbon\Carbon;

class WorkerPayrollController extends Controller
{
    /**
     * Generate payroll for date range
     */
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'employee_id' => 'nullable|exists:employees,id',
        ]);

        $fromDate = Carbon::parse($validated['from_date']);
        $toDate = Carbon::parse($validated['to_date']);

        GenerateMonthlyPayrollJob::dispatch(
            $fromDate,
            $toDate,
            $validated['employee_id'] ?? null
        );

        return response()->json([
            'message' => 'Payroll generation job queued successfully'
        ], 202);
    }

    /**
     * List payroll reports
     */
    public function index(Request $request)
    {
        $query = WorkerMonthlyPayroll::with('employee');

        // Filter by date range
        if ($request->has('from_date')) {
            $query->where('created_at', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->where('created_at', '<=', $request->to_date);
        }

        // Filter by employee
        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        // Filter by month/year
        if ($request->has('month')) {
            $query->where('month', $request->month);
        }
        if ($request->has('year')) {
            $query->where('year', $request->year);
        }

        $payrolls = $query->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->paginate();
        return WorkerMonthlyPayrollResource::collection($payrolls);
    }

    /**
     * Get detailed payroll report
     */
    public function show(WorkerMonthlyPayroll $workerMonthlyPayroll)
    {
        $workerMonthlyPayroll->load('employee');
        return new WorkerMonthlyPayrollResource($workerMonthlyPayroll);
    }
}
