<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActiveUserAttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],
            'coordinate' => $this->resource['coordinate'],
            'last_update' => $this->when(isset($this->resource['last_update']), function () {
                $lastUpdate = $this->resource['last_update'] ?? null;
                return $lastUpdate instanceof \Carbon\Carbon
                    ? $lastUpdate->toIso8601String()
                    : $lastUpdate;
            }),
            'is_in_zone' => $this->resource['is_in_zone'] ?? false,
        ];
    }
}
