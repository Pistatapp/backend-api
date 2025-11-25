<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Resources\PhonologyGuideFileResource;

class PhonologyGuideFileController extends Controller
{
    public function __construct()
    {
        $this->middleware('role:root', ['only' => ['store', 'destroy']]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $request->validate([
            'model_type' => 'required|string',
            'model_id' => 'required'
        ]);

        $model = getModel($request->model_type, $request->model_id);

        return PhonologyGuideFileResource::collection($model->phonologyGuideFiles);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:phonology_guide_files,name',
            'file' => 'required|file|mimes:pdf|max:10240',
            'model_type' => 'required|string',
            'model_id' => 'required'
        ]);

        $model = getModel($request->model_type, $request->model_id);

        $phonologyGuideFile = $model->phonologyGuideFiles()->create(
            $request->only('name') + ['created_by' => $request->user()->id]
        );

        $phonologyGuideFile->addMediaFromRequest('file')->toMediaCollection('phonology_guide_files');

        return new PhonologyGuideFileResource($phonologyGuideFile);
    }

    /**
     * Delete the specified resource in storage.
     */
    public function destroy(Request $request, string $id)
    {
        $request->validate([
            'model_type' => 'required|string',
            'model_id' => 'required'
        ]);

        $model = getModel($request->model_type, $request->model_id);

        $phonologyGuideFile = $model->phonologyGuideFiles()->findOrFail($id);

        $phonologyGuideFile->clearMediaCollection('phonology_guide_files');

        $phonologyGuideFile->delete();

        return response()->noContent();
    }
}
