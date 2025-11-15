<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Farm;
use App\Models\Employee;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $employees = $farm->employees();

        if (request()->has('search')) {
            $employees = $employees->where('fname', 'like', '%' . request()->search . '%')
                ->orWhere('lname', 'like', '%' . request()->search . '%')
                ->get();
        } else {
            $employees = $employees->simplePaginate();
        }

        return EmployeeResource::collection($employees);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreEmployeeRequest $request, Farm $farm)
    {
        $employee = $farm->employees()->create($request->validated());

        if ($request->has('team_id')) {
            $employee->teams()->sync($request->team_id);
        }

        return new EmployeeResource($employee);
    }

    /**
     * Display the specified resource.
     */
    public function show(Employee $employee)
    {
        return new EmployeeResource($employee->load('teams'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateEmployeeRequest $request, Employee $employee)
    {
        $employee->update($request->validated());

        if ($request->has('team_id')) {
            $employee->teams()->sync($request->team_id);
        }

        return new EmployeeResource($employee->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Employee $employee)
    {
        $employee->delete();

        return response()->noContent();
    }
}
