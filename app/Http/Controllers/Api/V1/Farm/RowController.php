<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Models\Row;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\RowResource;
use App\Models\Field;
use Illuminate\Http\JsonResponse;

class RowController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Row::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Field $field)
    {
        return RowResource::collection($field->rows);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Field $field)
    {
        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'required|string|max:255',
            'rows.*.coordinates' => 'required|array|size:2',
            'rows.*.coordinates.*' => 'required|string',
        ]);

        $rows = $field->rows()->createMany($request->input('rows'));

        return RowResource::collection($rows);
    }

    /**
     * Display the specified resource.
     */
    public function show(Row $row)
    {
        $row->load('reports.operation', 'reports.labour');

        return new RowResource($row);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Row $row)
    {
        $row->delete();

        return response()->json([], JsonResponse::HTTP_GONE);
    }
}
