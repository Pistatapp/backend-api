<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Tractor;

class UpdateTractorReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Adjust authorization logic as needed
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'date' => 'sometimes|shamsi_date',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'operation_id' => 'sometimes|exists:operations,id',
            'field_id' => 'sometimes|exists:fields,id',
            'description' => 'nullable|string',
        ];
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'date' => jalali_to_carbon($this->date)
        ]);
    }

    /**
     * Handle a passed validation attempt.
     */
    protected function passedValidation()
    {
        $reportId = $this->route('tractor_report')->id; // Assuming 'report' is the route parameter for the current report
        $date = $this->input('date');
        $startTime = $this->input('start_time');
        $endTime = $this->input('end_time');

        $overlap = Tractor::where('id', $this->route('tractor_report')->id)
            ->where('date', $date)
            ->where('id', '!=', $reportId) // Exclude the current report
            ->where(function ($query) use ($startTime, $endTime) {
                $query->where(function ($query) use ($startTime, $endTime) {
                    $query->whereTime('start_time', '<=', $endTime)
                          ->whereTime('end_time', '>=', $startTime);
                });
            })->exists();

        if ($overlap) {
            throw new \Illuminate\Validation\ValidationException(
                'The selected time overlaps with an existing report.'
            );
        }
    }
}
