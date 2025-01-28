<?php

namespace App\Http\Controllers\Api\V1\Root;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSliderRequest;
use App\Http\Resources\SliderResource;
use App\Models\Slider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SliderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $sliders = request()->query('page')
            ? Slider::where('page', request()->query('page'))->get()
            : Slider::all();

        return SliderResource::collection($sliders);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSliderRequest $request)
    {
        $images = [];

        foreach ($request->images as $image) {
            $images[] = [
                'sort_order' => $image['sort_order'],
                'file' => url('storage/' . $image['file']->store('sliders', 'public')),
            ];
        }

        $slider = Slider::create([
            'name' => $request->name,
            'images' => $images,
            'page' => $request->page,
            'is_active' => $request->is_active,
            'interval' => $request->interval,
        ]);

        return new SliderResource($slider);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Slider $slider)
    {
        $images = $slider->images;

        foreach ($request->images as $image) {
            if (isset($image['file'])) {
                $images[] = [
                    'sort_order' => $image['sort_order'],
                    'file' => $image['file']->store('sliders', 'public'),
                ];
            }
        }

        $slider->update([
            'name' => $request->name,
            'images' => $images,
            'page' => $request->page,
            'is_active' => $request->is_active,
            'interval' => $request->interval,
        ]);

        return new SliderResource($slider);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Slider $slider)
    {
        $slider->delete();

        return response()->json([], JsonResponse::HTTP_GONE);
    }
}
