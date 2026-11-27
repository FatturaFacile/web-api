<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Exceptions;

use Modules\SwissEbill\Domain\Enums\EbillTransmissionStatus;

final class InvalidTransitionException extends EbillException
{
    public function __construct(public readonly EbillTransmissionStatus $from, public readonly EbillTransmissionStatus $to)
    {
        parent::__construct('invalid_transition', "An eBill transmission cannot move from {$from->value} to {$to->value}.", 409);
    }
}
