<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PestResource;
use App\Http\Requests\StorePestRequest;
use App\Http\Requests\UpdatePestRequest;
use Illuminate\Http\Request;
use App\Models\Pest;

class PestController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->authorizeResource(Pest::class, 'pest');
    }

    /**
     * Display a listing of the resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Root users can only see global pests
        if ($user->hasRole('root')) {
            $pests = Pest::global()->get();
        } else {
            // Other users can see global pests and their own custom pests
            $pests = Pest::accessibleByUser($user->id)->with('creator')->get();
        }

        return PestResource::collection($pests);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StorePestRequest  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StorePestRequest $request)
    {
        $user = $request->user();

        // Prepare data for creation
        $data = $request->only([
            'name',
            'scientific_name',
            'description',
            'damage',
            'management',
            'standard_day_degree',
        ]);

        // Set created_by based on user role
        if ($user->hasRole('admin')) {
            $data['created_by'] = $user->id;
        }
        // For root users, created_by remains null (global pest)

        $pest = Pest::create($data);

        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $pest->addMedia($image)->toMediaCollection('images');
        }

        return new PestResource($pest->load('creator'));
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Pest  $pest
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Pest $pest)
    {
        return new PestResource($pest->load('creator'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdatePestRequest  $request
     * @param  \App\Models\Pest  $pest
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdatePestRequest $request, Pest $pest)
    {
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

        return new PestResource($pest->fresh()->load('creator'));
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
        return response()->noContent();
    }

    /**
     * Remove the specified image from storage.
     *
     * @param  \App\Models\Pest  $pest
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteImage(Pest $pest)
    {
        $this->authorize('update', $pest);
        $pest->clearMediaCollection('images');
        return response()->noContent();
    }
}
