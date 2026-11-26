<?php

declare(strict_types=1);

namespace Modules\Workflow\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Workflow\Models\ApprovalMatrix;
use Modules\Workflow\Models\ApprovalMatrixTier;
use Modules\Workflow\Models\BusinessCalendar;
use Modules\Workflow\Models\BusinessCalendarHoliday;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Models\WorkflowTransition;
use Modules\Workflow\Services\HolidayCalendarFactory;

/**
 * The starting configuration of the Mod 006 approval engine for one tenant, written once and never overwritten:
 *
 * - business calendars for Switzerland and Italy with the public holidays of this and the next year (the calendar of the
 *   tenant's country is the default one);
 * - the purchase order matrix: up to 5'000 the department head, above 5'000 also the CFO, above 50'000 also the CEO;
 * - the expense report matrix: below 50 automatic approval (audited with the `system` actor), otherwise the reporting
 *   manager, above 2'000 also the CFO, plus the workflow that uses it.
 *
 * Thresholds are in the functional currency of the company.
 */
final class SeedApprovalEngineDefaultsAction
{
    public const string ExpenseWorkflowCode = 'EXPENSE_REPORT_APPROVAL';

    /**
     * Inject the holiday calculator.
     */
    public function __construct(private readonly HolidayCalendarFactory $holidays) {}

    /**
     * Writes what is missing.
     *
     * @return array{calendars: int, matrices: int, definitions: int}
     */
    public function execute(string $tenantId, string $country = 'CH'): array
    {
        return DB::transaction(fn (): array => [
            'calendars' => $this->calendars($tenantId, strtoupper($country)),
            'matrices' => $this->matrices($tenantId),
            'definitions' => $this->expenseDefinition($tenantId),
        ]);
    }

    private function calendars(string $tenantId, string $country): int
    {
        $created = 0;
        $year = (int) now()->year;

        foreach ([
            ['name' => 'Switzerland (Europe/Zurich)', 'timezone' => 'Europe/Zurich', 'country' => 'CH', 'canton' => 'ZH', 'patron' => null],
            ['name' => 'Italia (Europe/Rome)', 'timezone' => 'Europe/Rome', 'country' => 'IT', 'canton' => null, 'patron' => 'roma'],
        ] as $definition) {
            if (BusinessCalendar::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('name', $definition['name'])->exists()) {
                continue;
            }

            $calendar = BusinessCalendar::withoutGlobalScopes()->create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'name' => $definition['name'],
                'timezone' => $definition['timezone'],
                'is_default' => $definition['country'] === ($country === 'IT' ? 'IT' : 'CH')
                    && ! BusinessCalendar::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_default', true)->exists(),
            ]);

            foreach ([$year, $year + 1] as $y) {
                foreach ($this->holidays->holidays($definition['country'], $y, $definition['canton'], $definition['patron']) as $holiday) {
                    BusinessCalendarHoliday::withoutGlobalScopes()->create([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'business_calendar_id' => $calendar->id,
                        'holiday_date' => $holiday['date'],
                        'name' => $holiday['name'],
                        'holiday_type' => $holiday['type'],
                        'region' => $holiday['region'],
                    ]);
                }
            }

            $created++;
        }

        return $created;
    }

    private function matrices(string $tenantId): int
    {
        $created = 0;

        $created += $this->matrix($tenantId, 'MX-PURCHASE_ORDER-DEFAULT', 'Purchase order approval', PurchaseOrder::class, 'CHF', null, [
            ['level' => 1, 'type' => 'role', 'value' => 'department_manager', 'min' => null, 'inclusive' => true],
            ['level' => 2, 'type' => 'role', 'value' => 'cfo', 'min' => '5000', 'inclusive' => false],
            ['level' => 3, 'type' => 'role', 'value' => 'ceo', 'min' => '50000', 'inclusive' => false],
        ]);

        $created += $this->matrix($tenantId, 'MX-EXPENSE_REPORT-DEFAULT', 'Expense report approval', ExpenseReport::class, 'CHF', '50', [
            ['level' => 1, 'type' => 'reporting_manager', 'value' => '', 'min' => null, 'inclusive' => true],
            ['level' => 2, 'type' => 'role', 'value' => 'cfo', 'min' => '2000', 'inclusive' => false],
        ]);

        return $created;
    }

    /**
     * @param  list<array{level: int, type: string, value: string, min: ?string, inclusive: bool}>  $tiers
     */
    private function matrix(string $tenantId, string $code, string $name, string $target, string $currency, ?string $autoApproveBelow, array $tiers): int
    {
        if (ApprovalMatrix::withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
            return 0;
        }

        $matrix = ApprovalMatrix::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'target_entity_type' => $target,
            'currency' => $currency,
            'priority' => 100,
            'is_default' => true,
            'auto_approve_below' => $autoApproveBelow,
            'fallback_approver_type' => 'role',
            'fallback_approver_value' => 'admin',
            'is_active' => true,
        ]);

        foreach ($tiers as $tier) {
            ApprovalMatrixTier::withoutGlobalScopes()->create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'approval_matrix_id' => $matrix->id,
                'tier_level' => $tier['level'],
                'approver_type' => $tier['type'],
                'approver_value' => $tier['value'],
                'approval_type' => 'or_parallel',
                'threshold_min' => $tier['min'],
                'threshold_min_inclusive' => $tier['inclusive'],
            ]);
        }

        return 1;
    }

    private function expenseDefinition(string $tenantId): int
    {
        if (WorkflowDefinition::withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenantId)->where('code', self::ExpenseWorkflowCode)->exists()) {
            return 0;
        }

        $definition = WorkflowDefinition::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'code' => self::ExpenseWorkflowCode,
            'name' => 'Expense report approval',
            'description' => 'Matrix-driven approval of expense reports with automatic approval of small amounts.',
            'target_entity_type' => 'expense_report',
            'version' => 1,
            'status' => 'published',
            'is_active' => true,
            'configuration' => ['seeded_default' => true, 'sod' => ['block_self_approval' => true]],
            'published_at' => now(),
        ]);

        $node = static fn (string $key, string $label, string $type, array $extra = []): WorkflowNode => WorkflowNode::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'workflow_definition_id' => $definition->id,
            'node_key' => $key,
            'name' => $label,
            'node_type' => $type,
            ...$extra,
        ]);

        $start = $node('start', 'Start', 'start');
        $approval = $node('approval', 'Approval by matrix', 'approval', ['approval_type' => 'single', 'assignee_type' => 'matrix_sequential', 'timeout_hours' => 48]);
        $end = $node('end', 'Approved', 'end');

        foreach ([[$start, $approval, 'start'], [$approval, $end, 'approved']] as [$from, $to, $trigger]) {
            WorkflowTransition::withoutGlobalScopes()->create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'workflow_definition_id' => $definition->id,
                'from_node_id' => $from->id,
                'to_node_id' => $to->id,
                'action_trigger' => $trigger,
                'priority' => 1,
            ]);
        }

        return 1;
    }
}
