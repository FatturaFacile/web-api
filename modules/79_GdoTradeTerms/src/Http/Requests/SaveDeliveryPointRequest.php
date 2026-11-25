<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\GdoTradeTerms\Domain\Enums\DeliveryPointType;

final class SaveDeliveryPointRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'contact_id' => [$required, 'uuid'],
            'code' => [$required, 'string', 'max:40'],
            'name' => [$required, 'string', 'max:160'],
            'point_type' => ['nullable', Rule::in(DeliveryPointType::values())],
            'city' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
