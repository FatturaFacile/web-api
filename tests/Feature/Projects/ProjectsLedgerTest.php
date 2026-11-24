<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBudget;
use Modules\Projects\Models\ProjectMilestone;

require_once __DIR__.'/ProjectsSupport.php';

uses(RefreshDatabase::class);

beforeEach(fn () => projectsSetUp($this));
afterEach(fn () => app(TenantContext::class)->clear());

function projectsBookCost(object $test, Project $project, int $amount): void
{
    $test->postJson("/api/v1/projects/{$project->id}/costs", ['cost_category' => 'material', 'cost_source_type' => 'manual_document', 'cost_source_id' => (string) Str::uuid(), 'quantity' => 1, 'unit_cost' => $amount, 'incurred_date' => now()->toDateString()])->assertCreated();
}

/**
 * Bills a completed milestone and marks the draft as issued, as Invoicing does at emission (its own ledger posting of
 * the invoice is not part of this test).
 */
function projectsIssueMilestoneInvoice(object $test, Project $project, string $amount): void
{
    $milestone = ProjectMilestone::create(['tenant_id' => $project->tenant_id, 'project_id' => $project->id, 'name' => 'SAL '.Str::random(3), 'milestone_date' => now()->toDateString(), 'billing_amount' => $amount, 'is_completed' => true, 'completed_at' => now()]);
    $invoiceId = $test->postJson("/api/v1/projects/{$project->id}/billing/milestones/{$milestone->id}", ['tax_rate' => 22])->assertCreated()->json('data.id');
    SalesInvoice::query()->whereKey($invoiceId)->update(['status' => 'posted']);
}

test('percentage of completion: costs are capitalised, runs adjust the contract asset and the closure settles it', function (): void {
    $project = projectsProject($this, ['revenue_recognition_method' => 'percentage_of_completion', 'contract_value' => '100000.00']);
    ProjectBudget::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'status' => 'approved', 'total_cost_budget' => '50000.00', 'total_revenue_budget' => '100000.00']);
    projectsActAs($this, $this->manager);

    projectsBookCost($this, $project, 20000);
    expect(projectsLedgerBalance($this->tenant->id, $project->id, '1250'))->toBe('20000.00')
        ->and(projectsLedgerBalance($this->tenant->id, $project->id, '5900'))->toBe('-20000.00');

    $first = $this->postJson("/api/v1/projects/{$project->id}/revenue-recognitions", ['accounting_period_date' => now()->subDay()->toDateString()])->assertCreated()->json('data');
    expect($first['completion_percentage'])->toBe('40.00')
        ->and($first['cumulative_recognized_revenue'])->toBe('40000.00')
        ->and($first['contract_asset_balance'])->toBe('40000.00')
        ->and($first['period_cost_of_revenue'])->toBe('20000.00')
        ->and(projectsLedgerBalance($this->tenant->id, $project->id, '1150'))->toBe('40000.00')
        ->and(projectsLedgerBalance($this->tenant->id, $project->id, '4000'))->toBe('-40000.00')
        ->and(projectsLedgerBalance($this->tenant->id, $project->id, '5000'))->toBe('20000.00')
        ->and(projectsLedgerBalance($this->tenant->id, $project->id, '1250'))->toBe('0.00');

    $this->postJson("/api/v1/projects/{$project->id}/revenue-recognitions", ['accounting_period_date' => now()->subDay()->toDateString()])->assertConflict();
    $this->postJson("/api/v1/projects/{$project->id}/revenue-recognitions", ['accounting_period_date' => now()->subDays(2)->toDateString()])->assertConflict();

    projectsIssueMilestoneInvoice($this, $project, '30000.00');
    projectsBookCost($this, $project, 5000);

    $second = $this->postJson("/api/v1/projects/{$project->id}/revenue-recognitions", ['accounting_period_date' => now()->toDateString()])->assertCreated()->json('data');
    expect($second['cumulative_recognized_revenue'])->toBe('50000.00')
        ->and($second['cumulative_billed_revenue'])->toBe('30000.00')
        ->and($second['contract_asset_balance'])->toBe('20000.00')
        ->and($second['period_contract_asset_adjustment'])->toBe('-20000.00')
        ->and(projectsLedgerBalance($this->tenant->id, $project->id, '1150'))->toBe('20000.00')
        ->and(projectsLedgerBalance($this->tenant->id, $project->id, '5000'))->toBe('25000.00')
        ->and(projectsLedgerBalance($this->tenant->id, $project->id, '1250'))->toBe('0.00');
    $this->getJson("/api/v1/projects/{$project->id}/revenue-recognitions")->assertOk()->assertJsonPath('meta.total', 2);

    $checks = $this->getJson("/api/v1/projects/{$project->id}/closure-checks")->assertOk()->json('data');
    expect(collect($checks['blockers'])->pluck('code')->all())->toBe(['unbilled_recognised_revenue']);

    projectsIssueMilestoneInvoice($this, $project, '25000.00');
    $this->postJson("/api/v1/projects/{$project->id}/close")->assertOk()->assertJsonPath('data.status', 'closed');

    expect(projectsLedgerBalance($this->tenant->id, $project->id, '1150'))->toBe('0.00')
        ->and(projectsLedgerBalance($this->tenant->id, $project->id, '1250'))->toBe('0.00')
        ->and(JournalEntry::query()->where('source_document_type', 'project_closure')->where('source_document_id', $project->id)->count())->toBe(1)
        ->and(JournalEntry::query()->where('source_document_type', 'project_cost_entry')->count())->toBe(2);
    $this->getJson("/api/v1/projects/{$project->id}/profitability")->assertOk()->assertJsonPath('data.revenue.billed', '55000.00');
});

