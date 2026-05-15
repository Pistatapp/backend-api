<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Resources\FarmPlanResource;
use App\Http\Resources\FieldResource;
use App\Http\Resources\PlotResource;
use App\Http\Resources\RowResource;
use App\Http\Resources\TreeResource;
use App\Models\FarmPlan;
use App\Models\Field;
use App\Models\Plot;
use App\Models\Row;
use App\Models\Tree;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QrEntityLookupController extends Controller
{
    /**
     * Resolve a QR payload (unique_id) to an entity and return the same payload as that model's show action.
     */
    public function __invoke(Request $request): JsonResource
    {
        $validated = $request->validate([
            'unique_id' => 'required|string|max:255',
        ]);

        $uniqueId = $validated['unique_id'];

        $resolved = $this->resolveByUniqueId($uniqueId);

        if ($resolved === null) {
            abort(404, __('No entity found for this code.'));
        }

        $this->authorize('view', $resolved['model']);

        $resource = $this->resourceForShow($resolved['type'], $resolved['model']);

        return $resource->additional([
            'meta' => [
                'entity_type' => $resolved['type'],
                'unique_id' => $resolved['model']->unique_id,
            ],
        ]);
    }

    /**
     * @return array{type: string, model: Field|Row|Tree|Plot|FarmPlan}|null
     */
    private function resolveByUniqueId(string $uniqueId): ?array
    {
        $matches = [];

        $checks = [
            ['type' => 'field', 'model' => Field::where('unique_id', $uniqueId)->first()],
            ['type' => 'plot', 'model' => Plot::where('unique_id', $uniqueId)->first()],
            ['type' => 'row', 'model' => Row::where('unique_id', $uniqueId)->first()],
            ['type' => 'tree', 'model' => Tree::where('unique_id', $uniqueId)->first()],
            ['type' => 'farm_plan', 'model' => FarmPlan::where('unique_id', $uniqueId)->first()],
        ];

        foreach ($checks as $check) {
            if ($check['model'] !== null) {
                $matches[] = ['type' => $check['type'], 'model' => $check['model']];
            }
        }

        if (count($matches) === 0) {
            return null;
        }

        if (count($matches) > 1) {
            abort(409, __('This code matches more than one record; contact support.'));
        }

        return $matches[0];
    }

    private function resourceForShow(string $type, Field|Row|Tree|Plot|FarmPlan $model): JsonResource
    {
        return match ($type) {
            'field' => new FieldResource(
                $model->load([
                    'attachments',
                    'cropType',
                    'reports.operation',
                    'reports.labour',
                ])->loadCount('rows', 'plots', 'trees')
            ),
            'row' => new RowResource(
                $model->load('reports.operation', 'reports.labour')
            ),
            'tree' => new TreeResource(
                $model->load('attachments', 'reports.operation', 'reports.labour')
            ),
            'plot' => new PlotResource(
                $model->load('attachments')
            ),
            'farm_plan' => new FarmPlanResource(
                $model->load('details.treatment', 'details.treatable')
            ),
        };
    }
}
