<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NutrientDiagnosisRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Includes related user and samples when relationships are loaded.
     * Includes authorization flags for responding to and deleting the request.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', function() {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->username,
                ];
            }),
            'farm_id' => $this->farm_id,
            'status' => $this->status,
            'approved_at' => $this->approved_at
                ? jdate($this->approved_at)->format('Y/m/d H:i:s')
                : null,
            'response_description' => $this->response_description,
            'response_attachment' => $this->response_attachment,
            'samples' => NutrientSampleResource::collection($this->whenLoaded('samples')),
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
            'can' => [
                'respond' => $request->user()->can('respond', $this->resource),
                'update' => $request->user()->can('update', $this->resource)
                    && $this->status !== 'approved',
                'delete' => $request->user()->can('delete', $this->resource),
            ],
        ];
    }
}
