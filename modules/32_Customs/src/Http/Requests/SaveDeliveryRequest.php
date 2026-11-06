<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveDeliveryRequest extends FormRequest
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
            'delivery_note_id' => ['sometimes', 'nullable', 'uuid', CustomsRules::exists('delivery_notes')],
            'packages' => ['required', 'integer', 'min:1'],
            'gross_weight_kg' => ['sometimes', 'numeric', 'min:0'],
            'delivered_on' => ['sometimes', 'nullable', 'date'],
            'recipient' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
