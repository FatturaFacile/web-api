<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Customs\Enums\DossierStatus;

final class SaveDossierRequest extends FormRequest
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
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'customer_contact_id' => [$required, 'uuid', CustomsRules::exists('contacts')],
            'service_type' => [$required, 'string', 'max:40'],
            'direction' => [$required, ...CustomsRules::direction()],
            'currency' => [$required, 'string', 'size:3'],
            'status' => [$this->isMethod('post') ? 'prohibited' : 'sometimes', Rule::enum(DossierStatus::class)],
            'origin_country' => ['sometimes', 'nullable', ...CustomsRules::country()],
            'destination_country' => ['sometimes', 'nullable', ...CustomsRules::country()],
            'incoterms' => ['sometimes', 'nullable', 'string', 'size:3'],
            'gross_weight_kg' => ['sometimes', 'numeric', 'min:0'],
            'volume_m3' => ['sometimes', 'numeric', 'min:0'],
            'packages' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
