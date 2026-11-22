<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\CostCenter;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Projects\Contracts\ProjectEvmAlertGatewayInterface;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBudget;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Models\ProjectCostLedgerEntry;
use Modules\Projects\Models\ProjectEvmAlertState;
use Modules\Projects\Models\ProjectFinancialSnapshot;
use Modules\Projects\Models\ProjectTask;
use Modules\Projects\Support\CostSourceType;

require_once __DIR__.'/ProjectsSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    projectsSetUp($this);

    // Stand-in for the Notification & Alert Engine (Mod 008) that records every delivered alert.
    $this->alerts = new class implements ProjectEvmAlertGatewayInterface
    {
        /** @var list<array<string, mixed>> */
        public array $sent = [];

        public function notify(Project $project, array $breach): int
        {
            $this->sent[] = $breach;

            return 1;
        }
    };
    app()->instance(ProjectEvmAlertGatewayInterface::class, $this->alerts);
});
afterEach(fn () => app(TenantContext::class)->clear());

/**
 * A project of 100,000 budget with two equal work packages: the first is half done and planned in the past, the second
 * is not started and planned in the future. The first carries 30,000 of approved labour.
 *
 * @return array{project: Project, first: ProjectTask, second: ProjectTask}
 */
function evmScenario(object $test, string $cost = '30000.00'): array
{
    $project = projectsProject($test);
    ProjectBudget::factory()->approved()->create(['tenant_id' => $test->tenant->id, 'project_id' => $project->id, 'total_cost_budget' => '100000.00']);

    $task = fn (string $number, string $start, string $end, string $progress, ?string $parent = null): ProjectTask => ProjectTask::create([
        'tenant_id' => $test->tenant->id, 'company_id' => $test->tenant->id, 'project_id' => $project->id, 'parent_task_id' => $parent,
        'task_number' => $number, 'wbs_code' => $number, 'title' => 'Package '.$number, 'status' => 'in_progress', 'priority' => 'medium',
        'planned_start_date' => $start, 'planned_end_date' => $end, 'estimated_hours' => 100, 'progress_percentage' => $progress,
    ]);

    $first = $task('1.1', now()->subMonths(3)->toDateString(), now()->subMonth()->toDateString(), '50');
    $second = $task('1.2', now()->addMonth()->toDateString(), now()->addMonths(2)->toDateString(), '0');
    evmCost($test, $project, $first, $cost);

    return ['project' => $project, 'first' => $first, 'second' => $second];
}

function evmCost(object $test, Project $project, ?ProjectTask $task, string $amount, string $category = 'labour'): ProjectCostEntry
{
    return ProjectCostEntry::factory()->create([
        'tenant_id' => $test->tenant->id, 'company_id' => $test->tenant->id, 'project_id' => $project->id, 'project_task_id' => $task?->id,
        'cost_category' => $category, 'cost_source_type' => 'manual_entry', 'cost_source_id' => (string) Str::uuid7(),
        'quantity' => 1, 'unit_cost' => $amount, 'total_cost' => $amount, 'approval_status' => 'approved', 'incurred_date' => now()->toDateString(),
    ]);
}

test('computing a snapshot stores the project and WBS figures with the PMI formulas', function (): void {
    ['project' => $project, 'first' => $first] = evmScenario($this);

    $data = $this->postJson("/api/v1/projects/{$project->id}/evm/compute")->assertOk()
        ->assertJsonPath('data.budget_at_completion_bac', '100000.0000')
        ->assertJsonPath('data.planned_value_pv', '50000.0000')
        ->assertJsonPath('data.earned_value_ev', '25000.0000')
        ->assertJsonPath('data.actual_cost_ac', '30000.0000')
        ->assertJsonPath('data.cost_variance_cv', '-5000.0000')
        ->assertJsonPath('data.schedule_variance_sv', '-25000.0000')
        ->assertJsonPath('data.cost_performance_index_cpi', '0.8333')
        ->assertJsonPath('data.schedule_performance_index_spi', '0.5000')
        ->assertJsonPath('data.estimate_at_completion_eac', '120000.0000')
        ->assertJsonPath('data.estimate_to_complete_etc', '90000.0000')
        ->assertJsonPath('data.variance_at_completion_vac', '-20000.0000')
        ->assertJsonPath('data.budget_absorption_percent', '30.00')
        ->assertJsonPath('data.cpi_light', 'red')
        ->json('data');

    expect($data['wbs_node_id'])->toBeNull();

    $node = ProjectFinancialSnapshot::query()->where('project_id', $project->id)->where('wbs_node_id', $first->id)->firstOrFail();
    expect((string) $node->budget_at_completion_bac)->toBe('50000.0000')
        ->and((string) $node->earned_value_ev)->toBe('25000.0000')
        ->and((string) $node->actual_cost_ac)->toBe('30000.0000')
        ->and((string) $node->cost_performance_index_cpi)->toBe('0.8333')
        ->and(ProjectFinancialSnapshot::query()->where('project_id', $project->id)->count())->toBe(3);
});

