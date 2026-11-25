<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Convention and period of a consolidation: a month (YYYY-MM) or an explicit date range (a quarter, or any open period).
 */
final class ConsolidationRequest extends FormRequest
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
            'convention_id' => ['required', 'uuid', Rule::exists('sales_partner_conventions', 'id')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')],
            'period' => ['required_without:from', 'nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'from' => ['required_without:period', 'nullable', 'date'],
            'to' => ['required_without:period', 'nullable', 'date', 'after_or_equal:from'],
            'anonymize' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function bounds(): array
    {
        if ($this->filled('period')) {
            $start = CarbonImmutable::createFromFormat('Y-m-d', $this->input('period').'-01')->startOfDay();

            return [$start, $start->endOfMonth()->startOfDay()];
        }

        return [CarbonImmutable::parse((string) $this->input('from'))->startOfDay(), CarbonImmutable::parse((string) $this->input('to'))->startOfDay()];
    }
}
