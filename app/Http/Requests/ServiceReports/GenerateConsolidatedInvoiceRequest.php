<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceReports;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateConsolidatedInvoiceRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId()
            ?? $this->user()?->tenant_id
            ?? $this->header('X-Tenant-ID');

        return [
            'report_ids' => ['required', 'array', 'min:2'],
            'report_ids.*' => [
                'required',
                'uuid',
                Rule::exists('service_reports', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }
}
