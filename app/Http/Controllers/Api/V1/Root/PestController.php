<?php

namespace App\Http\Controllers\Api\V1\Root;

use App\Http\Controllers\Controller;
use App\Http\Resources\PestResource;
use Illuminate\Http\Request;
use App\Models\Pest;
use Illuminate\Http\JsonResponse;

class PestController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        return PestResource::collection(Pest::all());
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:pests,name',
            'scientific_name' => 'nullable|string',
            'description' => 'nullable|string',
            'damage' => 'nullable|string',
            'management' => 'nullable|string',
            'image' => 'nullable|image',
            'standard_day_degree' => 'nullable|numeric',
        ]);

        $pest = Pest::create($request->only([
            'name',
            'scientific_name',
            'description',
            'damage',
            'management',
            'standard_day_degree',
        ]));

        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $pest->addMedia($image)->toMediaCollection('images');
        }

        return new PestResource($pest);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Pest  $pest
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Pest $pest)
    {
        return new PestResource($pest);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Pest  $pest
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, Pest $pest)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:pests,name,' . $pest->id,
            'description' => 'nullable|string',
            'damage' => 'nullable|string',
            'management' => 'nullable|string',
            'standard_day_degree' => 'nullable|numeric',
        ]);

        $pest->update($request->only([
            'name',
            'scientific_name',
            'description',
            'damage',
            'management',
            'standard_day_degree',
        ]));

        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $pest->clearMediaCollection('images');
            $pest->addMedia($image)->toMediaCollection('images');
        }

        return new PestResource($pest->fresh());
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Pest  $pest
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Pest $pest)
    {
        $pest->delete();
        return response()->json([], JsonResponse::HTTP_GONE);
    }

    /**
     * Remove the specified image from storage.
     *
     * @param  \App\Models\Pest  $pest
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteImage(Pest $pest)
    {
        $pest->clearMediaCollection('images');
        return response()->json([], JsonResponse::HTTP_GONE);
    }
}
