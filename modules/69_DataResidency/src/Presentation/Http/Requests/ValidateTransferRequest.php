<?php

declare(strict_types=1);

namespace Modules\DataResidency\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ValidateTransferRequest extends FormRequest
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
            'legal_entity_id' => ['nullable', 'uuid'],
            'purpose' => ['required', 'string', 'max:64'],
            'target' => ['required', 'string', 'max:2048'],
            'destination_country_iso2' => ['nullable', 'string', 'size:2', 'alpha'],
        ];
    }
}
