<?php

namespace App\Http\Controllers\Api\Labour;

use App\Http\Controllers\Controller;
use App\Http\Resources\LabourMonthlyPayrollResource;
use App\Models\LabourMonthlyPayroll;
use App\Models\Labour;
use App\Jobs\GenerateMonthlyPayrollJob;
use Illuminate\Http\Request;
use Carbon\Carbon;

class LabourPayrollController extends Controller
{
    /**
     * Generate payroll for date range
     */
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'labour_id' => 'nullable|exists:labours,id',
        ]);

        $fromDate = Carbon::parse($validated['from_date']);
        $toDate = Carbon::parse($validated['to_date']);

        GenerateMonthlyPayrollJob::dispatch(
            $fromDate,
            $toDate,
            $validated['labour_id'] ?? null
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
        $query = LabourMonthlyPayroll::with('labour');

        // Filter by date range
        if ($request->has('from_date')) {
            $query->where('created_at', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->where('created_at', '<=', $request->to_date);
        }

        // Filter by labour
        if ($request->has('labour_id')) {
            $query->where('labour_id', $request->labour_id);
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
        return LabourMonthlyPayrollResource::collection($payrolls);
    }

    /**
     * Get detailed payroll report
     */
    public function show(LabourMonthlyPayroll $labourMonthlyPayroll)
    {
        $labourMonthlyPayroll->load('labour');
        return new LabourMonthlyPayrollResource($labourMonthlyPayroll);
    }
}

