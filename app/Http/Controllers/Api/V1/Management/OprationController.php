<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Http\Resources\OperationCollection;
use App\Models\Operation;
use App\Models\Farm;
use Illuminate\Http\Request;

class OprationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        return new OperationCollection($farm->operations);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Farm $farm)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $operation = $farm->operations()->create($request->only('name'));

        return response()->json($operation, 201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Operation $operation)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $this->authorize('update', $operation);

        $operation->update($request->only('name'));

        return response()->json([], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Operation $operation)
    {
        $this->authorize('delete', $operation);

        $operation->delete();

        return response()->json(null, 204);
    }
}
