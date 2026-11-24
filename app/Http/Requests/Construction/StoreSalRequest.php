<?php

declare(strict_types=1);

namespace App\Http\Requests\Construction;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class StoreSalRequest extends FormRequest
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
            'construction_site_id' => ['required', 'uuid', $this->ofTenant('construction_sites')],
            'sal_number' => ['required', 'string'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date'],
            'gross_certified_amount' => ['required', 'numeric', 'min:0'],
            'retention_percentage' => ['nullable', 'numeric', 'min:0', 'max:50'],
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
