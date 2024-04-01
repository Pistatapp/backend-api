<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlockResource;
use App\Models\Block;
use App\Models\Field;
use Illuminate\Http\Request;

class BlockController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Field $field)
    {
        return BlockResource::collection($field->blocks);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Field $field)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'coordinates' => 'required|array',
            'coordinates.*' => 'required|string',
        ]);

        throw_unless($field->hasRows(), new \Exception('Field must have rows before creating blocks.'));

        $block = $field->blocks()->create($request->only([
            'name',
            'coordinates',
        ]));

        return new BlockResource($block);
    }

    /**
     * Display the specified resource.
     */
    public function show(Block $block)
    {
        return new BlockResource($block);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Block $block)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'coordinates' => 'required|array',
            'coordinates.*' => 'required|string',
        ]);

        $block->update($request->only([
            'name',
            'coordinates',
        ]));

        return new BlockResource($block);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Block $block)
    {
        $block->delete();

        return response()->noContent();
    }
}