test('the ledger takes the approved costs by source and is idempotent', function (): void {
    ['project' => $project, 'first' => $first] = evmScenario($this);
    evmCost($this, $project, $first, '1200.00', 'expense');
    evmCost($this, $project, null, '800.00', 'material');
    ProjectCostEntry::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->tenant->id, 'project_id' => $project->id, 'cost_category' => 'supplier', 'total_cost' => '999.00', 'approval_status' => 'pending']);

    $this->postJson("/api/v1/projects/{$project->id}/evm/compute")->assertOk();
    $this->postJson("/api/v1/projects/{$project->id}/evm/compute")->assertOk();

    $ledger = $this->getJson("/api/v1/projects/{$project->id}/evm/ledger")->assertOk()->assertJsonPath('meta.total', 3);
    expect(collect($ledger->json('data'))->pluck('source_type')->sort()->values()->all())
        ->toBe([CostSourceType::EmployeeExpense->value, CostSourceType::TimesheetLabor->value, CostSourceType::WarehouseMaterialIssue->value]);
    expect(ProjectFinancialSnapshot::query()->where('project_id', $project->id)->whereNull('wbs_node_id')->count())->toBe(1);

    // A rejected entry leaves the ledger on the next computation.
    ProjectCostEntry::query()->where('project_id', $project->id)->where('cost_category', 'material')->update(['approval_status' => 'rejected']);
    $this->postJson("/api/v1/projects/{$project->id}/evm/compute")->assertOk()->assertJsonPath('data.actual_cost_ac', '31200.0000');
});

test('open purchase orders are committed cost that shrinks as goods are received', function (): void {
    ['project' => $project] = evmScenario($this);
    $order = PurchaseOrder::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'approved']);
    $order->forceFill(['project_id' => $project->id])->save();
    $line = PurchaseOrderLine::factory()->create(['tenant_id' => $this->tenant->id, 'purchase_order_id' => $order->id, 'quantity_ordered' => 10, 'quantity_received' => 0, 'quantity_cancelled' => 0, 'unit_price' => 100]);

    $this->postJson("/api/v1/projects/{$project->id}/evm/compute")->assertOk()->assertJsonPath('data.committed_cost', '1000.0000');

    $line->update(['quantity_received' => 4]);
    $this->postJson("/api/v1/projects/{$project->id}/evm/compute")->assertOk()->assertJsonPath('data.committed_cost', '600.0000');

    $order->update(['status' => 'closed']);
    $this->postJson("/api/v1/projects/{$project->id}/evm/compute")->assertOk()->assertJsonPath('data.committed_cost', '0.0000');
    expect(ProjectCostLedgerEntry::query()->where('project_id', $project->id)->where('source_type', CostSourceType::PurchaseCommitment->value)->count())->toBe(1);
});

