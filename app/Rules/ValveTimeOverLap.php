<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class ValveTimeOverLap implements ValidationRule, DataAwareRule
{

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
        $valves = $this->data['valves'];
        $start_time = $this->data['start_time'];
        $end_time = $this->data['end_time'];
        $irrigation = request()->route('irrigation');

        $irrigationExists = DB::table('irrigations')
            ->where('date', $this->data['date'])
            ->where('start_time', '<', $end_time)
            ->where('end_time', '>', $start_time)
            ->where(function ($query) use ($valves) {
                foreach ($valves as $valve) {
                    $query->orWhereJsonContains('valves', $valve);
                }
            });

        if ($irrigation) {
            $irrigationExists->where('id', '!=', $irrigation->id);
        }

        $irrigationExists = $irrigationExists->exists();

        if ($irrigationExists) {
            $fail(__("An irrigation report has already been stored for these valves withing this time range."));
        }
    }
}
