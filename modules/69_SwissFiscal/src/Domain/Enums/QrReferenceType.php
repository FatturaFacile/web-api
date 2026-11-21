<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Domain\Enums;

/** Reference type of a Swiss QR-bill. */
enum QrReferenceType: string
{
    /** 27-digit QR reference, only with a QR-IBAN. */
    case QrReference = 'QRR';

    /** ISO 11649 creditor reference, only with a regular IBAN. */
    case CreditorReference = 'SCOR';

    /** No reference, only with a regular IBAN. */
    case None = 'NON';
}
