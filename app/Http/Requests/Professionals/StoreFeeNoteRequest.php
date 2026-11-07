<?php

declare(strict_types=1);

namespace App\Http\Requests\Professionals;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class StoreFeeNoteRequest extends FormRequest
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
            'legal_case_id' => ['nullable', 'uuid', $this->ofTenant('legal_cases')],
            'client_id' => ['required', 'uuid', $this->ofTenant('contacts')],
            'note_number' => ['required', 'string'],
            'date' => ['required', 'date'],
            'taxable_fees' => ['required', 'numeric', 'min:0'],
            'cpa_rate' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'ritenuta_rate' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:25'],
        ];
    }

    /**
     * A row of the active company, or a generic one without company.
     */
    private function ofTenant(string $table): Exists
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return Rule::exists($table, 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
    }
}
