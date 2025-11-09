<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Models\Irrigation;
use Carbon\Carbon;

class PlotIrrigationTimeOverLap implements ValidationRule, DataAwareRule
{
    private $irrigation;
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    public function __construct($irrigation = null)
    {
        $this->irrigation = $irrigation;
    }

    /**
     * Set the data under validation.
     *
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $dateInput = $this->data['date'] ?? request('date');
        $startTimeInput = $this->data['start_time'] ?? request('start_time');
        $endTimeInput = $this->data['end_time'] ?? request('end_time');
        $plotIds = $this->data['plots'] ?? [];

        if (!$dateInput || !$startTimeInput || !$endTimeInput || empty($plotIds)) {
            return;
        }

        $date = $dateInput instanceof Carbon
            ? $dateInput
            : Carbon::parse($dateInput);

        $startTime = $startTimeInput instanceof Carbon
            ? $startTimeInput
            : Carbon::parse($startTimeInput);

        $endTime = $endTimeInput instanceof Carbon
            ? $endTimeInput
            : Carbon::parse($endTimeInput);

        $plotIds = array_filter(array_map(function ($plotId) {
            return is_numeric($plotId) ? (int) $plotId : null;
        }, (array) $plotIds));

        if (empty($plotIds)) {
            return;
        }

        $irrigationToIgnore = $this->irrigation ?? request()->route('irrigation');
        $farm = request()->route('farm');

        $query = Irrigation::query()
            ->whereHas('plots', function ($query) use ($plotIds) {
                $query->whereIn('plots.id', $plotIds);
            })
            ->when($farm, fn ($query) => $query->where('farm_id', $farm->id))
            ->whereDate('date', $date->format('Y-m-d'))
            // Check for time overlap
            ->where(function ($query) use ($startTime, $endTime) {
                $query->whereBetween('start_time', [$startTime, $endTime])
                    ->orWhereBetween('end_time', [$startTime, $endTime])
                    ->orWhere(function ($query) use ($startTime, $endTime) {
                        $query->where('start_time', '<=', $startTime)
                            ->where('end_time', '>=', $endTime);
                    });
            })
            ->when($irrigationToIgnore, function ($query) use ($irrigationToIgnore) {
                $query->where('id', '!=', $irrigationToIgnore->id);
            });

        if ($query->exists()) {
            $fail('زمان انتخاب شده با برنامه آبیاری دیگری برای این قطعه زمین تداخل دارد.');
        }
    }
}
