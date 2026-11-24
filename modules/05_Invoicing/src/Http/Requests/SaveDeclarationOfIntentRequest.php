<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Invoicing\Models\DeclarationOfIntent;

final class SaveDeclarationOfIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The protocol is the receipt number with its progressive, separated by - or / (Provvedimento AdE 293390/2021).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $id = $this->route('id');

        return [
            'contact_id' => ['required', 'uuid', Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'direction' => ['nullable', Rule::in([DeclarationOfIntent::RECEIVED, DeclarationOfIntent::ISSUED])],
            'protocol_number' => [
                'required', 'string', 'regex:/^\d{17}[-\/]\d{6}$/',
                Rule::unique('declarations_of_intent', 'protocol_number')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('direction', $this->input('direction', DeclarationOfIntent::RECEIVED)))
                    ->ignore($id),
            ],
            'receipt_date' => ['required', 'date'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'plafond_amount' => ['required', 'numeric', 'gt:0'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['required', 'date', 'after_or_equal:valid_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
