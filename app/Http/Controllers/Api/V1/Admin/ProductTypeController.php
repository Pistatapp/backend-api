<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductTypeResource;
use App\Models\Product;
use App\Models\ProductType;
use Illuminate\Http\Request;

class ProductTypeController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Product $product)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:product_types,name',
        ]);

        $productType = $product->productTypes()->create($request->only('name'));

        return new ProductTypeResource($productType);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ProductType $productType)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:product_types,name,' . $productType->id . ',id',
        ]);

        $productType->update($request->only('name'));

        return new ProductTypeResource($productType);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ProductType $productType)
    {
        abort_if($productType->fields()->exists(), 400, 'This product type has fields.');

        $productType->delete();

        return response()->noContent();
    }
}
