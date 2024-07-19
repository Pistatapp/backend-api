<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Http\Controllers\Controller;
use App\Http\Resources\TreeResource;
use App\Models\Row;
use App\Models\Tree;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class TreeController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param \App\Models\Row $row
     * @return \App\Http\Resources\TreeResource
     */
    public function index(Row $row)
    {
        $trees = $row->trees()->select('id', 'row_id', 'location')->get();
        return TreeResource::collection($trees);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Row $row
     * @return \App\Http\Resources\TreeResource
     */
    public function store(Request $request, Row $row)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'product' => 'required|string|max:255',
            'image' => 'nullable|image|max:1024',
        ]);

        $tree = $row->trees()->create($request->only([
            'name',
            'location',
        ]));

        if ($request->hasFile('image')) {
            $tree->addMediaFromRequest('image')->toMediaCollection('image');
            $tree->image = $tree->getFirstMediaUrl('image');
        }

        $uniqueId = Str::random(15);
        $tree->unique_id = $uniqueId;

        $tree->qr_code = base64_encode(QrCode::size(300)->generate($uniqueId));

        $tree->save();

        return new TreeResource($tree);
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Models\Tree $tree
     * @return \App\Http\Resources\TreeResource
     */
    public function show(Tree $tree)
    {
        $tree->load('attachments', 'reports.operation', 'reports.labour');
        return new TreeResource($tree);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Tree $tree
     * @return \App\Http\Resources\TreeResource
     */
    public function update(Request $request, Tree $tree)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'required|array|min:2',
            'location.*' => 'required|numeric',
            'image' => 'nullable|image|max:1024',
        ]);

        $tree->fill($request->only([
            'name',
            'location',
        ]));

        if ($request->hasFile('image')) {
            $tree->clearMediaCollection('image');
            $tree->addMediaFromRequest('image')->toMediaCollection('image');
            $tree->image = $tree->getFirstMediaUrl('image');
        }

        $tree->save();

        return new TreeResource($tree->refresh());
    }

    /**
     * Batch store a collection of trees.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Row $row
     * @return \Illuminate\Http\Response
     */
    public function batchStore(Request $request, Row $row)
    {
        $request->validate([
            'trees' => 'required|array|min:1',
            'trees.*.name' => 'required|string',
            'trees.*.location' => 'required|string|regex:/\d+\.\d+,\d+\.\d+/',
        ]);

        $trees = $request->input('trees');
        $treesData = [];

        foreach ($trees as $tree) {
            $treeData = [
                'row_id' => $row->id,
                'name' => $tree['name'],
                'location' => json_encode($tree['location']),
                'unique_id' => $uniqueId = Str::random(15),
                'qr_code' => base64_encode(QrCode::size(300)->generate($uniqueId)),
            ];

            $treesData[] = $treeData;
        }

        Tree::insert($treesData);

        return response()->noContent();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Models\Tree $tree
     * @return \Illuminate\Http\Response
     */
    public function destroy(Tree $tree)
    {
        $tree->delete();

        return response()->noContent();
    }
}
