<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Workflow\Models\ApprovalMatrix;
use Modules\Workflow\Models\ApprovalMatrixRule;
use Modules\Workflow\Models\ApprovalMatrixTier;
use Modules\Workflow\Services\ApprovalMatrixEvaluatorService;

uses(DatabaseTransactions::class);

/**
 * Creates a matrix with its tiers: each tier is [level, type, value, min, max, minInclusive].
 *
 * @param  list<array{0: int, 1: string, 2: string, 3: ?string, 4: ?string, 5: bool}>  $tiers
 * @param  array<string, mixed>  $attributes
 */
function approvalMatrix(Tenant $tenant, string $code, array $tiers, array $attributes = []): ApprovalMatrix
{
    $matrix = ApprovalMatrix::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'code' => $code,
        'name' => $code,
        'target_entity_type' => 'purchase_order',
        'priority' => 100,
        'is_active' => true,
        ...$attributes,
    ]);

    foreach ($tiers as [$level, $type, $value, $min, $max, $inclusive]) {
        ApprovalMatrixTier::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'approval_matrix_id' => $matrix->id,
            'tier_level' => $level,
            'approver_type' => $type,
            'approver_value' => $value,
            'approval_type' => 'or_parallel',
            'threshold_min' => $min,
            'threshold_max' => $max,
            'threshold_min_inclusive' => $inclusive,
        ]);
    }

    return $matrix;
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['id' => (string) Str::uuid7(), 'name' => 'Matrix AG', 'legal_name' => 'Matrix AG', 'status' => 'active']);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->evaluator = app(ApprovalMatrixEvaluatorService::class);

    // Purchase orders: up to 5'000 the department head, above 5'000 also the CFO, above 50'000 also the CEO.
    $this->standard = fn (array $attributes = []): ApprovalMatrix => approvalMatrix($this->tenant, 'PO-STD', [
        [1, 'role', 'department_manager', null, null, true],
        [2, 'role', 'cfo', '5000', null, false],
        [3, 'role', 'ceo', '50000', null, false],
    ], $attributes);

    $this->levelsFor = fn (string $amount, array $extra = []): array => array_column(
        $this->evaluator->simulate((string) $this->tenant->id, 'purchase_order', ['amount' => $amount, ...$extra])['tiers'],
        'approver_value'
    );
});

test('tiers are cumulative with exclusive lower bounds at the thresholds', function (): void {
    ($this->standard)();

    expect(($this->levelsFor)('100'))->toBe(['department_manager'])
        ->and(($this->levelsFor)('5000'))->toBe(['department_manager'])
        ->and(($this->levelsFor)('5000.01'))->toBe(['department_manager', 'cfo'])
        ->and(($this->levelsFor)('50000'))->toBe(['department_manager', 'cfo'])
        ->and(($this->levelsFor)('50000.01'))->toBe(['department_manager', 'cfo', 'ceo']);
});

test('an inclusive lower bound applies at the threshold and the upper bound is always inclusive', function (): void {
    approvalMatrix($this->tenant, 'RANGES', [
        [1, 'role', 'low', null, '1000', true],
        [1, 'role', 'mid', '1000', '2000', true],
        [1, 'role', 'high', '2000', null, false],
    ]);

    expect(($this->levelsFor)('1000'))->toBe(['low', 'mid'])
        ->and(($this->levelsFor)('2000'))->toBe(['mid'])
        ->and(($this->levelsFor)('2000.01'))->toBe(['high']);
});

test('the first matching matrix in priority order wins and conditions must all hold', function (): void {
    $generic = ($this->standard)(['priority' => 200]);
    $special = approvalMatrix($this->tenant, 'PO-CC', [[1, 'role', 'cc_owner', null, null, true]], ['priority' => 50]);
    ApprovalMatrixRule::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'approval_matrix_id' => $special->id, 'field_name' => 'cost_center_id', 'operator' => '=', 'value_string' => 'CC-1']);

    $withCostCentre = $this->evaluator->simulate((string) $this->tenant->id, 'purchase_order', ['amount' => '100', 'cost_center_id' => 'CC-1']);
    $without = $this->evaluator->simulate((string) $this->tenant->id, 'purchase_order', ['amount' => '100', 'cost_center_id' => 'CC-2']);

    expect($withCostCentre['matrix']->id)->toBe($special->id)
        ->and($without['matrix']->id)->toBe($generic->id)
        ->and($without['used_default'])->toBeFalse();
});

