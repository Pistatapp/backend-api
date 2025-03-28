<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSliderRequest;
use App\Http\Requests\UpdateSliderRequest;
use App\Http\Resources\SliderResource;
use App\Models\Slider;
use Illuminate\Http\Request;

class SliderController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Slider::class, 'slider');
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $sliders = Slider::query()
            ->when(request()->has('page'), function ($query) {
                $query->where('page', request()->get('page'));
            })
            ->get();

        return SliderResource::collection($sliders);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSliderRequest $request)
    {
        $slider = Slider::create([
            'name' => $request->name,
            'page' => $request->page,
            'is_active' => $request->is_active,
            'interval' => $request->interval,
            'images' => collect($request->images)->map(function ($imageData) {
                $path = $imageData['file']->store('slides', 'public');
                return [
                    'path' => $path,
                    'sort_order' => $imageData['sort_order'],
                ];
            }),
        ]);

        return new SliderResource($slider);
    }

    /**
     * Display the specified resource.
     */
    public function show(Slider $slider)
    {
        return new SliderResource($slider);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSliderRequest $request, Slider $slider)
    {
        $slider->update([
            'name' => $request->name,
            'page' => $request->page,
            'is_active' => $request->is_active,
            'interval' => $request->interval,
            'images' => collect($request->images)->map(function ($imageData) {
                $path = $imageData['file']->store('slides', 'public');
                return [
                    'path' => $path,
                    'sort_order' => $imageData['sort_order'],
                ];
            }),
        ]);

        return new SliderResource($slider);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Slider $slider)
    {
        $slider->delete();

        return response()->noContent();
    }
}
