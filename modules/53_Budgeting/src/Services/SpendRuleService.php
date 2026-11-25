<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Contracts\LedgerLookup;
use Modules\Budgeting\Models\BudgetAuditEvent;
use Modules\Budgeting\Models\BudgetSpendRule;

/**
 * Spend rules of a company and the choice of the one that governs a document: the active rules that cover the
 * document type, the stage and the dimensions of the spend; the most specific wins, then the highest priority, then
 * the strictest mode.
 */
final readonly class SpendRuleService
{
    private const array Strictness = [BudgetSpendRule::Allow => 0, BudgetSpendRule::Warning => 1, BudgetSpendRule::HardBlock => 2];

    public function __construct(private LedgerLookup $ledger, private AuditTrail $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data, ?string $userId): BudgetSpendRule
    {
        $this->assertDimensions($tenantId, $data);
        $rule = BudgetSpendRule::create([...$this->fields($data), 'tenant_id' => $tenantId])->refresh();
        $this->audit->record($tenantId, BudgetAuditEvent::SubjectRule, $rule->id, 'create', $userId, null, $rule->mode, null, $rule->only(['name', 'mode', 'threshold_percent']));

        return $rule;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BudgetSpendRule $rule, array $data, ?string $userId): BudgetSpendRule
    {
        $this->assertDimensions($rule->tenant_id, $data);
        $before = $rule->mode;
        $rule->update($this->fields($data));
        $this->audit->record($rule->tenant_id, BudgetAuditEvent::SubjectRule, $rule->id, 'update', $userId, $before, $rule->mode, null, $rule->only(['name', 'mode', 'threshold_percent']));

        return $rule->refresh();
    }

    public function delete(BudgetSpendRule $rule, ?string $userId): void
    {
        $this->audit->record($rule->tenant_id, BudgetAuditEvent::SubjectRule, $rule->id, 'delete', $userId, $rule->mode, null, null, $rule->only(['name']));
        $rule->delete();
    }

    public function resolve(string $tenantId, string $documentType, string $stage, ?string $accountId, ?string $costCenterId, ?string $projectId): ?BudgetSpendRule
    {
        /** @var Collection<int, BudgetSpendRule> $rules */
        $rules = BudgetSpendRule::query()->where('tenant_id', $tenantId)->where('is_active', true)->get();

        return $rules
            ->filter(static fn (BudgetSpendRule $rule): bool => in_array($documentType, $rule->document_types, true)
                && in_array($stage, $rule->stages, true)
                && ($rule->account_id === null || $rule->account_id === $accountId)
                && ($rule->cost_center_id === null || $rule->cost_center_id === $costCenterId)
                && ($rule->project_id === null || $rule->project_id === $projectId))
            ->sort(static fn (BudgetSpendRule $left, BudgetSpendRule $right): int => [$right->specificity(), $right->priority, self::Strictness[$right->mode]]
                <=> [$left->specificity(), $left->priority, self::Strictness[$left->mode]])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $fields = array_intersect_key($data, array_flip(['name', 'account_id', 'cost_center_id', 'project_id', 'mode', 'threshold_percent', 'document_types', 'stages', 'enforce_without_budget', 'priority', 'is_active']));

        foreach (['document_types', 'stages'] as $key) {
            if (isset($fields[$key])) {
                $fields[$key] = array_values(array_unique($fields[$key]));
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertDimensions(string $tenantId, array $data): void
    {
        $checks = [
            'account_id' => fn (string $id): array => $this->ledger->accounts($tenantId, [$id]),
            'cost_center_id' => fn (string $id): array => $this->ledger->costCenters($tenantId, [$id]),
            'project_id' => fn (string $id): array => $this->ledger->projects($tenantId, [$id]),
        ];

        foreach ($checks as $field => $lookup) {
            if (! empty($data[$field]) && $lookup((string) $data[$field]) === []) {
                throw ValidationException::withMessages([$field => [__('budgeting_errors.line.'.str_replace('_id', '', $field).'_missing')]]);
            }
        }
    }
}
