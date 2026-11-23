<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Transport metadata printed on the custom DDT of a dropshipment.
 */
final class IssueDropshipmentDdtRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'carrier' => ['nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'packages' => ['nullable', 'integer', 'min:1'],
            'gross_weight' => ['nullable', 'numeric', 'min:0'],
            'transport_by' => ['nullable', 'string', 'max:40'],
            'shipment_date' => ['nullable', 'date'],
        ];
    }
}