test('operators in, not_in and between select the matrix', function (): void {
    $matrix = approvalMatrix($this->tenant, 'CATEGORY', [[1, 'role', 'buyer', null, null, true]]);
    ApprovalMatrixRule::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'approval_matrix_id' => $matrix->id, 'field_name' => 'category', 'operator' => 'in', 'value_json' => ['it', 'furniture']]);
    ApprovalMatrixRule::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'approval_matrix_id' => $matrix->id, 'field_name' => 'discount_percent', 'operator' => 'between', 'value_json' => [0, 10]]);

    $run = fn (array $attributes): ?string => $this->evaluator->simulate((string) $this->tenant->id, 'purchase_order', ['amount' => '10', ...$attributes])['matrix']?->id;

    expect($run(['category' => 'it', 'discount_percent' => 5]))->toBe($matrix->id)
        ->and($run(['category' => 'food', 'discount_percent' => 5]))->toBeNull()
        ->and($run(['category' => 'it', 'discount_percent' => 15]))->toBeNull();
});

test('the explicit default rule applies when nothing else matches and a fallback approver covers a gap', function (): void {
    $special = approvalMatrix($this->tenant, 'ONLY-IT', [[1, 'role', 'it_head', null, null, true]]);
    ApprovalMatrixRule::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'approval_matrix_id' => $special->id, 'field_name' => 'category', 'operator' => '=', 'value_string' => 'it']);
    $default = approvalMatrix($this->tenant, 'DEFAULT', [[1, 'role', 'cfo', null, '10000', true]], ['is_default' => true, 'fallback_approver_type' => 'role', 'fallback_approver_value' => 'admin']);

    $general = $this->evaluator->simulate((string) $this->tenant->id, 'purchase_order', ['amount' => '500', 'category' => 'food']);
    $gap = $this->evaluator->simulate((string) $this->tenant->id, 'purchase_order', ['amount' => '99000', 'category' => 'food']);

    expect($general['matrix']->id)->toBe($default->id)->and($general['used_default'])->toBeTrue()->and($general['used_fallback'])->toBeFalse()
        ->and($gap['used_fallback'])->toBeTrue()->and($gap['tiers'][0]['approver_value'])->toBe('admin')->and($gap['blocked'])->toBeFalse();
});

test('without a rule, a default or a fallback the result is blocked, never an implicit approval', function (): void {
    approvalMatrix($this->tenant, 'RANGE-ONLY', [[1, 'role', 'cfo', '1000', '2000', true]]);

    $result = $this->evaluator->simulate((string) $this->tenant->id, 'purchase_order', ['amount' => '10']);

    expect($result['tiers'])->toBe([])->and($result['blocked'])->toBeTrue()->and($result['auto_approved'])->toBeFalse();
});

test('auto approval applies only strictly below the configured threshold', function (): void {
    approvalMatrix($this->tenant, 'EXP', [[1, 'reporting_manager', '', null, null, true]], ['auto_approve_below' => '50', 'target_entity_type' => 'expense_report']);

    $at = fn (string $amount): array => $this->evaluator->simulate((string) $this->tenant->id, 'expense_report', ['amount' => $amount]);

    expect($at('49.99')['auto_approved'])->toBeTrue()
        ->and($at('50')['auto_approved'])->toBeFalse()
        ->and($at('50')['tiers'][0]['approver_type'])->toBe('reporting_manager');
});

test('a foreign currency document is compared in the functional currency', function (): void {
    ($this->standard)();

    // 4'800 in a currency worth 1.25 functional units is 6'000: above the CFO threshold.
    expect(($this->levelsFor)('4800', ['amount_functional' => 6000]))->toBe(['department_manager', 'cfo']);
});

test('matrices of another tenant and inactive matrices are ignored', function (): void {
    $other = Tenant::create(['id' => (string) Str::uuid7(), 'name' => 'Other AG', 'legal_name' => 'Other AG', 'status' => 'active']);
    approvalMatrix($other, 'FOREIGN', [[1, 'role', 'spy', null, null, true]]);
    ($this->standard)(['is_active' => false]);

    expect($this->evaluator->simulate((string) $this->tenant->id, 'purchase_order', ['amount' => '10'])['tiers'])->toBe([]);
});
