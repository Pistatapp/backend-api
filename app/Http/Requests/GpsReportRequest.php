<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GpsReportRequest extends FormRequest
{
    /** @var array<int, array<string,mixed>> */
    private array $gpsIngressItems = [];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rawPayload = (string) $this->getContent();
        $decodedPayload = json_decode(str_replace('\\:', ':', $rawPayload), true);
        if (! is_array($decodedPayload) && $rawPayload === '') {
            $decodedPayload = $this->all();
        }

        $this->gpsIngressItems = app(\App\Services\GpsIngressPayloadDecoder::class)
            ->decode($rawPayload, $decodedPayload);

        $validItems = array_values(array_filter(
            array_map(static fn (array $item) => $item['data'] ?? null, $this->gpsIngressItems),
            static fn (mixed $item): bool => is_array($item) && $item !== []
        ));

        // Keep only the normalized valid items in the existing request field.
        // The original per-item results remain available to the controller for
        // quarantine and do not alter the public request/response contract.
        $this->merge([
            'data' => $validItems,
            '_gps_ingress_items' => $this->gpsIngressItems,
        ]);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'data' => 'required|array',
        ];
    }

    /**
     * Item validation is intentionally performed after decoding so one bad
     * frame cannot reject an otherwise valid batch.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function itemRules(): array
    {
        return [
            'imei' => 'required|string|max:20',
            'coordinate' => 'required|array|size:2',
            'coordinate.0' => 'required|numeric|between:-90,90',
            'coordinate.1' => 'required|numeric|between:-180,180',
            'date_time' => 'required|date',
            'speed' => 'required|integer|min:0',
            'status' => 'required|integer|in:0,1',
            'directions' => 'required|array',
            'directions.ew' => 'required|integer',
            'directions.ns' => 'required|integer',
        ];
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function gpsIngressItems(): array
    {
        return $this->gpsIngressItems;
    }
}
