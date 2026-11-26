<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Enums;

/**
 * What the company does with an unrealised exchange gain (losses are always booked, CO Art. 960a imparity principle):
 * recognise it in the income statement, book it to a reserve/provision account, or leave it out.
 */
enum UnrealizedGainTreatment: string
{
    case Recognise = 'recognise';
    case Provision = 'provision';
    case Suppress = 'suppress';
}
