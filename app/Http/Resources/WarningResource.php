<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarningResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'setting_message' => $this->setting_message,
            'enabled' => $this->enabled,
            'parameters' => $this->parameters,
            'setting_message_parameters' => $this->setting_message_parameters,
            'type' => $this->type,
            'related_to' => $this->related_to,
            'last_updated' => $this->updated_at->format('Y/m/d H:i:s')
        ];
    }
}
