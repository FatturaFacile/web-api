<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Services;

use Modules\ForeignVatRefund\Domain\Exceptions\ForeignVatException;
use Modules\ForeignVatRefund\Models\CountryVatRefundRule;

/**
 * A company customises a cell of the matrix with a row of its own, which wins over the global row; the global (seeded) rows are never edited.
 */
final class RuleOverrideService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function save(string $tenantId, array $data, ?string $ruleId = null): CountryVatRefundRule
    {
        $query = CountryVatRefundRule::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at');

        if ($ruleId !== null) {
            $existing = (clone $query)->whereKey($ruleId)->first() ?? throw ForeignVatException::because('rule_not_editable', [], 403);
        } else {
            $existing = (clone $query)
                ->where('home_country_code', $data['home_country_code'])
                ->where('refund_country_code', $data['refund_country_code'])
                ->where('expense_category_code', $data['expense_category_code'])
                ->when(($data['sub_code'] ?? null) === null, fn ($builder) => $builder->whereNull('sub_code'), fn ($builder) => $builder->where('sub_code', $data['sub_code']))
                ->first();
        }

        $data['vat_rates'] = isset($data['vat_rates']) ? array_values(array_map('strval', $data['vat_rates'])) : null;

        if ($existing === null) {
            return CountryVatRefundRule::query()->create($data + ['tenant_id' => $tenantId]);
        }

        $existing->update($data);

        return $existing->refresh();
    }

    public function revert(string $tenantId, string $ruleId): void
    {
        $rule = CountryVatRefundRule::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($ruleId)->whereNull('deleted_at')->first()
            ?? throw ForeignVatException::because('rule_not_editable', [], 403);

        $rule->delete();
    }
}
