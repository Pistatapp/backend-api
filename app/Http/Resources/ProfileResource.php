<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'first_name' => $this->first_name,
            'last_name'  => $this->last_name,
            'province'   => $this->province,
            'city'       => $this->city,
            'company'    => $this->company,
            'photo'      => $this->user->getFirstMediaUrl('photo'),
        ];
    }
}
