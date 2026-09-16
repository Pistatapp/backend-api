<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Resources\FieldResource;
use App\Models\Farm;
use App\Models\Field;
use App\Models\CropType;
use App\Helpers\UniqueId;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FieldController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Field::class);
    }

    /**
     * Display a listing of the resource.
     *
     * @param \App\Models\Farm $farm
     * @return \App\Http\Resources\FieldResource
     */
    public function index(Farm $farm)
    {
        return FieldResource::collection($farm->fields);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Farm $farm
     * @return \App\Http\Resources\FieldResource
     */
    public function store(Request $request, Farm $farm)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'coordinates' => 'required|array|min:3',
            'coordinates.*' => 'required|string',
            'center' => 'required|string',
            'area' => 'required|numeric|min:0',
            'crop_type_id' => ['nullable', Rule::exists('crop_types', 'id')->where('is_active', true)],
        ]);
        $this->ensureCropTypeMatchesFarm($farm, $request->crop_type_id);

        $field = $farm->fields()->create(array_merge([
            'name' => $request->name,
            'coordinates' => $request->coordinates,
            'center' => $request->center,
            'area' => $request->area,
            'crop_type_id' => $request->crop_type_id,
        ], UniqueId::makeForTable('fields')));

        return new FieldResource($field);
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Models\Field $field
     * @return \App\Http\Resources\FieldResource
     */
    public function show(Field $field)
    {
        $fields = $field->load([
            'attachments',
            'cropType',
            'reports.operation',
            'reports.labour',
        ])->loadCount('rows', 'plots', 'trees');

        return new FieldResource($fields);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Field $field
     * @return \App\Http\Resources\FieldResource
     */
    public function update(Request $request, Field $field)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'coordinates' => 'required|array|min:3',
            'coordinates.*' => 'required|string',
            'center' => 'required|string',
            'area' => 'required|numeric|min:0',
            'crop_type_id' => ['nullable', Rule::exists('crop_types', 'id')->where('is_active', true)],
        ]);
        $this->ensureCropTypeMatchesFarm($field->farm()->firstOrFail(), $request->crop_type_id);

        $field->update([
            'name' => $request->name,
            'coordinates' => $request->coordinates,
            'center' => $request->center,
            'area' => $request->area,
            'crop_type_id' => $request->crop_type_id,
        ]);

        return new FieldResource($field->fresh());
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Models\Field $field
     * @return \Illuminate\Http\Response
     */
    public function destroy(Field $field)
    {
        $field->delete();

        return response()->noContent();
    }

    private function ensureCropTypeMatchesFarm(Farm $farm, mixed $cropTypeId): void
    {
        if ($cropTypeId === null || $cropTypeId === '') {
            return;
        }

        $matches = CropType::query()
            ->whereKey($cropTypeId)
            ->where('crop_id', $farm->crop_id)
            ->exists();

        if (!$matches) {
            throw ValidationException::withMessages([
                'crop_type_id' => ['رقم انتخاب‌شده با محصول این باغ سازگار نیست.'],
            ]);
        }
    }
}
