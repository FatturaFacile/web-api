<?php

declare(strict_types=1);

namespace Modules\Customs\Enums;

/**
 * The document types of the edecReceipt schema v3.0.
 */
enum AssessmentDocumentType: string
{
    case CustomsDuties = 'taxationDecisionCustomsDuties';
    case Vat = 'taxationDecisionVAT';
    case Export = 'taxationDecisionExport';
    case RefundCustomsDuties = 'refundCustomsDuties';
    case RefundVat = 'refundVAT';

    public function direction(): string
    {
        return $this === self::Export ? 'export' : 'import';
    }

    public function isRefund(): bool
    {
        return in_array($this, [self::RefundCustomsDuties, self::RefundVat], true);
    }
}
