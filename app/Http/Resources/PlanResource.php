<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
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
            'price' => $this->price,
            'currency' => $this->currency,
            'interval' => $this->interval,
            'trial_period' => $this->trial_period,
            'trial_interval' => $this->trial_interval,
            'invoice_period' => $this->invoice_period,
            'invoice_interval' => $this->invoice_interval,
            'grace_period' => $this->grace_period,
            'grace_interval' => $this->grace_interval,
            'prorate_day' => $this->prorate_day,
            'prorate_period' => $this->prorate_period,
            'prorate_extend_due' => $this->prorate_extend_due,
            'active_subscribers_limit' => $this->active_subscribers_limit,
            'is_active' => $this->is_active,
            'features' => FeatureResource::collection($this->whenLoaded('features')),
            'created_at' => jdate($this->created_at)->format('datetime'),
        ];
    }
}
