<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Campaign;
use Modules\CRM\Models\CampaignType;
use Modules\CRM\Models\ContactRole;
use Modules\CRM\Models\CustomerHealthProfile;
use Modules\CRM\Models\Forecast;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadSource;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityLineItem;
use Modules\CRM\Models\OpportunityStage;

final class CRMDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $this->call(CRMReferenceSeeder::class);

        foreach ($tenants as $tenant) {
            $tId = (string) $tenant->id;

            $srcWeb = LeadSource::where('tenant_id', $tId)->where('code', 'web')->first();
            $srcReferral = LeadSource::where('tenant_id', $tId)->where('code', 'referral')->first();
            $srcEvent = LeadSource::where('tenant_id', $tId)->where('code', 'event')->first();

            $cmpTypeEmail = CampaignType::where('tenant_id', $tId)->where('code', 'email')->first();
            $cmpTypeWebinar = CampaignType::where('tenant_id', $tId)->where('code', 'webinar')->first();

            $cmpEnterprise = Campaign::updateOrCreate(
                ['tenant_id' => $tId, 'code' => 'CMP-ENT-2027'],
                [
                    'id' => (string) Str::uuid7(),
                    'campaign_type_id' => $cmpTypeWebinar?->id,
                    'name' => 'Enterprise ERP Digital Transformation Summit',
                    'status' => 'active',
                    'budgeted_cost' => 20000.00,
                    'actual_cost' => 18500.00,
                    'expected_revenue' => 250000.00,
                    'actual_revenue' => 310000.00,
                    'utm_source' => 'linkedin',
                    'utm_medium' => 'paid_social',
                    'utm_campaign' => 'ent_summit_2027',
                ]
            );

            $cmpSmb = Campaign::updateOrCreate(
                ['tenant_id' => $tId, 'code' => 'CMP-SMB-2027'],
                [
                    'id' => (string) Str::uuid7(),
                    'campaign_type_id' => $cmpTypeEmail?->id,
                    'name' => 'SMB Invoicing & Billing Automation Drive',
                    'status' => 'active',
                    'budgeted_cost' => 8000.00,
                    'actual_cost' => 6200.00,
                    'expected_revenue' => 60000.00,
                    'actual_revenue' => 74000.00,
                    'utm_source' => 'google',
                    'utm_medium' => 'cpc',
                    'utm_campaign' => 'smb_drive_2027',
                ]
            );

            // Stages
            $stgDisc = OpportunityStage::where('tenant_id', $tId)->where('code', 'discovery')->first();
            $stgQual = OpportunityStage::where('tenant_id', $tId)->where('code', 'qualification')->first();
            $stgProp = OpportunityStage::where('tenant_id', $tId)->where('code', 'proposal')->first();
            $stgNego = OpportunityStage::where('tenant_id', $tId)->where('code', 'negotiation')->first();
            $stgWon = OpportunityStage::where('tenant_id', $tId)->where('code', 'closed_won')->first();
            $stgLost = OpportunityStage::where('tenant_id', $tId)->where('code', 'closed_lost')->first();

            // ==========================================
            // FUNNEL 1: ENTERPRISE SALES FUNNEL
            // ==========================================
            $accEnt = Account::updateOrCreate(
                ['tenant_id' => $tId, 'account_number' => 'ACC-ENT-001'],
                [
                    'id' => (string) Str::uuid7(),
                    'name' => 'Global Manufacturing Dynamics SpA',
                    'industry' => 'Automotive & Heavy Industry',
                    'tier' => 'strategic',
                    'status' => 'active',
                    'annual_revenue' => 45000000.00,
                    'employee_count' => 850,
                    'customer_health_index' => 96.00,
                    'churn_risk' => 'low',
                    'lifecycle_stage' => 'customer',
                    'last_activity_at' => now()->subDays(2),
                ]
            );

            $cntEnt = Contact::firstOrCreate(
                ['tenant_id' => $tId, 'email' => 'a.ferrari@globaldynamics.it'],
                [
                    'id' => (string) Str::uuid7(),
                    'company_name' => $accEnt->name,
                    'first_name' => 'Alessandro',
                    'last_name' => 'Ferrari',
                    'type' => 'client',
                    'country' => 'IT',
                    'phone' => '+39 02 5551234',
                ]
            );
            $accEnt->update(['contact_id' => $cntEnt->id]);

            ContactRole::updateOrCreate(
                ['tenant_id' => $tId, 'account_id' => $accEnt->id, 'contact_id' => $cntEnt->id],
                ['id' => (string) Str::uuid7(), 'role' => 'decision_maker', 'is_primary' => true]
            );

            // Won Deal in Enterprise Funnel
            $oppEntWon = Opportunity::updateOrCreate(
                ['tenant_id' => $tId, 'opportunity_number' => 'OPP-ENT-WON-01'],
                [
                    'id' => (string) Str::uuid7(),
                    'account_id' => $accEnt->id,
                    'contact_id' => $cntEnt->id,
                    'campaign_id' => $cmpEnterprise->id,
                    'stage_id' => $stgWon->id,
                    'name' => 'Phase 1: Multi-Plant ERP Deployment',
                    'amount' => 180000.00,
                    'expected_revenue' => 180000.00,
                    'currency' => 'EUR',
                    'probability' => 100.00,
                    'forecast_category' => 'closed',
                    'expected_close_date' => now()->subDays(10)->toDateString(),
                    'actual_close_date' => now()->subDays(10)->toDateString(),
                    'status' => 'won',
                ]
            );

            OpportunityLineItem::updateOrCreate(
                ['tenant_id' => $tId, 'opportunity_id' => $oppEntWon->id, 'description' => 'Core ERP License (500 seats)'],
                ['id' => (string) Str::uuid7(), 'quantity' => 1.0, 'unit_price' => 120000.00, 'total_price' => 120000.00]
            );
            OpportunityLineItem::updateOrCreate(
                ['tenant_id' => $tId, 'opportunity_id' => $oppEntWon->id, 'description' => 'System Integration & Deployment'],
                ['id' => (string) Str::uuid7(), 'quantity' => 1.0, 'unit_price' => 60000.00, 'total_price' => 60000.00]
            );

            // In-flight Deal in Enterprise Funnel
            Opportunity::updateOrCreate(
                ['tenant_id' => $tId, 'opportunity_number' => 'OPP-ENT-NEGO-02'],
                [
                    'id' => (string) Str::uuid7(),
                    'account_id' => $accEnt->id,
                    'contact_id' => $cntEnt->id,
                    'stage_id' => $stgNego->id,
                    'name' => 'Phase 2: Supply Chain & MES Expansion',
                    'amount' => 120000.00,
                    'expected_revenue' => 102000.00,
                    'currency' => 'EUR',
                    'probability' => 85.00,
                    'forecast_category' => 'commit',
                    'expected_close_date' => now()->addDays(20)->toDateString(),
                    'status' => 'open',
                ]
            );

            // ==========================================
            // FUNNEL 2: SMB SALES FUNNEL
            // ==========================================
            $accSmb = Account::updateOrCreate(
                ['tenant_id' => $tId, 'account_number' => 'ACC-SMB-002'],
                [
                    'id' => (string) Str::uuid7(),
                    'name' => 'Officina Meccanica Rossi Srl',
                    'industry' => 'Precision Engineering',
                    'tier' => 'smb',
                    'status' => 'active',
                    'annual_revenue' => 1200000.00,
                    'employee_count' => 24,
                    'customer_health_index' => 88.00,
                    'churn_risk' => 'low',
                    'lifecycle_stage' => 'customer',
                    'last_activity_at' => now()->subDays(5),
                ]
            );

            $cntSmb = Contact::firstOrCreate(
                ['tenant_id' => $tId, 'email' => 'luca.rossi@meccanicarossi.it'],
                [
                    'id' => (string) Str::uuid7(),
                    'company_name' => $accSmb->name,
                    'first_name' => 'Luca',
                    'last_name' => 'Rossi',
                    'type' => 'client',
                    'country' => 'IT',
                ]
            );
            $accSmb->update(['contact_id' => $cntSmb->id]);

            Opportunity::updateOrCreate(
                ['tenant_id' => $tId, 'opportunity_number' => 'OPP-SMB-WON-01'],
                [
                    'id' => (string) Str::uuid7(),
                    'account_id' => $accSmb->id,
                    'contact_id' => $cntSmb->id,
                    'campaign_id' => $cmpSmb->id,
                    'stage_id' => $stgWon->id,
                    'name' => 'Cloud Invoicing & Warehouse Essentials',
                    'amount' => 15000.00,
                    'expected_revenue' => 15000.00,
                    'currency' => 'EUR',
                    'probability' => 100.00,
                    'forecast_category' => 'closed',
                    'expected_close_date' => now()->subDays(15)->toDateString(),
                    'actual_close_date' => now()->subDays(15)->toDateString(),
                    'status' => 'won',
                ]
            );

            // ==========================================
            // FUNNEL 3: CONSULTING & PROFESSIONAL SERVICES FUNNEL
            // ==========================================
            $accCons = Account::updateOrCreate(
                ['tenant_id' => $tId, 'account_number' => 'ACC-CONS-003'],
                [
                    'id' => (string) Str::uuid7(),
                    'name' => 'Studio Legale & Tributario Visconti Associati',
                    'industry' => 'Legal & Fiscal Advisory',
                    'tier' => 'mid_market',
                    'status' => 'active',
                    'annual_revenue' => 3800000.00,
                    'employee_count' => 45,
                    'customer_health_index' => 74.00,
                    'churn_risk' => 'medium',
                    'lifecycle_stage' => 'prospect',
                    'last_activity_at' => now()->subDays(14),
                ]
            );

            $cntCons = Contact::firstOrCreate(
                ['tenant_id' => $tId, 'email' => 'm.visconti@studiovisconti.it'],
                [
                    'id' => (string) Str::uuid7(),
                    'company_name' => $accCons->name,
                    'first_name' => 'Massimo',
                    'last_name' => 'Visconti',
                    'type' => 'client',
                    'country' => 'IT',
                ]
            );
            $accCons->update(['contact_id' => $cntCons->id]);

            Opportunity::updateOrCreate(
                ['tenant_id' => $tId, 'opportunity_number' => 'OPP-CONS-PROP-01'],
                [
                    'id' => (string) Str::uuid7(),
                    'account_id' => $accCons->id,
                    'contact_id' => $cntCons->id,
                    'stage_id' => $stgProp->id,
                    'name' => 'Practice Management & Job Costing Suite',
                    'amount' => 35000.00,
                    'expected_revenue' => 21000.00,
                    'currency' => 'EUR',
                    'probability' => 60.00,
                    'forecast_category' => 'best_case',
                    'expected_close_date' => now()->addDays(25)->toDateString(),
                    'status' => 'open',
                ]
            );

            // ==========================================
            // FUNNEL 4: ERP IMPLEMENTATION FUNNEL (LOST CASE)
            // ==========================================
            $accLost = Account::updateOrCreate(
                ['tenant_id' => $tId, 'account_number' => 'ACC-LOST-004'],
                [
                    'id' => (string) Str::uuid7(),
                    'name' => 'Nordic Hardware Distribution AB',
                    'industry' => 'Wholesale & Retail Hardware',
                    'tier' => 'enterprise',
                    'status' => 'prospect',
                    'annual_revenue' => 18000000.00,
                    'employee_count' => 180,
                    'customer_health_index' => 50.00,
                    'churn_risk' => 'high',
                    'lifecycle_stage' => 'prospect',
                ]
            );

            Opportunity::updateOrCreate(
                ['tenant_id' => $tId, 'opportunity_number' => 'OPP-ERP-LOST-01'],
                [
                    'id' => (string) Str::uuid7(),
                    'account_id' => $accLost->id,
                    'stage_id' => $stgLost->id,
                    'name' => 'International B2B Portal Implementation',
                    'amount' => 70000.00,
                    'expected_revenue' => 0.00,
                    'currency' => 'EUR',
                    'probability' => 0.00,
                    'forecast_category' => 'omitted',
                    'expected_close_date' => now()->subDays(20)->toDateString(),
                    'actual_close_date' => now()->subDays(20)->toDateString(),
                    'win_loss_reason' => 'Legacy vendor matched pricing discount',
                    'competitor' => 'SAP Business One',
                    'status' => 'lost',
                ]
            );

            // Demo Leads
            Lead::updateOrCreate(
                ['tenant_id' => $tId, 'email' => 'clara.schmidt@bavariamachinery.de'],
                [
                    'id' => (string) Str::uuid7(),
                    'lead_source_id' => $srcWeb?->id,
                    'first_name' => 'Clara',
                    'last_name' => 'Schmidt',
                    'company_name' => 'Bavaria Machinery GmbH',
                    'title' => 'Managing Director',
                    'phone' => '+49 89 12345678',
                    'country' => 'DE',
                    'status' => 'qualified',
                    'score' => 88,
                    'rating' => 'hot',
                    'estimated_budget' => 95000.00,
                    'tags' => ['dach', 'manufacturing'],
                ]
            );

            Lead::updateOrCreate(
                ['tenant_id' => $tId, 'email' => 'jean.dupont@rhonealpeslogistique.fr'],
                [
                    'id' => (string) Str::uuid7(),
                    'lead_source_id' => $srcReferral?->id,
                    'first_name' => 'Jean',
                    'last_name' => 'Dupont',
                    'company_name' => 'Rhône-Alpes Logistique SAS',
                    'title' => 'Directeur des Opérations',
                    'phone' => '+33 4 72 00 11 22',
                    'country' => 'FR',
                    'status' => 'new',
                    'score' => 55,
                    'rating' => 'warm',
                    'estimated_budget' => 40000.00,
                    'tags' => ['france', 'logistics'],
                ]
            );

            // Demo Activities
            Activity::updateOrCreate(
                ['tenant_id' => $tId, 'subject' => 'Phase 2 Architecture Review Meeting'],
                [
                    'id' => (string) Str::uuid7(),
                    'type' => 'meeting',
                    'status' => 'completed',
                    'priority' => 'urgent',
                    'due_date' => now()->subDays(2),
                    'completed_at' => now()->subDays(2),
                    'related_type' => 'account',
                    'related_id' => $accEnt->id,
                    'duration_minutes' => 90,
                    'location' => 'HQ Boardroom Milan',
                    'outcome' => 'Board approved phase 2 scope and timeline.',
                ]
            );

            Activity::updateOrCreate(
                ['tenant_id' => $tId, 'subject' => 'Follow up on practice management proposal'],
                [
                    'id' => (string) Str::uuid7(),
                    'type' => 'follow_up',
                    'status' => 'pending',
                    'priority' => 'high',
                    'due_date' => now()->addDays(2),
                    'related_type' => 'account',
                    'related_id' => $accCons->id,
                    'duration_minutes' => 30,
                    'notes' => 'Call Managing Partner regarding software license discount structure.',
                ]
            );

            // Customer Health Profiles
            CustomerHealthProfile::updateOrCreate(
                ['tenant_id' => $tId, 'account_id' => $accEnt->id],
                [
                    'id' => (string) Str::uuid7(),
                    'activity_score' => 98.00,
                    'engagement_score' => 95.00,
                    'revenue_score' => 100.00,
                    'risk_score' => 5.00,
                    'churn_risk_indicator' => 'low',
                    'health_index' => 97.25,
                    'last_interaction_at' => now()->subDays(2),
                    'open_receivable_amount' => 0.00,
                    'overdue_receivable_amount' => 0.00,
                ]
            );

            CustomerHealthProfile::updateOrCreate(
                ['tenant_id' => $tId, 'account_id' => $accCons->id],
                [
                    'id' => (string) Str::uuid7(),
                    'activity_score' => 65.00,
                    'engagement_score' => 70.00,
                    'revenue_score' => 80.00,
                    'risk_score' => 35.00,
                    'churn_risk_indicator' => 'medium',
                    'health_index' => 70.75,
                    'last_interaction_at' => now()->subDays(14),
                    'open_receivable_amount' => 15000.00,
                    'overdue_receivable_amount' => 5000.00,
                ]
            );

            // Sales Forecast
            Forecast::updateOrCreate(
                ['tenant_id' => $tId, 'period' => '2027-Q1'],
                [
                    'id' => (string) Str::uuid7(),
                    'currency' => 'EUR',
                    'pipeline_amount' => 35000.00,
                    'best_case_amount' => 350000.00,
                    'commit_amount' => 315000.00,
                    'closed_amount' => 195000.00,
                    'weighted_amount' => 318000.00,
                    'quota_amount' => 300000.00,
                    'attainment_percent' => 65.00,
                    'status' => 'draft',
                ]
            );
        }
    }
}
