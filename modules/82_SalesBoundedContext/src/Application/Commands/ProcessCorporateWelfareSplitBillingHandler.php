<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Illuminate\Support\Facades\DB;
use Modules\SalesBoundedContext\Domain\Enums\WelfareFiscalRegime;
use Modules\SalesBoundedContext\Domain\ValueObjects\DecimalMoney;
use Modules\SalesBoundedContext\Events\CorporateWelfareSplitBilledEvent;
use Modules\SalesBoundedContext\Exceptions\SalesBoundedContextException;
use Modules\SalesBoundedContext\Models\SbcCorporateWelfareConvention;

final readonly class ProcessCorporateWelfareSplitBillingHandler
{
    public function handle(ProcessCorporateWelfareSplitBillingCommand $command): SbcCorporateWelfareConvention
    {
        $data = $command->payload;
        $domain = strtolower(trim((string) ($data['corporate_email_domain'] ?? '')));
        $employeeEmail = strtolower(trim((string) ($data['employee_email'] ?? '')));
        $cralBadge = trim((string) ($data['cral_badge_number'] ?? ''));

        if ($domain === '' || ! str_ends_with($employeeEmail, '@'.$domain) || $cralBadge === '') {
            throw SalesBoundedContextException::invalidStateTransition(
                'Employee email domain and CRAL badge number must match the active corporate convention.'
            );
        }

        $regime = WelfareFiscalRegime::from((string) ($data['welfare_regime'] ?? 'it_art51_tuir'));
        $currency = strtoupper((string) ($data['currency'] ?? ($regime === WelfareFiscalRegime::ChRekaRailLunch ? 'CHF' : 'EUR')));

        $grossBasket = DecimalMoney::of((float) ($data['gross_basket_amount'] ?? 500), $currency);
        $discountPct = max(0.0, min(60.0, (float) ($data['convention_discount_pct'] ?? 10.0)));
        $discountedNet = $grossBasket->applyCascadingDiscounts([$discountPct]);

        $employerSplitPct = max(0.0, min(100.0, (float) ($data['employer_welfare_split_pct'] ?? 60.0)));
        $employerQuota = $discountedNet->percentage($employerSplitPct);
        $employeeCopay = $discountedNet->subtract($employerQuota);

        $annualLimit = DecimalMoney::of((float) ($data['regime_annual_limit'] ?? match ($regime) {
            WelfareFiscalRegime::ItArt51Tuir => 1000.00,
            WelfareFiscalRegime::ChRekaRailLunch => 600.00,
            WelfareFiscalRegime::B2bCralDiscount => 2500.00,
        }), $currency);
        $withinExemption = $employerQuota->toFloat() <= $annualLimit->toFloat();

        $convention = DB::transaction(function () use (
            $command,
            $data,
            $domain,
            $employeeEmail,
            $cralBadge,
            $regime,
            $currency,
            $grossBasket,
            $discountPct,
            $discountedNet,
            $employerQuota,
            $employeeCopay,
            $annualLimit,
            $withinExemption,
        ): SbcCorporateWelfareConvention {
            return SbcCorporateWelfareConvention::query()->create([
                'tenant_id' => $command->tenantId,
                'partner_dossier_id' => $data['partner_dossier_id'] ?? null,
                'convention_code' => (string) ($data['convention_code'] ?? 'WLF-'.strtoupper(substr(md5((string) now()->timestamp.mt_rand()), 0, 5))),
                'partner_company_name' => (string) $data['partner_company_name'],
                'corporate_email_domain' => $domain,
                'employee_email' => $employeeEmail,
                'cral_badge_number' => $cralBadge,
                'domain_and_cral_verified' => true,
                'welfare_regime' => $regime,
                'voucher_provider' => (string) ($data['voucher_provider'] ?? ($regime === WelfareFiscalRegime::ChRekaRailLunch ? 'Reka-Card' : 'Edenred Art.51')),
                'gross_basket_amount' => $grossBasket->roundHalfUp(),
                'convention_discount_pct' => $discountPct,
                'discounted_net_amount' => $discountedNet->roundHalfUp(),
                'employer_welfare_quota_amount' => $employerQuota->roundHalfUp(),
                'employee_copay_quota_amount' => $employeeCopay->roundHalfUp(),
                'regime_annual_limit' => $annualLimit->roundHalfUp(),
                'within_statutory_exemption' => $withinExemption,
                'currency' => $currency,
                'status' => 'split_billed',
            ]);
        });

        CorporateWelfareSplitBilledEvent::dispatch(
            $command->tenantId,
            (string) $convention->id,
            $employerQuota->toFloat(),
            $employeeCopay->toFloat(),
            $withinExemption,
        );

        return $convention;
    }
}
