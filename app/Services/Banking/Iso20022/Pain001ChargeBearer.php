<?php

declare(strict_types=1);

namespace App\Services\Banking\Iso20022;

/**
 * Who pays the bank charges of a payment (ISO 20022 ChargeBearerType1Code): SLEV follows the SEPA service level and
 * is the default of domestic and SEPA payments, SHAR shares the charges, DEBT lets the debtor bear all of them and
 * CRED the creditor.
 */
enum Pain001ChargeBearer: string
{
    case ServiceLevel = 'SLEV';
    case Shared = 'SHAR';
    case Debtor = 'DEBT';
    case Creditor = 'CRED';
}
