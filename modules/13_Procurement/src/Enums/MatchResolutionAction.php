<?php

declare(strict_types=1);

namespace Modules\Procurement\Enums;

/**
 * Actions of the exception resolution workflow of a blocked invoice.
 */
enum MatchResolutionAction: string
{
    case RequestCreditNote = 'request_credit_note';
    case Rematch = 'rematch';
    case PartialResolution = 'partial_resolution';
    case Override = 'override';
}
