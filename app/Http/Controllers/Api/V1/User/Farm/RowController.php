<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Models\Row;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\RowResource;
use App\Models\Field;

class RowController extends Controller
{
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

        $rowsData = [];

        foreach ($request->input('rows') as $row) {
            $rowData = [
                'field_id' => $field->id,
                'name' => $row['name'],
                'coordinates' => json_encode([$row['coordinates'][0], $row['coordinates'][1]]),
            ];
            $rowsData[] = $rowData;
        }

        Row::insert($rowsData);

        return response()->noContent();
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

        return response()->noContent();
    }
}
