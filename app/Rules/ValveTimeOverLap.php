<?php

namespace App\Rules;

use App\Models\Irrigation;
use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class ValveTimeOverLap implements ValidationRule, DataAwareRule
{

    /**
     * The data the validation rule has access to.
     *
     * @var array<string, mixed>
     */
    protected array $data;

    /**
     * Set the data the validation rule has access to.
     *
     * @param  array<string, mixed>  $data
     * @return static
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $valves = $this->data['valves'];
            $start_time = $this->data['start_time'];
            $end_time = $this->data['end_time'];
            $start_date = Carbon::parse($this->data['start_date']);
            $end_date = isset($this->data['end_date']) && $this->data['end_date']
                ? Carbon::parse($this->data['end_date'])
                : $start_date->copy();
            $irrigation = request()->route('irrigation');
            $farm_id = request()->route('farm')->id ?? $irrigation->farm_id;

            $irrigationExistsQuery = Irrigation::where('farm_id', $farm_id)
                // Check for date range overlap
                // Two date ranges overlap if: start1 <= (end2 ?? start2) AND (end1 ?? start1) >= start2
                ->where(function ($query) use ($start_date, $end_date) {
                    $query->where(function ($q) use ($start_date, $end_date) {
                        // Existing irrigation's start_date <= new irrigation's end_date (or start_date if end_date is null)
                        $q->whereDate('start_date', '<=', $end_date->format('Y-m-d'))
                            // AND existing irrigation's end_date (or start_date if null) >= new irrigation's start_date
                            ->where(function ($subQuery) use ($start_date, $end_date) {
                                $subQuery->where(function ($dateQuery) use ($start_date) {
                                    // If existing irrigation has end_date, check end_date >= new start_date
                                    $dateQuery->whereNotNull('end_date')
                                        ->whereDate('end_date', '>=', $start_date->format('Y-m-d'));
                                })
                                ->orWhere(function ($dateQuery) use ($start_date, $end_date) {
                                    // If existing irrigation has no end_date (single day), check it falls within new irrigation's range
                                    $dateQuery->whereNull('end_date')
                                        ->whereDate('start_date', '>=', $start_date->format('Y-m-d'))
                                        ->whereDate('start_date', '<=', $end_date->format('Y-m-d'));
                                });
                            });
                    });
                })
                // Check for time overlap
                ->where('start_time', '<', $end_time)
                ->where('end_time', '>', $start_time)
                ->whereHas('valves', fn($query) => $query->whereIn('valves.id', $valves));

            if ($irrigation) {
                $irrigationExistsQuery->where('id', '!=', $irrigation->id);
            }

            if ($irrigationExistsQuery->exists()) {
                $fail(__("زمان انتخاب شده با برنامه آبیاری دیگری برای این سرویس تداخل دارد."));
            }
        } catch (\Exception $e) {
            $fail(__("An error occurred while validating the irrigation report: " . $e->getMessage()));
        }
    }
}
