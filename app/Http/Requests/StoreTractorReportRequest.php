<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\TractorReport;

class StoreTractorReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', TractorReport::class);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'date' => 'required|shamsi_date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'operation_id' => 'required|exists:operations,id',
            'field_id' => 'required|exists:fields,id',
            'description' => 'nullable|string',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'date' => jalali_to_carbon($this->date),
        ]);
    }

    /**
     * Handle a passed validation attempt.
     */
    protected function passedValidation()
    {
        $tractorId = $this->route('tractor')->id;
        $date = $this->input('date');
        $startTime = $this->input('start_time');
        $endTime = $this->input('end_time');

        $overlap = TractorReport::where('tractor_id', $tractorId)
            ->where('date', $date)
            ->where(function ($query) use ($startTime, $endTime) {
                $query->where(function ($query) use ($startTime, $endTime) {
                    $query->whereTime('start_time', '<=', $endTime)
                          ->whereTime('end_time', '>=', $startTime);
                });
            })->exists();

        if ($overlap) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'start_time' => __('The selected time overlaps with another report for the same tractor on the same date.'),
                'end_time' => __('The selected time overlaps with another report for the same tractor on the same date.'),
            ]);
        }
    }
}
