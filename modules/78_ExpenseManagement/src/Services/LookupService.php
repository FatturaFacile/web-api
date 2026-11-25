<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use App\Models\Tenant;
use App\Models\User;
use Modules\ExpenseManagement\Domain\Enums\AllowanceMethod;
use Modules\ExpenseManagement\Domain\Enums\CalculationSource;
use Modules\ExpenseManagement\Domain\Enums\DestinationType;
use Modules\ExpenseManagement\Domain\Enums\PaymentMethod;
use Modules\ExpenseManagement\Domain\Enums\ReconciliationStatus;
use Modules\ExpenseManagement\Domain\Enums\ReportStatus;
use Modules\ExpenseManagement\Models\ExpenseCategory;
use Modules\ExpenseManagement\Models\ExpensePolicy;
use Modules\ExpenseManagement\Strategies\FiscalStrategyRegistry;

/**
 * The reference data the screens need: categories, enumerations, the jurisdiction with its fiscal notices and the employees
 * a manager may file for.
 */
final class LookupService
{
    public function __construct(private readonly FiscalStrategyRegistry $strategies) {}

    /**
     * @return array<string, mixed>
     */
    public function lookups(string $tenantId, string $userId, bool $manager): array
    {
        $tenant = Tenant::query()->find($tenantId);
        $jurisdiction = FiscalStrategyRegistry::jurisdictionOfCountry($tenant?->country);

        return [
            'categories' => $this->categories(),
            'statuses' => ReportStatus::values(),
            'payment_methods' => PaymentMethod::values(),
            'calculation_sources' => CalculationSource::values(),
            'destination_types' => DestinationType::values(),
            'allowance_methods' => AllowanceMethod::values(),
            'reconciliation_statuses' => ReconciliationStatus::values(),
            'jurisdiction' => $jurisdiction,
            'fiscal_notice_codes' => $this->strategies->for($jurisdiction)->noticeCodes(),
            'base_currency' => $tenant->default_currency ?? 'EUR',
            'employees' => $this->employees($tenantId, $userId, $manager),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function categories(): array
    {
        $byCode = [];

        // Tenant rows come first, so a tenant category overrides the global one with the same code.
        foreach (ExpenseCategory::query()->where('is_active', true)->orderByRaw('CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END')->orderBy('sort_order')->get() as $category) {
            $byCode[$category->code] ??= $category;
        }

        return array_values(array_map(static fn (ExpenseCategory $category): array => [
            'id' => $category->id,
            'code' => $category->code,
            'name' => $category->name,
            'name_key' => $category->nameKey(),
            'is_meal' => $category->is_meal,
            'is_lodging' => $category->is_lodging,
            'is_transport' => $category->is_transport,
            'is_mileage' => $category->is_mileage,
            'vat_recoverable' => $category->vat_recoverable,
            'requires_receipt' => $category->requires_receipt,
        ], $byCode));
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function employees(string $tenantId, string $userId, bool $manager): array
    {
        $users = User::query()->select(['id', 'name'])->orderBy('name');

        if ($manager) {
            $users->whereHas('tenants', fn ($tenants) => $tenants->where('tenants.id', $tenantId));
        } else {
            $users->whereKey($userId);
        }

        return $users->get()->map(static fn (User $user): array => ['id' => (string) $user->id, 'name' => (string) $user->name])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function policies(): array
    {
        return ExpensePolicy::query()->with('rules')->orderBy('jurisdiction')->orderBy('code')->get()->map(static fn (ExpensePolicy $policy): array => [
            'id' => $policy->id,
            'code' => $policy->code,
            'name' => $policy->name,
            'jurisdiction' => $policy->jurisdiction,
            'is_active' => $policy->is_active,
            'duplicate_detection' => $policy->duplicate_detection,
            'block_non_traceable' => $policy->block_non_traceable,
            'receipt_threshold' => $policy->receipt_threshold,
            'mileage_rate_per_km' => $policy->mileage_rate_per_km,
            'per_diem_daily_limit' => $policy->per_diem_daily_limit,
            'rules' => $policy->rules->map(static fn ($rule): array => [
                'id' => $rule->id,
                'category_code' => $rule->category_code,
                'rule_type' => $rule->rule_type,
                'limit_amount' => $rule->limit_amount,
                'severity' => $rule->severity,
            ])->all(),
        ])->all();
    }
}
