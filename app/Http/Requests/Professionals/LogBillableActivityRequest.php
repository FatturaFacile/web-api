<?php

declare(strict_types=1);

namespace App\Http\Requests\Professionals;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class LogBillableActivityRequest extends FormRequest
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
            'legal_case_id' => ['required', 'uuid', $this->ofTenant('legal_cases')],
            'date' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'description' => ['required', 'string'],
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