test('completed contract recognises nothing until completion, then the contract value and the whole cost', function (): void {
    $project = projectsProject($this, ['revenue_recognition_method' => 'completed_contract', 'contract_value' => '30000.00']);
    projectsActAs($this, $this->manager);
    projectsBookCost($this, $project, 10000);

    $this->postJson("/api/v1/projects/{$project->id}/revenue-recognitions", ['accounting_period_date' => now()->subDay()->toDateString(), 'override_completion_percentage' => 50])->assertUnprocessable();
    $this->postJson("/api/v1/projects/{$project->id}/revenue-recognitions", ['accounting_period_date' => now()->subDay()->toDateString()])->assertCreated()
        ->assertJsonPath('data.cumulative_recognized_revenue', '0.00')->assertJsonPath('data.is_posted_to_gl', false);
    expect(projectsLedgerBalance($this->tenant->id, $project->id, '1250'))->toBe('10000.00');

    $this->postJson("/api/v1/projects/{$project->id}/complete")->assertOk();
    $this->postJson("/api/v1/projects/{$project->id}/revenue-recognitions", ['accounting_period_date' => now()->toDateString()])->assertCreated()
        ->assertJsonPath('data.cumulative_recognized_revenue', '30000.00')->assertJsonPath('data.period_cost_of_revenue', '10000.00');

    expect(projectsLedgerBalance($this->tenant->id, $project->id, '1150'))->toBe('30000.00')
        ->and(projectsLedgerBalance($this->tenant->id, $project->id, '4000'))->toBe('-30000.00')
        ->and(projectsLedgerBalance($this->tenant->id, $project->id, '5000'))->toBe('10000.00')
        ->and(projectsLedgerBalance($this->tenant->id, $project->id, '1250'))->toBe('0.00');
});

test('projects without a recognition method keep costs out of WIP and refuse recognition runs', function (): void {
    $project = projectsProject($this);
    projectsActAs($this, $this->manager);
    projectsBookCost($this, $project, 4000);

    expect(projectsLedgerBalance($this->tenant->id, $project->id, '1250'))->toBe('0.00');
    $this->postJson("/api/v1/projects/{$project->id}/revenue-recognitions", ['accounting_period_date' => now()->toDateString()])->assertUnprocessable();

    $this->putJson("/api/v1/projects/{$project->id}", ['revenue_recognition_method' => 'percentage_of_completion'])->assertUnprocessable()->assertJsonValidationErrors(['revenue_recognition_method']);
    $this->postJson("/api/v1/projects/{$project->id}/revenue-recognitions", ['accounting_period_date' => now()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors(['accounting_period_date']);

    projectsActAs($this, $this->member);
    $this->postJson("/api/v1/projects/{$project->id}/revenue-recognitions", ['accounting_period_date' => now()->toDateString()])->assertForbidden();
});
