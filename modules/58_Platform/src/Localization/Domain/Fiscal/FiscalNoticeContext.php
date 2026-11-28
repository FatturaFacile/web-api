<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Fiscal;

/**
 * What a document is, as far as its mandatory legal texts are concerned.
 */
final readonly class FiscalNoticeContext
{
    /**
     * @param  string|null  $naturaCode  FatturaPA natura code (N1 to N7, with or without sub-code) of a VAT-free line
     * @param  string  $documentLocale  the locale the document is printed in
     */
    public function __construct(
        public string $documentLocale = 'it-IT',
        public bool $flatRateVat = false,
        public bool $qrBill = false,
        public bool $privacyNotice = false,
        public ?string $naturaCode = null,
        public bool $exemptArt10 = false,
        public bool $splitPayment = false,
        public bool $reverseCharge = false,
        public bool $virtualStamp = false,
    ) {}
}
