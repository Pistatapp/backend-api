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
        $startDate = Carbon::parse(request('start_date'));
        $endDate = request('end_date') ? Carbon::parse(request('end_date')) : $startDate->copy();

        $query = Irrigation::query()
            ->whereHas('plots', function ($query) {
                $query->where('plots.id', $this->plotId);
            })
            // Check for date range overlap
            // Two date ranges overlap if: start1 <= (end2 ?? start2) AND (end1 ?? start1) >= start2
            ->where(function ($query) use ($startDate, $endDate) {
                $query->where(function ($q) use ($startDate, $endDate) {
                    // Existing irrigation's start_date <= new irrigation's end_date (or start_date if end_date is null)
                    $q->whereDate('start_date', '<=', $endDate->format('Y-m-d'))
                        // AND existing irrigation's end_date (or start_date if null) >= new irrigation's start_date
                        ->where(function ($subQuery) use ($startDate, $endDate) {
                            $subQuery->where(function ($dateQuery) use ($startDate) {
                                // If existing irrigation has end_date, check end_date >= new start_date
                                $dateQuery->whereNotNull('end_date')
                                    ->whereDate('end_date', '>=', $startDate->format('Y-m-d'));
                            })
                            ->orWhere(function ($dateQuery) use ($startDate, $endDate) {
                                // If existing irrigation has no end_date (single day), check it falls within new irrigation's range
                                $dateQuery->whereNull('end_date')
                                    ->whereDate('start_date', '>=', $startDate->format('Y-m-d'))
                                    ->whereDate('start_date', '<=', $endDate->format('Y-m-d'));
                            });
                        });
                });
            })
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
