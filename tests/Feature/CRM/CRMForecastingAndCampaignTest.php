<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Modules\CRM\Contracts\CampaignServiceInterface;
use Modules\CRM\Contracts\ForecastServiceInterface;
use Modules\CRM\DTOs\GenerateForecastDTO;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Forecast;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'CRM Forecast Test Tenant', 'legal_name' => 'CRM Forecast Test Tenant SpA']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::firstOrCreate(
        ['email' => 'forecastrep@example.com'],
        ['id' => (string) Str::uuid7(), 'name' => 'Forecast Representative', 'password' => bcrypt('secret')]
    );

    $this->account = Account::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'account_number' => 'ACC-FC-01',
        'name' => 'Target Enterprise SpA',
    ]);

    $this->forecastService = app(ForecastServiceInterface::class);
    $this->campaignService = app(CampaignServiceInterface::class);
});

test('generates sales forecast aggregating pipeline, best case, commit, closed and weighted amounts', function (): void {
    $stage = OpportunityStage::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'code' => 'nego_test',
        'name' => 'Negotiation Test',
        'sequence' => 40,
        'default_probability' => 80.00,
    ]);

    // Commit opportunity: 50,000 EUR
    Opportunity::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'account_id' => (string) $this->account->id,
        'stage_id' => (string) $stage->id,
        'owner_user_id' => (string) $this->user->id,
        'opportunity_number' => 'OPP-FC-01',
        'name' => 'Commit Project',
        'amount' => 50000.00,
        'expected_revenue' => 40000.00,
        'currency' => 'EUR',
        'probability' => 80.00,
        'forecast_category' => 'commit',
        'expected_close_date' => '2027-01-20',
        'status' => 'open',
    ]);

    // Won opportunity: 30,000 EUR
    Opportunity::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'account_id' => (string) $this->account->id,
        'stage_id' => (string) $stage->id,
        'owner_user_id' => (string) $this->user->id,
        'opportunity_number' => 'OPP-FC-02',
        'name' => 'Won Deal',
        'amount' => 30000.00,
        'expected_revenue' => 30000.00,
        'currency' => 'EUR',
        'probability' => 100.00,
        'forecast_category' => 'closed',
        'expected_close_date' => '2027-01-25',
        'actual_close_date' => '2027-01-25',
        'status' => 'won',
    ]);

    $forecast = $this->forecastService->generateForecast(new GenerateForecastDTO(
        tenantId: (string) $this->tenant->id,
        period: '2027-01',
        userId: (string) $this->user->id,
        quotaAmount: 100000.00
    ), (string) $this->user->id);

    expect($forecast)->not->toBeNull();
    expect((float) $forecast->closed_amount)->toBe(30000.00);
    expect((float) $forecast->commit_amount)->toBe(80000.00); // 50k commit + 30k won
    expect((float) $forecast->weighted_amount)->toBe(70000.00); // 40k + 30k
    expect((float) $forecast->quota_amount)->toBe(100000.00);
    expect((float) $forecast->attainment_percent)->toBe(30.00);

    // Lock forecast
    $locked = $this->forecastService->lockForecast($forecast, (string) $this->user->id);
    expect($locked->status)->toBe('locked');
});

test('creates marketing campaign, enrolls members, and calculates ROI metrics', function (): void {
    $campaign = $this->campaignService->createCampaign([
        'tenant_id' => (string) $this->tenant->id,
        'name' => 'Q1 Cloud ERP Virtual Summit',
        'code' => 'CMP-SUMMIT-2027',
        'budgeted_cost' => 10000.00,
        'actual_cost' => 8000.00,
        'expected_revenue' => 50000.00,
        'actual_revenue' => 64000.00,
        'utm_source' => 'linkedin',
    ], (string) $this->user->id);

    expect($campaign)->not->toBeNull();
    expect((float) $campaign->actual_cost)->toBe(8000.00);

    $lead = Lead::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'first_name' => 'Franco',
        'company_name' => 'Summit Attendee Inc',
        'email' => 'franco@summit.it',
        'status' => 'converted',
    ]);

    $this->campaignService->addMember($campaign, (string) $lead->id, null, 'converted');

    $roi = $this->campaignService->calculateROI($campaign);
    expect($roi['actual_cost'])->toBe(8000.00);
    expect($roi['actual_revenue'])->toBe(64000.00);
    // ROI = ((64000 - 8000) / 8000) * 100 = 700%
    expect($roi['roi_percentage'])->toBe(700.00);
    expect($roi['total_members'])->toBe(1);
    expect($roi['converted_members'])->toBe(1);
});
