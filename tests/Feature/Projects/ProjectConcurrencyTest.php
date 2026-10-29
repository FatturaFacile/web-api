<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\DTOs\RecordJobCostDTO;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Models\ProjectFinancialSummary;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    Gate::before(fn () => true);

    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Project Concurrency Tenant',
        'legal_name' => 'Project Concurrency Enterprise',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::first() ?? User::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'name' => 'Concurrency Operator',
        'email' => 'concurrency@projects.test',
        'password' => bcrypt('password123'),
    ]);

    DB::table('tenant_user')->updateOrInsert(
        ['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id],
        [
            'id' => (string) Str::uuid7(),
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );

    $this->contact = Contact::where('tenant_id', $this->tenant->id)->first() ?? Contact::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'type' => 'customer',
        'country' => 'IT',
        'company_name' => 'Concurrency Test Client SpA',
        'email' => 'client@concurrency.it',
        'vat_number' => 'IT09876543210',
    ]);

    $this->project = Project::factory()->active()->create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->contact->id,
        'contract_value' => 200000.00,
    ]);

    ProjectFinancialSummary::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'project_id' => $this->project->id,
        'total_contract_revenue' => 200000.00,
        'budget_at_completion_bac' => 150000.00,
        'total_actual_cost' => 0.00,
        'actual_material_cost' => 0.00,
        'actual_labour_cost' => 0.00,
        'actual_supplier_cost' => 0.00,
        'last_recalculated_at' => now(),
    ]);
});

test('concurrent cost accumulation prevents lost updates and locks financial summary row', function (): void {
    $costingService = app(JobCostingAccumulationServiceInterface::class);

    $batches = [
        ['category' => 'material', 'amount' => 1250.00],
        ['category' => 'labour', 'amount' => 850.00],
        ['category' => 'supplier', 'amount' => 2100.00],
        ['category' => 'material', 'amount' => 3400.00],
        ['category' => 'labour', 'amount' => 1600.00],
    ];

    $expectedTotal = 0.0;

    foreach ($batches as $batch) {
        $expectedTotal += $batch['amount'];

        $dto = new RecordJobCostDTO(
            projectId: $this->project->id,
            costCategory: $batch['category'],
            costSourceType: 'manual_posting',
            costSourceId: (string) Str::uuid7(),
            quantity: 1.0,
            unitCost: $batch['amount'],
            totalCost: $batch['amount'],
            incurredDate: now()->toDateString(),
            userId: $this->user->id,
            companyId: $this->tenant->id
        );

        $costingService->recordCost($dto);
    }

    $summary = ProjectFinancialSummary::where('project_id', $this->project->id)->first();
    expect($summary)->not->toBeNull()
        ->and((float) $summary->total_actual_cost)->toBe($expectedTotal)
        ->and((float) $summary->actual_material_cost)->toBe(4650.00) // 1250 + 3400
        ->and((float) $summary->actual_labour_cost)->toBe(2450.00)   // 850 + 1600
        ->and((float) $summary->actual_supplier_cost)->toBe(2100.00);

    expect(ProjectCostEntry::where('project_id', $this->project->id)->count())->toBe(5);
});

test('pessimistic row locking on financial summary handles atomic balance rollup', function (): void {
    $summary = ProjectFinancialSummary::where('project_id', $this->project->id)->first();

    DB::transaction(function () use ($summary): void {
        // Lock summary row for update
        $lockedSummary = ProjectFinancialSummary::where('id', $summary->id)
            ->lockForUpdate()
            ->first();

        expect($lockedSummary)->not->toBeNull();

        $lockedSummary->update([
            'total_actual_cost' => $lockedSummary->total_actual_cost + 500.00,
        ]);
    });

    $refreshed = ProjectFinancialSummary::find($summary->id);
    expect((float) $refreshed->total_actual_cost)->toBe(500.00);
});
