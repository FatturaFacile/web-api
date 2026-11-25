<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\CorporateConventions\Domain\Enums\ConventionStatus;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;
use Modules\CorporateConventions\Services\ConventionService;

/**
 * Demo corporate conventions for the first company of the installation: an Italian company convention (work e-mail OTP, family
 * members, D.Lgs. 81/08 VDT eyewear subsidy, monthly anonymized billing) and a Swiss CRAL-style convention (membership card, SHA-256
 * employee list, promo code, Reka / Lunch-Check welfare in CHF). Idempotent by convention code; does nothing without a company
 * or a user. Names are demo content; the interface texts are in the frontend dictionary seeders.
 */
final class CorporateConventionsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('created_at')->first();
        $user = $tenant?->users()->first();

        if ($tenant === null || $user === null) {
            return;
        }

        app(TenantContext::class)->runWithTenant($tenant, function () use ($tenant, $user): void {
            if (CorporatePartnerConvention::query()->where('code', 'DEMO-IT-ACME')->exists()) {
                return;
            }

            $service = app(ConventionService::class);
            $tenantId = (string) $tenant->id;
            $userId = (string) $user->id;
            $category = ProductCategory::query()->orderBy('name')->first();

            $italian = $service->create([
                'code' => 'DEMO-IT-ACME',
                'name' => 'Acme Italia - dipendenti',
                'partner_company_name' => 'Acme Italia S.r.l.',
                'partner_vat_number' => '01234567897',
                'partner_type' => 'CORPORATE',
                'country_code' => 'IT',
                'currency' => 'EUR',
                'valid_from' => now()->startOfYear()->toDateString(),
                'valid_to' => now()->addYear()->endOfYear()->toDateString(),
                'eligible_population' => 450,
                'hr_contact_name' => 'Giulia Bianchi',
                'hr_contact_email' => 'hr@acme-italia.example',
                'verification_methods' => ['WORK_EMAIL_OTP'],
                'allowed_email_domains' => ['@acme-italia.example'],
                'allow_family_members' => true,
                'max_family_members' => 3,
                'annual_spending_cap_per_beneficiary' => '800.00',
                'employer_subsidy_enabled' => true,
                'employer_subsidy_type' => 'PERCENTAGE',
                'employer_subsidy_value' => '20.0000',
                'employer_subsidy_annual_cap' => '150.00',
                'subsidy_purpose' => 'DLGS_81_08_VDT',
                'split_billing_anonymized' => true,
                'billing_cycle' => 'MONTHLY',
                'primary_color' => '#1f4e79',
                'discount_rules' => array_values(array_filter([
                    $category === null ? null : ['priority' => 10, 'rule_type' => 'PRODUCT_FAMILY_PERCENTAGE', 'target_category_id' => $category->id, 'discount_percentage' => '20.0000', 'exclude_promotional_items' => true, 'cumulative_with_other_discounts' => false],
                    ['priority' => 20, 'rule_type' => 'GLOBAL_PERCENTAGE', 'discount_percentage' => '10.0000', 'exclude_promotional_items' => true, 'cumulative_with_other_discounts' => false],
                ])),
            ], $tenantId, $userId);
            $service->transition($italian, ConventionStatus::Active, $userId);

            $swiss = $service->create([
                'code' => 'DEMO-CH-CRAL',
                'name' => 'Cassa del personale Lugano',
                'partner_company_name' => 'Personalverein Lugano',
                'partner_uid_number' => 'CHE-123.456.788',
                'partner_type' => 'CRAL',
                'country_code' => 'CH',
                'currency' => 'CHF',
                'valid_from' => now()->startOfYear()->toDateString(),
                'valid_to' => null,
                'eligible_population' => 180,
                'verification_methods' => ['MEMBERSHIP_CARD_PREFIX', 'HASHED_EMPLOYEE_LIST', 'PROMO_CODE'],
                'card_number_prefix' => 'LUG',
                'card_number_regex' => '^LUG\d{6}$',
                'static_convention_code' => 'LUGANO-CRAL',
                'allow_family_members' => true,
                'max_family_members' => 2,
                'annual_spending_cap_per_beneficiary' => null,
                'employer_subsidy_enabled' => false,
                'subsidy_purpose' => 'GENERAL',
                'split_billing_anonymized' => false,
                'billing_cycle' => 'QUARTERLY',
                'primary_color' => '#a6192e',
                'discount_rules' => [
                    ['priority' => 10, 'rule_type' => 'GLOBAL_PERCENTAGE', 'discount_percentage' => '12.0000', 'exclude_promotional_items' => true, 'cumulative_with_other_discounts' => false, 'min_cart_amount' => '50.00'],
                ],
            ], $tenantId, $userId);
            $service->importIdentifiers($swiss, ['TI-1001', 'TI-1002', 'TI-1003'], false, $userId);
            $service->transition($swiss, ConventionStatus::Active, $userId);
        });
    }
}