test('a threshold breach is notified once, not on every snapshot, and again after it was resolved', function (): void {
    ['project' => $project, 'first' => $first] = evmScenario($this);

    $this->postJson("/api/v1/projects/{$project->id}/evm/compute")->assertOk();
    $metrics = collect($this->alerts->sent)->map(fn (array $breach): string => ($breach['wbs_node_id'] ?? 'project').':'.$breach['metric'])->sort()->values()->all();
    expect($metrics)->toBe([$first->id.':cpi', $first->id.':spi', 'project:cpi', 'project:spi']);
    expect(collect($this->alerts->sent)->firstWhere('metric', 'cpi'))->toMatchArray(['threshold' => '0.9000', 'severity' => 'warning']);

    $this->postJson("/api/v1/projects/{$project->id}/evm/compute")->assertOk();
    expect($this->alerts->sent)->toHaveCount(4);

    // The cost drops back in line: the CPI breach is resolved (the SPI one stays open) and nothing is notified.
    ProjectCostEntry::query()->where('project_id', $project->id)->update(['total_cost' => '10000.00', 'unit_cost' => '10000.00']);
    $this->postJson("/api/v1/projects/{$project->id}/evm/compute")->assertOk();
    expect(ProjectEvmAlertState::query()->where('project_id', $project->id)->where('node_key', 'project')->where('metric', 'cpi')->value('is_open'))->toBeFalse()
        ->and(ProjectEvmAlertState::query()->where('project_id', $project->id)->where('node_key', 'project')->where('metric', 'spi')->value('is_open'))->toBeTrue()
        ->and($this->alerts->sent)->toHaveCount(4);

    // The CPI breaches anew: the project and the work package notify again, the SPI ones do not repeat.
    ProjectCostEntry::query()->where('project_id', $project->id)->update(['total_cost' => '30000.00', 'unit_cost' => '30000.00']);
    $this->postJson("/api/v1/projects/{$project->id}/evm/compute")->assertOk();
    expect($this->alerts->sent)->toHaveCount(6)
        ->and(collect($this->alerts->sent)->slice(4)->pluck('metric')->unique()->all())->toBe(['cpi']);
});

test('budget absorption above 80 percent raises its own alert', function (): void {
    ['project' => $project] = evmScenario($this, '85000.00');

    $this->postJson("/api/v1/projects/{$project->id}/evm/compute")->assertOk()->assertJsonPath('data.budget_absorption_percent', '85.00');

    expect(collect($this->alerts->sent)->where('metric', 'absorption')->where('wbs_node_id', null)->first())
        ->toMatchArray(['value' => '85.00', 'threshold' => '80.0000', 'severity' => 'warning']);
});

test('a manual bottom-up ETC drives the EAC and clearing it restores the formula', function (): void {
    ['project' => $project, 'first' => $first] = evmScenario($this);

    $this->putJson("/api/v1/projects/{$project->id}/evm/etc", ['estimate_to_complete' => '60000', 'reason' => 'Supplier quote'])->assertOk()
        ->assertJsonPath('data.estimate_at_completion_eac', '90000.0000')
        ->assertJsonPath('data.estimate_to_complete_etc', '60000.0000')
        ->assertJsonPath('data.variance_at_completion_vac', '10000.0000')
        ->assertJsonPath('data.manual_etc_override', '60000.0000');

    $this->putJson("/api/v1/projects/{$project->id}/evm/etc", ['wbs_node_id' => $first->id, 'estimate_to_complete' => '10000'])->assertOk();
    $node = collect($this->getJson("/api/v1/projects/{$project->id}/evm/wbs-breakdown")->assertOk()->json('data.nodes'))->firstWhere('wbs_node_id', $first->id);
    expect($node['eac'])->toBe('40000.00')->and($node['manual_etc_override'])->toBe('10000.00');

    $this->putJson("/api/v1/projects/{$project->id}/evm/etc", ['estimate_to_complete' => null])->assertOk()
        ->assertJsonPath('data.estimate_at_completion_eac', '120000.0000')
        ->assertJsonPath('data.manual_etc_override', null);

    $this->putJson("/api/v1/projects/{$project->id}/evm/etc", ['wbs_node_id' => (string) Str::uuid7(), 'estimate_to_complete' => '1'])->assertUnprocessable()->assertJsonValidationErrors(['wbs_node_id']);
    $this->putJson("/api/v1/projects/{$project->id}/evm/etc", ['estimate_to_complete' => '-5'])->assertUnprocessable()->assertJsonValidationErrors(['estimate_to_complete']);
});

