<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The customers to verify in the background: the selected ones, or every customer of the company when none is listed.
 */
final class BulkVerifyCustomersRequest extends FormRequest
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
            'customer_ids' => ['sometimes', 'array', 'max:5000'],
            'customer_ids.*' => ['uuid', Rule::exists('contacts', 'id')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')],
            'force_refresh' => ['sometimes', 'boolean'],
        ];
    }
}
