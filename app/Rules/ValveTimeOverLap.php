<?php

namespace App\Rules;

use App\Models\Irrigation;
use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class ValveTimeOverLap implements ValidationRule, DataAwareRule
{
    /** @var array<string, mixed> */
    protected array $data;

    public function setData(array $data): static
    {
        $this->data = $data;
        return $this;
    }

    /**
     * Run the validation rule.
     *
     * @param \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        [$startDateTime, $endDateTime, $valves] = $this->extractValidationData();

        if (!$startDateTime || !$endDateTime || empty($valves)) {
            return;
        }

        $query = $this->buildOverlapQuery($startDateTime, $endDateTime, $valves);

        if ($query->exists()) {
            $fail(__('The selected time overlaps with another irrigation schedule for this service.'));
        }
    }

    private function extractValidationData(): array
    {
        $valves = $this->data['valves'] ?? [];
        $startTime = $this->data['start_time'] ?? null;
        $endTime = $this->data['end_time'] ?? null;

        if (empty($valves) || !$startTime || !$endTime) {
            return [null, null, []];
        }

        // start_time and end_time are already combined datetime values from prepareForValidation
        $startDateTime = $startTime instanceof Carbon ? $startTime : Carbon::parse($startTime);
        $endDateTime = $endTime instanceof Carbon ? $endTime : Carbon::parse($endTime);

        return [$startDateTime, $endDateTime, $valves];
    }

    private function buildOverlapQuery(Carbon $startDateTime, Carbon $endDateTime, array $valves)
    {
        $irrigation = request()->route('irrigation');
        $farmId = request()->route('farm')->id ?? ($irrigation?->farm_id);

        return Irrigation::query()
            ->where('farm_id', $farmId)
            ->where(fn($query) => $this->addOverlapConditions($query, $startDateTime, $endDateTime))
            ->whereHas('valves', fn($query) => $query->whereIn('valves.id', $valves))
            ->when($irrigation, fn($query) => $query->where('id', '!=', $irrigation->id));
    }

    private function addOverlapConditions($query, Carbon $startDateTime, Carbon $endDateTime)
    {
        return $query
            // New irrigation starts during existing irrigation
            ->orWhere(fn($q) => $q->where('start_time', '<=', $startDateTime)->where('end_time', '>', $startDateTime))
            // New irrigation ends during existing irrigation
            ->orWhere(fn($q) => $q->where('start_time', '<', $endDateTime)->where('end_time', '>=', $endDateTime))
            // New irrigation completely encompasses existing irrigation
            ->orWhere(fn($q) => $q->where('start_time', '>=', $startDateTime)->where('end_time', '<=', $endDateTime));
    }
}
