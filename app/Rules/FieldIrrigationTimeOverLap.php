<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Models\Irrigation;

class FieldIrrigationTimeOverLap implements ValidationRule, DataAwareRule
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
        $fields = $this->data['fields'];
        $start_time = $this->data['start_time'];
        $end_time = $this->data['end_time'];
        $irrigation = request()->route('irrigation');

        $irrigationExists = Irrigation::where('date', $this->data['date'])
            ->where('start_time', '<', $end_time)
            ->where('end_time', '>', $start_time)
            ->whereHas('fields', function ($query) use ($fields) {
                $query->whereIn('fields.id', $fields);
            });

        if ($irrigation) {
            $irrigationExists->where('id', '!=', $irrigation->id);
        }

        $irrigationExists = $irrigationExists->exists();

        if ($irrigationExists) {
            $fail(__("The selected field's irrigation time overlaps with another irrigation time."));
        }
    }
}
