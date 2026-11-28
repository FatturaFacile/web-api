<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\WithholdingRecipients\Domain\Calculation\CalculatorRegistry;
use Modules\WithholdingRecipients\Domain\Calculation\CashPeriodResolver;
use Modules\WithholdingRecipients\Domain\Calculation\CompensationInput;
use Modules\WithholdingRecipients\Domain\Calculation\CompensationResult;
use Modules\WithholdingRecipients\Domain\Enums\ActivityType;
use Modules\WithholdingRecipients\Domain\Enums\EnasarcoMandate;
use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;
use Modules\WithholdingRecipients\Domain\ValueObjects\Money;
use Modules\WithholdingRecipients\Models\AgentReducedWithholdingCert;
use Modules\WithholdingRecipients\Models\RecipientProfile;
use Modules\WithholdingRecipients\Models\RecipientSettlement;

/**
 * Builds the calculation input of a compensation from the recipient profile, the declarations on file and what was already
 * posted in the tax year, then runs the calculator of the regime. Without a profile (the simulator) the facts come from the
 * request.
 */
final class CompensationEngine
{
    public function __construct(
        private readonly CalculatorRegistry $registry,
        private readonly CashPeriodResolver $periods,
    ) {}

    /**
     * @param  array<string, mixed>  $data  gross_amount, payment_date, service_date, and for an ad-hoc simulation the payee facts
     */
    public function input(array $data, ?RecipientProfile $profile): CompensationInput
    {
        $regime = $profile?->regimeEnum() ?? RecipientRegime::from((string) $data['regime']);
        $paymentDate = CarbonImmutable::parse((string) $data['payment_date'])->startOfDay();
        $serviceDate = CarbonImmutable::parse((string) ($data['service_date'] ?? $data['payment_date']))->startOfDay();
        $taxYear = $this->periods->taxYear($paymentDate, $serviceDate);

        $birthDate = $profile->birth_date ?? ($data['birth_date'] ?? null);
        $taxCode = $profile->tax_code ?? ($data['tax_code'] ?? null);
        $mandate = $profile?->mandateEnum() ?? (isset($data['enasarco_mandate']) ? EnasarcoMandate::from((string) $data['enasarco_mandate']) : null);
        $activity = $profile?->activityEnum() ?? (isset($data['activity_type']) ? ActivityType::from((string) $data['activity_type']) : null);

        return new CompensationInput(
            regime: $regime,
            gross: Money::of($data['gross_amount']),
            paymentDate: $paymentDate,
            taxYear: $taxYear,
            birthDate: $birthDate === null ? null : CarbonImmutable::parse((string) $birthDate)->startOfDay(),
            taxCode: $taxCode === null ? null : (string) $taxCode,
            priorCompanyIncome: $profile === null ? Money::of($data['prior_company_income'] ?? 0) : $this->priorIncome($profile, $taxYear),
            thirdPartyIncome: $this->thirdPartyIncome($profile, $taxYear, $data),
            priorAvsBase: $profile === null ? Money::of($data['prior_avs_base'] ?? 0) : $this->priorAvsBase($profile, $taxYear),
            hasReducedWithholdingCertificate: $profile === null ? (bool) ($data['has_reduced_certificate'] ?? false) : $this->hasCertificate($profile, $paymentDate),
            enasarcoMandate: $mandate,
            firrEnabled: $profile->firr_enabled ?? (bool) ($data['firr_enabled'] ?? false),
            culturalSector: $profile->is_cultural_sector ?? (bool) ($data['is_cultural_sector'] ?? false),
            activityType: $activity,
            performanceDays: max(1, (int) ($data['performance_days'] ?? 1)),
            acquisitionExpenses: isset($data['acquisition_expenses']) ? Money::of($data['acquisition_expenses']) : null,
            canton: $profile->canton ?? ($data['canton'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function calculate(array $data, ?RecipientProfile $profile): CompensationResult
    {
        return $this->run($this->input($data, $profile));
    }

    public function run(CompensationInput $input): CompensationResult
    {
        return $this->registry->calculate($input);
    }

    public function priorIncome(RecipientProfile $profile, int $taxYear): Money
    {
        return Money::of($this->postedQuery($profile, $taxYear)->sum('gross_amount'));
    }

    public function priorAvsBase(RecipientProfile $profile, int $taxYear): Money
    {
        return Money::of($this->postedQuery($profile, $taxYear)->sum('avs_base'));
    }

    public function hasCertificate(RecipientProfile $profile, CarbonImmutable $on): bool
    {
        return AgentReducedWithholdingCert::query()
            ->where('recipient_profile_id', $profile->id)
            ->whereDate('valid_from', '<=', $on->toDateString())
            ->whereDate('valid_to', '>=', $on->toDateString())
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function thirdPartyIncome(?RecipientProfile $profile, int $taxYear, array $data): Money
    {
        if ($profile === null) {
            return Money::of($data['third_party_income'] ?? 0);
        }

        return (int) $profile->third_party_income_year === $taxYear ? Money::of($profile->third_party_ytd_income) : Money::zero();
    }

    /**
     * @return Builder<RecipientSettlement>
     */
    private function postedQuery(RecipientProfile $profile, int $taxYear): Builder
    {
        return RecipientSettlement::query()
            ->where('recipient_profile_id', $profile->id)
            ->where('tax_year', $taxYear)
            ->where('status', 'posted');
    }
}
