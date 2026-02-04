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
            'id'         => $this->id,
            'user' => [
                'id'         => $this->user->id,
                'username'   => $this->user->username,
                'mobile'     => $this->user->mobile,
            ],
            'name' => $this->name,
            'province'   => $this->province,
            'city'       => $this->city,
            'company'    => $this->company,
            'photo'      => $this->user->getFirstMediaUrl('photo'),
        ];
    }
}
