<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Campaign;
use Modules\CRM\Models\CampaignType;
use Modules\CRM\Models\CustomerHealthProfile;
use Modules\CRM\Models\Forecast;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadSource;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;

final class CRMTestingSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['id' => '019318b7-0000-7000-8000-000000000001'],
            ['name' => 'CRM Test Enterprise', 'legal_name' => 'CRM Test Enterprise SpA']
        );
        app(TenantContext::class)->setTenant($tenant);

        $this->call(CRMReferenceSeeder::class);

        $source = LeadSource::where('tenant_id', $tenant->id)->where('code', 'web')->first();
        $campType = CampaignType::where('tenant_id', $tenant->id)->where('code', 'webinar')->first();

        $campaign = Campaign::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'CMP-TEST-001'],
            [
                'id' => (string) Str::uuid7(),
                'campaign_type_id' => $campType?->id,
                'name' => 'Q1 Testing Campaign',
                'status' => 'active',
                'budgeted_cost' => 5000.00,
                'actual_cost' => 4500.00,
                'expected_revenue' => 25000.00,
                'actual_revenue' => 30000.00,
            ]
        );

        $account = Account::updateOrCreate(
            ['tenant_id' => $tenant->id, 'account_number' => 'ACC-TEST-001'],
            [
                'id' => (string) Str::uuid7(),
                'name' => 'Alpha Industrial Test Srl',
                'tier' => 'enterprise',
                'status' => 'active',
                'annual_revenue' => 2500000.00,
                'customer_health_index' => 92.50,
                'churn_risk' => 'low',
            ]
        );

        $contact = Contact::firstOrCreate(
            ['tenant_id' => $tenant->id, 'email' => 'mario.rossi@alphaindustrial.it'],
            [
                'id' => (string) Str::uuid7(),
                'company_name' => $account->name,
                'first_name' => 'Mario',
                'last_name' => 'Rossi',
                'type' => 'client',
                'country' => 'IT',
            ]
        );

        $account->update(['contact_id' => $contact->id]);

        $stageDiscovery = OpportunityStage::where('tenant_id', $tenant->id)->where('code', 'discovery')->first();
        $stageWon = OpportunityStage::where('tenant_id', $tenant->id)->where('code', 'closed_won')->first();

        Opportunity::updateOrCreate(
            ['tenant_id' => $tenant->id, 'opportunity_number' => 'OPP-TEST-001'],
            [
                'id' => (string) Str::uuid7(),
                'account_id' => $account->id,
                'contact_id' => $contact->id,
                'stage_id' => $stageDiscovery->id,
                'name' => 'ERP Core Modernization',
                'amount' => 45000.00,
                'expected_revenue' => 6750.00,
                'currency' => 'EUR',
                'probability' => 15.00,
                'forecast_category' => 'pipeline',
                'expected_close_date' => now()->addDays(30)->toDateString(),
                'status' => 'open',
            ]
        );

        Opportunity::updateOrCreate(
            ['tenant_id' => $tenant->id, 'opportunity_number' => 'OPP-TEST-WON'],
            [
                'id' => (string) Str::uuid7(),
                'account_id' => $account->id,
                'contact_id' => $contact->id,
                'stage_id' => $stageWon->id,
                'name' => 'Supply Chain Automation',
                'amount' => 80000.00,
                'expected_revenue' => 80000.00,
                'currency' => 'EUR',
                'probability' => 100.00,
                'forecast_category' => 'closed',
                'expected_close_date' => now()->subDays(5)->toDateString(),
                'actual_close_date' => now()->subDays(5)->toDateString(),
                'status' => 'won',
            ]
        );

        Lead::updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => 'giulia.bianchi@betalogistics.it'],
            [
                'id' => (string) Str::uuid7(),
                'lead_source_id' => $source?->id,
                'campaign_id' => $campaign->id,
                'first_name' => 'Giulia',
                'last_name' => 'Bianchi',
                'company_name' => 'Beta Logistics SpA',
                'title' => 'Chief Technology Officer',
                'phone' => '+39 02 9876543',
                'status' => 'qualified',
                'score' => 85,
                'rating' => 'hot',
                'estimated_budget' => 65000.00,
            ]
        );

        Activity::updateOrCreate(
            ['tenant_id' => $tenant->id, 'subject' => 'Executive Architectural Review Meeting'],
            [
                'id' => (string) Str::uuid7(),
                'type' => 'meeting',
                'status' => 'completed',
                'priority' => 'high',
                'due_date' => now()->subDays(1),
                'completed_at' => now()->subDays(1),
                'related_type' => 'account',
                'related_id' => $account->id,
                'duration_minutes' => 60,
                'outcome' => 'Client confirmed requirements for full migration.',
            ]
        );

        CustomerHealthProfile::updateOrCreate(
            ['tenant_id' => $tenant->id, 'account_id' => $account->id],
            [
                'id' => (string) Str::uuid7(),
                'activity_score' => 90.00,
                'engagement_score' => 85.00,
                'revenue_score' => 95.00,
                'risk_score' => 10.00,
                'churn_risk_indicator' => 'low',
                'health_index' => 91.75,
                'last_interaction_at' => now()->subDays(1),
            ]
        );

        Forecast::updateOrCreate(
            ['tenant_id' => $tenant->id, 'period' => '2027-Q1'],
            [
                'id' => (string) Str::uuid7(),
                'currency' => 'EUR',
                'pipeline_amount' => 45000.00,
                'best_case_amount' => 125000.00,
                'commit_amount' => 80000.00,
                'closed_amount' => 80000.00,
                'weighted_amount' => 86750.00,
                'quota_amount' => 150000.00,
                'attainment_percent' => 53.33,
                'status' => 'draft',
            ]
        );
    }
}