test('the WBS breakdown is hierarchical and parents add up their children', function (): void {
    ['project' => $project, 'first' => $first] = evmScenario($this);
    $parent = ProjectTask::create([
        'tenant_id' => $this->tenant->id, 'company_id' => $this->tenant->id, 'project_id' => $project->id, 'task_number' => '1', 'wbs_code' => '1', 'title' => 'Phase one', 'status' => 'in_progress', 'priority' => 'medium',
        'planned_start_date' => now()->subMonths(3)->toDateString(), 'planned_end_date' => now()->addMonths(2)->toDateString(), 'estimated_hours' => 0, 'progress_percentage' => 0,
    ]);
    ProjectTask::query()->where('project_id', $project->id)->where('id', '!=', $parent->id)->update(['parent_task_id' => $parent->id]);

    $this->postJson("/api/v1/projects/{$project->id}/evm/compute")->assertOk();
    $tree = $this->getJson("/api/v1/projects/{$project->id}/evm/wbs-breakdown")->assertOk()->assertJsonPath('data.project.bac', '100000.00')->json('data');

    expect($tree['nodes'])->toHaveCount(1)
        ->and($tree['nodes'][0]['wbs_code'])->toBe('1')
        ->and($tree['nodes'][0]['bac'])->toBe('100000.00')
        ->and($tree['nodes'][0]['actual'])->toBe('30000.00')
        ->and($tree['nodes'][0]['ev'])->toBe('25000.00')
        ->and($tree['nodes'][0]['children'])->toHaveCount(2);
    expect(collect($tree['nodes'][0]['children'])->firstWhere('wbs_node_id', $first->id)['cpi_light'])->toBe('red');
    expect($tree['reconciliation'])->toHaveKeys(['standard', 'managerial_actual_cost', 'statutory_cumulative_cost', 'is_reconciled']);
});

test('the waterfall bridges revenue through the cost sources to the gross margin', function (): void {
    ['project' => $project] = evmScenario($this);
    evmCost($this, $project, null, '2000.00', 'expense');

    $this->postJson("/api/v1/projects/{$project->id}/evm/compute")->assertOk();
    ProjectFinancialSnapshot::query()->where('project_id', $project->id)->whereNull('wbs_node_id')->update(['recognized_revenue' => '50000.0000']);

    $data = $this->getJson("/api/v1/projects/{$project->id}/evm/waterfall")->assertOk()
        ->assertJsonPath('data.recognized_revenue', '50000.00')
        ->assertJsonPath('data.total_cost', '32000.00')
        ->assertJsonPath('data.gross_margin_amount', '18000.00')
        ->assertJsonPath('data.gross_margin_percent', '36.00')
        ->assertJsonPath('data.variance_bridge.variance_at_completion', '-28000.00')
        ->json('data');

    $steps = collect($data['steps']);
    expect($steps->first()['key'])->toBe('revenue')
        ->and($steps->firstWhere('key', 'TimesheetLabor')['amount'])->toBe('-30000.00')
        ->and($steps->firstWhere('key', 'EmployeeExpense')['running_total'])->toBe('18000.00')
        ->and($steps->last()['key'])->toBe('gross_margin')
        ->and($steps->last()['amount'])->toBe('18000.00');
});

test('the S-curve accumulates PV, EV and AC over time and projects the EAC', function (): void {
    ['project' => $project] = evmScenario($this);
    $this->postJson("/api/v1/projects/{$project->id}/evm/compute", ['as_of' => now()->subMonths(2)->toDateString()])->assertOk();
    $this->postJson("/api/v1/projects/{$project->id}/evm/compute")->assertOk();

    $curve = $this->getJson('/api/v1/projects/evm/scurve?granularity=month&project_ids[]='.$project->id)->assertOk()->json('data');

    expect($curve['points'])->not->toBeEmpty()
        ->and(end($curve['points'])['ac'])->toBe('30000.00')
        ->and($curve['forecast'])->toHaveCount(2)
        ->and($curve['forecast'][1]['eac'])->toBe('120000.00');

    $this->getJson('/api/v1/projects/evm/scurve?granularity=hour')->assertUnprocessable()->assertJsonValidationErrors(['granularity']);
    expect($this->getJson('/api/v1/projects/evm/scurve')->assertOk()->json('data.points'))->not->toBeEmpty();
});

