<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Support\TenantRules;

final class RecordSportCompensationRequest extends FormRequest
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
        $amount = ['sometimes', 'nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'];

        return [
            'collaborator_id' => ['required', 'uuid', TenantRules::exists('assoc_sport_collaborators')],
            'contract_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_sport_contracts')],
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'paid_on' => ['required', 'date'],
            'gross_amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/', 'not_in:0,0.0,0.00'],
            'contribution_base' => $amount,
            'contribution_amount' => $amount,
            'withholding_base' => $amount,
            'withholding_amount' => $amount,
            'hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000'],
            'payment_method' => ['sometimes', 'nullable', Rule::in(['cash', 'bank'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
