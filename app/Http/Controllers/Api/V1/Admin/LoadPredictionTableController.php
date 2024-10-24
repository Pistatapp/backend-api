<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CropType;
use Illuminate\Http\Request;

class LoadPredictionTableController extends Controller
{
    /**
     * Display the specified resource.
     */
    public function show(CropType $cropType)
    {
        return response()->json(['data' => $cropType->loadPredictionTable]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, CropType $cropType)
    {
        $cropType->loadPredictionTable()->updateOrCreate(
            ['crop_type_id' => $cropType->id],
            $request->only('headers', 'rows')
        );

        return response()->json([
            'success' => __('Load prediction table updated successfully'),
            'status' => 200
        ]);
    }
}
