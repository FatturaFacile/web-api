<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests\Receivables;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Invoicing\Services\Receivables\AgingBucketPolicy;

/**
 * Provision rates (percent) by aging bucket key; only keys of the buckets of the company are accepted.
 */
final class SaveProvisionRatesRequest extends FormRequest
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
        $rules = ['rates' => ['required', 'array', 'min:1']];
        $tenantId = (string) app(TenantContext::class)->getTenantId();

        foreach (AgingBucketPolicy::forTenant($tenantId)->keys() as $key) {
            $rules['rates.'.$key] = ['sometimes', 'numeric', 'between:0,100'];
        }

        return $rules;
    }
}
