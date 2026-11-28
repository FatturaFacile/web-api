<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Fiscal;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Switzerland: document totals are rounded to 0.05 CHF (Rappenrundung), the VAT number carries the MWST/TVA/IVA suffix
 * with the UID (IDI), flat-rate (Saldosteuersatz) settlements say so, QR-bills follow SIX Swiss Payment Standards v2.3
 * (section headings in the language of the bill, amount without thousands separator) and the nLPD privacy notice applies.
 */
final class SwissFiscalLocalization implements FiscalLocalizationStrategy
{
    public const array QR_BILL_HEADING_KEYS = [
        'statutory.ch.qr_bill.receipt',
        'statutory.ch.qr_bill.payment_part',
        'statutory.ch.qr_bill.account_payable_to',
        'statutory.ch.qr_bill.reference',
        'statutory.ch.qr_bill.additional_information',
        'statutory.ch.qr_bill.payable_by',
        'statutory.ch.qr_bill.currency',
        'statutory.ch.qr_bill.amount',
        'statutory.ch.qr_bill.acceptance_point',
    ];

    public function country(): string
    {
        return 'CH';
    }

    public function roundingIncrement(): string
    {
        return '0.05';
    }

    public function roundDocumentTotal(string $amount): string
    {
        $steps = BigDecimal::of($amount)->dividedBy($this->roundingIncrement(), 0, RoundingMode::HalfUp);

        return (string) $steps->multipliedBy($this->roundingIncrement())->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * The amount as the QR-bill payment part prints it: two decimals, dot, no thousands separator (SIX v2.3).
     */
    public function qrBillAmount(string $amount): string
    {
        return (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
    }

    public function statutoryNoticeKeys(FiscalNoticeContext $context): array
    {
        $keys = ['statutory.ch.vat_suffix', 'statutory.ch.uid_label'];

        if ($context->flatRateVat) {
            $keys[] = 'statutory.ch.flat_rate_notice';
        }

        if ($context->qrBill) {
            $keys = array_merge($keys, self::QR_BILL_HEADING_KEYS);
        }

        if ($context->privacyNotice) {
            $keys[] = 'statutory.ch.nlpd_notice';
        }

        return $keys;
    }
}
