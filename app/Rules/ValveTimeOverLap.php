<?php

namespace App\Rules;

use App\Models\Irrigation;
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
            $irrigation = request()->route('irrigation');

            $irrigationExistsQuery = Irrigation::where('date', $this->data['date'])
                ->where('farm_id', $irrigation->farm_id)
                ->where('start_time', '<', $end_time)
                ->where('end_time', '>', $start_time)
                ->whereHas('valves', fn($query) => $query->whereIn('valves.id', $valves));

            if ($irrigation) {
                $irrigationExistsQuery->where('id', '!=', $irrigation->id);
            }

            if ($irrigationExistsQuery->exists()) {
                $fail(__("An irrigation report has already been stored for these valves within this time range."));
            }
        } catch (\Exception $e) {
            $fail(__("An error occurred while validating the irrigation report: " . $e->getMessage()));
        }
    }
}
