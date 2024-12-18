<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Resources\PhonologyGuideFileResource;
use Illuminate\Http\JsonResponse;

class PhonologyGuideFileController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param string $model_type
     * @param string $model_id
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(string $model_type = null, string $model_id = null)
    {
        abort_unless($model_type && $model_id, 404);

        $model = getModel($model_type, $model_id);

        return PhonologyGuideFileResource::collection($model->phonologyGuideFiles);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $model_type
     * @param string $model_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request, string $model_type = null, string $model_id = null)
    {
        abort_unless($model_type && $model_id, 404);

        $request->validate([
            'name' => 'required|string|max:255|unique:phonology_guide_files,name',
            'file' => 'required|file|max:10240',
        ]);

        $model = getModel($model_type, $model_id);

        $phonologyGuideFile = $model->phonologyGuideFiles()->create(
            $request->only('name') + ['created_by' => $request->user()->id]
        );

        $phonologyGuideFile->addMediaFromRequest('file')->toMediaCollection('phonology_guide_files');

        return new PhonologyGuideFileResource($phonologyGuideFile);
    }

    /**
     * Delete the specified resource in storage.
     *
     * @param string $model_type
     * @param string $model_id
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(string $model_type = null, string $model_id = null, string $id)
    {
        abort_unless($model_type && $model_id, 404);

        $model = getModel($model_type, $model_id);

        $phonologyGuideFile = $model->phonologyGuideFiles()->findOrFail($id);

        abort_if($phonologyGuideFile->user->id !== request()->user()->id, 403);

        $phonologyGuideFile->clearMediaCollection('phonology_guide_files');

        $phonologyGuideFile->delete();

        return response()->json([], JsonResponse::HTTP_GONE);
    }
}
