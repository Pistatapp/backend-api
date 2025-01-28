<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FeatureResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'value' => $this->value,
            'resettable_period' => $this->resettable_period,
            'resettable_interval' => $this->resettable_interval,
            'sort_order' => $this->sort_order,
            'created_at' => jdate($this->created_at)->format('datetime'),
        ];
    }
}
