<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Support;

use Modules\CorporateConventions\Domain\Enums\WelfareCategory;
use Modules\CorporateConventions\Domain\Enums\WelfareJurisdiction;
use Modules\CorporateConventions\Domain\Enums\WelfareProvider;
use Modules\CorporateConventions\Exceptions\ConventionException;

/**
 * What a welfare voucher may pay for, by jurisdiction.
 *
 * IT_ART51_TUIR: corporate welfare under Art. 51 TUIR, paid in EUR with the Italian providers (Edenred, Pellegrini, Pluxee/Sodexo,
 * Eudaimon, DoubleYou, Boncadeau and others); every welfare category is accepted, meal vouchers included (Art. 51 c. 2 lett. c).
 * CH_REKA_LUNCHCHECK: Swiss cheques, paid in CHF: Lunch-Check pays meals only, Reka pays leisure, sport and shopping, never meals.
 */
final class WelfareVoucherPolicy
{
    /** @var list<WelfareProvider> */
    private const array SwissOnly = [WelfareProvider::RekaCard, WelfareProvider::LunchCheck];

    public function assertAllowed(WelfareJurisdiction $jurisdiction, WelfareProvider $provider, WelfareCategory $category, string $currency, string $grossAmount): void
    {
        if (Dec::cmp($grossAmount, '0') <= 0) {
            throw new ConventionException('welfare_amount_invalid', 422);
        }

        $expectedCurrency = $jurisdiction === WelfareJurisdiction::ItArt51Tuir ? 'EUR' : 'CHF';

        if (strtoupper($currency) !== $expectedCurrency) {
            throw new ConventionException('welfare_currency_mismatch', 422, ['expected' => $expectedCurrency]);
        }

        $providerAllowed = $jurisdiction === WelfareJurisdiction::ItArt51Tuir
            ? ! in_array($provider, self::SwissOnly, true)
            : in_array($provider, [...self::SwissOnly, WelfareProvider::Other], true);

        if (! $providerAllowed) {
            throw new ConventionException('welfare_provider_not_allowed', 422, ['provider' => $provider->value, 'jurisdiction' => $jurisdiction->value]);
        }

        if (! in_array($category, $this->categoriesOf($jurisdiction, $provider), true)) {
            throw new ConventionException('welfare_category_not_allowed', 422, ['category' => $category->value, 'provider' => $provider->value]);
        }
    }

    /**
     * @return list<WelfareCategory>
     */
    public function categoriesOf(WelfareJurisdiction $jurisdiction, WelfareProvider $provider): array
    {
        if ($jurisdiction === WelfareJurisdiction::ItArt51Tuir || $provider === WelfareProvider::Other) {
            return WelfareCategory::cases();
        }

        return $provider === WelfareProvider::LunchCheck
            ? [WelfareCategory::MealVoucher]
            : [WelfareCategory::CultureLeisure, WelfareCategory::SportsFitness, WelfareCategory::ShoppingVoucherExempt];
    }

    /**
     * Merchant fee withheld by the provider and the net the merchant expects to be reimbursed, both rounded to cents.
     *
     * @return array{fee: string, net: string}
     */
    public function split(string $grossAmount, string $feePercentage): array
    {
        $fee = Dec::round(Dec::mul($grossAmount, Dec::div($feePercentage, '100')));

        return ['fee' => $fee, 'net' => Dec::round(Dec::sub($grossAmount, $fee))];
    }

    /**
     * "****1234": the only form of a voucher code kept in clear.
     */
    public static function mask(string $code): string
    {
        $clean = preg_replace('/\s+/', '', trim($code)) ?? '';

        return strlen($clean) <= 4 ? str_repeat('*', strlen($clean)) : str_repeat('*', min(8, strlen($clean) - 4)).substr($clean, -4);
    }
}
