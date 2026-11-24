<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Presentation\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\KpiDashboards\Domain\Enums\ComparisonMode;
use Modules\KpiDashboards\Domain\ValueObjects\CockpitFilter;

/**
 * The global filter bar shared by the cockpit and the drill-down: period (preset or custom range), comparison, legal
 * entities, cost centers, display currency and the consolidation toggle.
 */
abstract class CockpitFiltersRequest extends FormRequest
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
            'preset' => ['nullable', Rule::in(['mtd', 'qtd', 'ytd', 'last_12m', 'custom'])],
            'from' => ['nullable', 'date', 'required_if:preset,custom'],
            'to' => ['nullable', 'date', 'required_if:preset,custom', 'after_or_equal:from'],
            'comparison' => ['nullable', Rule::enum(ComparisonMode::class)],
            'company_ids' => ['nullable', 'array', 'max:50'],
            'company_ids.*' => ['uuid'],
            'cost_center_ids' => ['nullable', 'array', 'max:100'],
            'cost_center_ids.*' => ['uuid'],
            'currency' => ['nullable', Rule::in(['CHF', 'EUR'])],
            'include_intercompany_eliminations' => ['nullable', 'boolean'],
        ];
    }

    public function toFilter(string $tenantId): CockpitFilter
    {
        $today = CarbonImmutable::today();
        $preset = (string) $this->input('preset', $this->filled('from') ? 'custom' : 'ytd');
        [$from, $to] = match ($preset) {
            'mtd' => [$today->startOfMonth(), $today],
            'qtd' => [$today->startOfQuarter(), $today],
            'last_12m' => [$today->subYear()->addDay(), $today],
            'custom' => [CarbonImmutable::parse((string) $this->input('from')), CarbonImmutable::parse((string) $this->input('to'))],
            default => [$today->startOfYear(), $today],
        };
        $accounting = TenantAccountingCurrency::of($tenantId);

        return new CockpitFilter(
            $tenantId,
            $from->startOfDay(),
            $to->startOfDay(),
            ComparisonMode::tryFrom((string) $this->input('comparison', 'yoy')) ?? ComparisonMode::Yoy,
            array_values(array_map('strval', (array) $this->input('company_ids', []))),
            array_values(array_map('strval', (array) $this->input('cost_center_ids', []))),
            (string) $this->input('currency', in_array($accounting, ['CHF', 'EUR'], true) ? $accounting : 'CHF'),
            $this->boolean('include_intercompany_eliminations'),
        );
    }
}
