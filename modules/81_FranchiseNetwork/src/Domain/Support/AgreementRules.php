<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Support;

use Carbon\CarbonImmutable;
use Modules\FranchiseNetwork\Domain\Enums\GuaranteeType;
use Modules\FranchiseNetwork\Domain\Enums\Jurisdiction;
use Modules\FranchiseNetwork\Domain\Enums\PaymentMethod;

/**
 * Compliance findings of an affiliation agreement.
 *
 * Italy (L. 129/2004): the term must be at least three years (Art. 3, comma 3), the franchisor delivers the draft agreement and the
 * disclosure at least thirty days before the signature (Art. 4), and the entry fee is deferred over the term. Switzerland (CO / LCart):
 * prices sent to the stores can only be recommended, and the collection mandate is LSV+ (or SEPA SDD for the euro area).
 *
 * A finding is `{code, severity, params}`; severity is `error` (the agreement cannot be activated) or `warning`.
 */
final class AgreementRules
{
    public const int ItalianMinimumTermMonths = 36;

    public const int DisclosureDays = 30;

    public const int GuaranteeWarningDays = 60;

    public const int ExpiryWarningDays = 120;

    /**
     * @param  array<string, mixed>  $agreement  start_date, end_date, term_months, jurisdiction, signed_on, disclosure_delivered_on,
     *                                           entry_fee, entry_fee_deferral_months, guarantee_type, guarantee_amount,
     *                                           guarantee_expires_on, payment_method, mandate_reference
     * @return list<array{code: string, severity: string, params: array<string, scalar>}>
     */
    public static function evaluate(array $agreement, CarbonImmutable $today): array
    {
        $findings = [];
        $jurisdiction = Jurisdiction::tryFrom((string) ($agreement['jurisdiction'] ?? 'IT')) ?? Jurisdiction::It;
        $start = CarbonImmutable::parse((string) $agreement['start_date'])->startOfDay();
        $end = CarbonImmutable::parse((string) $agreement['end_date'])->startOfDay();
        $term = (int) $agreement['term_months'];

        if ($jurisdiction === Jurisdiction::It && $term < self::ItalianMinimumTermMonths) {
            $findings[] = self::finding('term_below_statutory_minimum', 'warning', ['months' => $term, 'minimum' => self::ItalianMinimumTermMonths]);
        }

        if ($jurisdiction === Jurisdiction::It && ! empty($agreement['signed_on'])) {
            $signed = CarbonImmutable::parse((string) $agreement['signed_on'])->startOfDay();
            $delivered = empty($agreement['disclosure_delivered_on']) ? null : CarbonImmutable::parse((string) $agreement['disclosure_delivered_on'])->startOfDay();

            if ($delivered === null || (int) $delivered->diffInDays($signed) < self::DisclosureDays) {
                $findings[] = self::finding('disclosure_period_too_short', 'warning', ['days' => self::DisclosureDays]);
            }
        }

        if (Dec::cmp($agreement['entry_fee'] ?? 0, 0) > 0 && (int) ($agreement['entry_fee_deferral_months'] ?? 0) === 0) {
            $findings[] = self::finding('entry_fee_not_deferred', 'warning');
        }

        if ((int) ($agreement['entry_fee_deferral_months'] ?? 0) > $term) {
            $findings[] = self::finding('entry_fee_deferral_exceeds_term', 'error');
        }

        $paymentMethod = PaymentMethod::tryFrom((string) ($agreement['payment_method'] ?? 'bank_transfer'));

        if (in_array($paymentMethod, [PaymentMethod::SepaSdd, PaymentMethod::LsvPlus], true) && empty($agreement['mandate_reference'])) {
            $findings[] = self::finding('mandate_missing', 'warning');
        }

        if ($paymentMethod === PaymentMethod::LsvPlus && $jurisdiction === Jurisdiction::It) {
            $findings[] = self::finding('lsv_plus_requires_swiss_account', 'warning');
        }

        $guaranteeType = GuaranteeType::tryFrom((string) ($agreement['guarantee_type'] ?? 'none')) ?? GuaranteeType::None;

        if ($guaranteeType !== GuaranteeType::None) {
            if (empty($agreement['guarantee_expires_on'])) {
                $findings[] = self::finding('guarantee_expiry_missing', 'warning');
            } else {
                $expires = CarbonImmutable::parse((string) $agreement['guarantee_expires_on'])->startOfDay();

                if ($expires->lessThan($today)) {
                    $findings[] = self::finding('guarantee_expired', 'error');
                } elseif ((int) $today->diffInDays($expires) <= self::GuaranteeWarningDays) {
                    $findings[] = self::finding('guarantee_expiring', 'warning', ['days' => (int) $today->diffInDays($expires)]);
                }

                if ($expires->lessThan($end)) {
                    $findings[] = self::finding('guarantee_ends_before_agreement', 'warning');
                }
            }
        }

        if ($end->lessThanOrEqualTo($start)) {
            $findings[] = self::finding('end_before_start', 'error');
        }

        return $findings;
    }

    /**
     * Findings that depend on today's date and not on the contract text: the agreement is about to end or the guarantee about to lapse.
     *
     * @param  array<string, mixed>  $agreement
     * @return list<array{code: string, severity: string, params: array<string, scalar>}>
     */
    public static function alerts(array $agreement, CarbonImmutable $today): array
    {
        $alerts = [];
        $end = CarbonImmutable::parse((string) $agreement['end_date'])->startOfDay();
        $noticeDays = (int) ($agreement['renewal_notice_days'] ?? 180);

        if ($end->lessThan($today)) {
            $alerts[] = self::finding('agreement_expired', 'error');
        } else {
            $left = (int) $today->diffInDays($end);

            if ($left <= $noticeDays) {
                $alerts[] = self::finding('renewal_due', 'warning', ['days' => $left]);
            }
        }

        foreach (self::evaluate($agreement, $today) as $finding) {
            if (in_array($finding['code'], ['guarantee_expired', 'guarantee_expiring'], true)) {
                $alerts[] = $finding;
            }
        }

        return $alerts;
    }

    /**
     * True when two exclusivity zones claim a same postal code (a franchisee holds the exclusive right on its zone).
     *
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>|null  $b
     */
    public static function zonesOverlap(?array $a, ?array $b): bool
    {
        $left = array_map('strval', (array) ($a['postal_codes'] ?? []));
        $right = array_map('strval', (array) ($b['postal_codes'] ?? []));

        return $left !== [] && array_intersect($left, $right) !== [];
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array{code: string, severity: string, params: array<string, scalar>}
     */
    private static function finding(string $code, string $severity, array $params = []): array
    {
        return ['code' => $code, 'severity' => $severity, 'params' => $params];
    }
}
