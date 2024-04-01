<?php

namespace App\Http\Controllers\Api\V1\Farm;

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
            'coordinates' => 'required|array|min:1',
            'coordinates.*' => 'required|array|size:2',
            'coordinates.*.*' => 'required|string',
        ]);

        $rows = $request->input('coordinates');
        $rowsData = [];

        foreach ($rows as $rowCoordinates) {
            $rowData = [
                'field_id' => $field->id,
                'coordinates' => json_encode([$rowCoordinates[0], $rowCoordinates[1]]),
            ];
            $rowsData[] = $rowData;
        }

        Row::insert($rowsData);

        return response()->noContent();
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
