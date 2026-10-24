<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MatchImportLineRequest extends FormRequest
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
            'invoice_id' => ['required', 'uuid', Rule::exists('invoices', 'id')->where('tenant_id', app(TenantContext::class)->getTenantId())],
        ];
    }
}
