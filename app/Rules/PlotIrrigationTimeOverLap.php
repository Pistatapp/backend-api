<?php

namespace App\Rules;

use App\Models\Irrigation;
use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class PlotIrrigationTimeOverLap implements ValidationRule, DataAwareRule
{
    private $irrigation;

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct($irrigation = null)
    {
        $this->irrigation = $irrigation;
    }

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
        [$startDateTime, $endDateTime, $plotIds] = $this->extractValidationData();

        if (!$startDateTime || !$endDateTime || empty($plotIds)) {
            return;
        }

        $query = $this->buildOverlapQuery($startDateTime, $endDateTime, $plotIds);

        if ($query->exists()) {
            $fail(__('The selected time overlaps with another irrigation schedule for this plot.'));
        }
    }

    private function extractValidationData(): array
    {
        $startTimeInput = $this->data['start_time'] ?? request('start_time');
        $endTimeInput = $this->data['end_time'] ?? request('end_time');
        $plotIds = $this->data['plots'] ?? [];

        if (!$startTimeInput || !$endTimeInput) {
            return [null, null, []];
        }

        // start_time and end_time are already combined datetime values from prepareForValidation
        $startDateTime = $startTimeInput instanceof Carbon ? $startTimeInput : Carbon::parse($startTimeInput);
        $endDateTime = $endTimeInput instanceof Carbon ? $endTimeInput : Carbon::parse($endTimeInput);

        $plotIds = array_filter(array_map('intval', array_filter($plotIds, 'is_numeric')));

        return [$startDateTime, $endDateTime, $plotIds];
    }

    private function buildOverlapQuery(Carbon $startDateTime, Carbon $endDateTime, array $plotIds)
    {
        $irrigationToIgnore = $this->irrigation ?? request()->route('irrigation');
        $farm = request()->route('farm');

        return Irrigation::query()
            ->whereHas('plots', fn($query) => $query->whereIn('plots.id', $plotIds))
            ->when($farm, fn($query) => $query->where('farm_id', $farm->id))
            ->where(fn($query) => $this->addOverlapConditions($query, $startDateTime, $endDateTime))
            ->when($irrigationToIgnore, fn($query) => $query->where('id', '!=', $irrigationToIgnore->id));
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
