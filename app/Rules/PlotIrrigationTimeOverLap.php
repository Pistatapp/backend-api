<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Models\Irrigation;
use Carbon\Carbon;

class PlotIrrigationTimeOverLap implements ValidationRule
{
    private $irrigation;
    private $plotId;

    public function __construct($irrigation = null, $plotId = null)
    {
        $this->irrigation = $irrigation;
        $this->plotId = $plotId;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $startTime = Carbon::parse($value);
        $endTime = Carbon::parse(request('end_time'));
        $date = Carbon::parse(request('date'));

        $query = Irrigation::query()
            ->whereHas('plots', function ($query) {
                $query->where('plots.id', $this->plotId);
            })
            ->whereDate('date', $date->format('Y-m-d'))
            // Check for time overlap
            ->where(function ($query) use ($startTime, $endTime) {
                $query->whereBetween('start_time', [$startTime, $endTime])
                    ->orWhereBetween('end_time', [$startTime, $endTime])
                    ->orWhere(function ($query) use ($startTime, $endTime) {
                        $query->where('start_time', '<=', $startTime)
                            ->where('end_time', '>=', $endTime);
                    });
            });

        if ($this->irrigation) {
            $query->where('id', '!=', $this->irrigation->id);
        }

        if ($query->exists()) {
            $fail('زمان انتخاب شده با برنامه آبیاری دیگری برای این قطعه زمین تداخل دارد.');
        }
    }
}
