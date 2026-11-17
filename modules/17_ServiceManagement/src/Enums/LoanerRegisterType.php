<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Enums;

/**
 * Register type: "prestito d'uso" (loaner unit lent to the customer) or
 * "conto riparazione" (customer unit held in the lab, no ownership transfer).
 */
enum LoanerRegisterType: string
{
    case PrestitoUso = 'prestito_uso';
    case ContoRiparazione = 'conto_riparazione';
}
