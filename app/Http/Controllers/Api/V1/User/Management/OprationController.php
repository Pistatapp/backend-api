<?php

namespace App\Http\Controllers\Api\V1\User\Management;

use App\Http\Controllers\Controller;
use App\Http\Resources\OperationResource;
use App\Models\Operation;
use App\Models\Farm;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class OprationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $operations = $farm->operations()->whereNull('parent_id')->get();
        return OperationResource::collection($operations);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Farm $farm)
    {
        $request->validate([
            'parent_id' => 'nullable|exists:operations,id', // 'nullable' means 'optional
            'name' => 'required|string|max:255',
        ]);

        $operation = $farm->operations()->create($request->all());

        return new OperationResource($operation);
    }

    /**
     * Display the specified resource.
     */
    public function show(Operation $operation)
    {
        return new OperationResource($operation->load('children'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Operation $operation)
    {
        $request->validate([
            'parent_id' => 'nullable|exists:operations,id', // 'nullable' means 'optional
            'name' => 'required|string|max:255',
        ]);

        $this->authorize('update', $operation);

        $operation->update($request->only('name', 'parent_id'));

        return new OperationResource($operation->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Operation $operation)
    {
        $this->authorize('delete', $operation);

        $operation->delete();

        return response()->json([], JsonResponse::HTTP_GONE);
    }
}
