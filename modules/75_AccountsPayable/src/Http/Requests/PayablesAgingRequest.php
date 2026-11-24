<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Parameters of the payables aging report and of its exports.
 */
final class PayablesAgingRequest extends FormRequest
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
            'as_of' => ['nullable', 'date'],
            'supplier_id' => ['nullable', 'uuid'],
            'currency' => ['nullable', 'string', 'size:3'],
            'detail' => ['nullable', 'boolean'],
            'locale' => ['nullable', 'string', 'in:it,de,fr,en'],
        ];
    }

    public function asOf(): CarbonImmutable
    {
        return $this->filled('as_of') ? CarbonImmutable::parse((string) $this->input('as_of'))->startOfDay() : CarbonImmutable::today();
    }

    public function supplierId(): ?string
    {
        return $this->filled('supplier_id') ? (string) $this->input('supplier_id') : null;
    }

    public function currency(): ?string
    {
        return $this->filled('currency') ? strtoupper((string) $this->input('currency')) : null;
    }
}
