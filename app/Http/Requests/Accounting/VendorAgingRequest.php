<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reference date (default today) and optional supplier of the vendor aging report.
 */
class VendorAgingRequest extends FormRequest
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
            'as_of_date' => ['sometimes', 'date_format:Y-m-d'],
            'supplier_id' => ['sometimes', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', app(TenantContext::class)->getTenantId())],
        ];
    }

    public function asOf(): CarbonImmutable
    {
        return $this->has('as_of_date') ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('as_of_date')) : CarbonImmutable::today();
    }

    public function supplierId(): ?string
    {
        return $this->input('supplier_id');
    }
}