test('an overhead pool is allocated by driver, adds up to the pool and replaces its own run', function (): void {
    $costCenter = CostCenter::create(['tenant_id' => $this->tenant->id, 'code' => 'GEN', 'name' => 'General costs', 'is_active' => true]);
    $a = projectsProject($this);
    $b = projectsProject($this);
    $c = projectsProject($this);
    evmCost($this, $a, null, '3000.00');
    evmCost($this, $b, null, '1000.00');
    ProjectBudget::factory()->approved()->create(['tenant_id' => $this->tenant->id, 'project_id' => $a->id, 'total_cost_budget' => '50000.00']);

    $payload = ['run_key' => 'OH-2027-01', 'cost_center_id' => $costCenter->id, 'pool_amount' => '4000', 'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(), 'driver' => 'direct_cost', 'project_ids' => [$a->id, $b->id, $c->id]];

    $result = $this->postJson('/api/v1/projects/evm/overhead-allocations', $payload)->assertOk()
        ->assertJsonPath('data.allocated_amount', '4000.0000')->json('data');
    $byProject = collect($result['allocations'])->pluck('amount', 'project_id');
    expect($byProject->get($a->id))->toBe('3000.0000')->and($byProject->get($b->id))->toBe('1000.0000')->and($byProject->has($c->id))->toBeFalse();

    $lines = ProjectCostLedgerEntry::query()->where('source_type', CostSourceType::OverheadAllocation->value)->get();
    expect($lines)->toHaveCount(2)->and($lines->every(fn (ProjectCostLedgerEntry $line): bool => $line->cost_center_id === $costCenter->id))->toBeTrue();

    // The overhead is part of the actual cost of the project snapshot.
    expect((string) ProjectFinancialSnapshot::query()->where('project_id', $a->id)->whereNull('wbs_node_id')->value('actual_cost_ac'))->toBe('6000.0000');

    // Running the same key again replaces its lines instead of adding to them.
    $this->postJson('/api/v1/projects/evm/overhead-allocations', $payload)->assertOk();
    expect(ProjectCostLedgerEntry::query()->where('source_type', CostSourceType::OverheadAllocation->value)->count())->toBe(2);
});

test('custom weights share the pool exactly even when the shares do not divide evenly', function (): void {
    $costCenter = CostCenter::create(['tenant_id' => $this->tenant->id, 'code' => 'GEN', 'name' => 'General costs', 'is_active' => true]);
    $projects = [projectsProject($this), projectsProject($this), projectsProject($this)];

    $result = $this->postJson('/api/v1/projects/evm/overhead-allocations', [
        'run_key' => 'OH-CUSTOM', 'cost_center_id' => $costCenter->id, 'pool_amount' => '1000', 'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(),
        'driver' => 'custom_weights', 'weights' => [$projects[0]->id => 1, $projects[1]->id => 1, $projects[2]->id => 1], 'project_ids' => array_map(fn (Project $project): string => $project->id, $projects),
    ])->assertOk()->json('data');

    expect(collect($result['allocations'])->pluck('amount')->all())->toBe(['333.3333', '333.3333', '333.3334'])
        ->and($result['allocated_amount'])->toBe('1000.0000');
});

test('an allocation without any driver value is refused', function (): void {
    $costCenter = CostCenter::create(['tenant_id' => $this->tenant->id, 'code' => 'GEN', 'name' => 'General costs', 'is_active' => true]);
    projectsProject($this);

    $this->postJson('/api/v1/projects/evm/overhead-allocations', ['run_key' => 'OH-EMPTY', 'cost_center_id' => $costCenter->id, 'pool_amount' => '100', 'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(), 'driver' => 'labor_hours'])
        ->assertUnprocessable()->assertJsonValidationErrors(['driver']);
    $this->postJson('/api/v1/projects/evm/overhead-allocations', [])->assertUnprocessable()->assertJsonValidationErrors(['run_key', 'cost_center_id', 'pool_amount', 'driver']);
});

test('only managers recompute, set the ETC or allocate overhead, and tenants stay apart', function (): void {
    ['project' => $project] = evmScenario($this);
    $costCenter = CostCenter::create(['tenant_id' => $this->tenant->id, 'code' => 'GEN', 'name' => 'General costs', 'is_active' => true]);

    projectsActAs($this, $this->reader);
    $this->getJson("/api/v1/projects/{$project->id}/evm/wbs-breakdown")->assertOk();
    $this->postJson("/api/v1/projects/{$project->id}/evm/compute")->assertForbidden();
    $this->putJson("/api/v1/projects/{$project->id}/evm/etc", ['estimate_to_complete' => '1'])->assertForbidden();
    $this->postJson('/api/v1/projects/evm/overhead-allocations', ['run_key' => 'X', 'cost_center_id' => $costCenter->id, 'pool_amount' => '1', 'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(), 'driver' => 'direct_cost'])->assertForbidden();

    projectsActAs($this, $this->manager);
    $this->postJson("/api/v1/projects/{$project->id}/evm/compute")->assertOk();

    $stranger = projectsUser($this, 'owner', $this->otherTenant);
    projectsActAs($this, $stranger, $this->otherTenant);
    $this->getJson("/api/v1/projects/{$project->id}/evm/waterfall")->assertNotFound();
    $this->postJson("/api/v1/projects/{$project->id}/evm/compute")->assertNotFound();
});
