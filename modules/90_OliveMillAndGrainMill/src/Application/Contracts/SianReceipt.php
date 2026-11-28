<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Contracts;

/**
 * Acknowledgement of a SIAN transmission: the protocol number of the receipt, or the reason it was refused.
 */
final readonly class SianReceipt
{
    public function __construct(public bool $accepted, public ?string $protocol, public ?string $message = null) {}
}
