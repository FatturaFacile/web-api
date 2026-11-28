<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Events;

/**
 * A batch of oil register operations was sent to the SIAN.
 */
final readonly class SianOperationsTransmitted
{
    /**
     * @param  list<string>  $movementIds
     */
    public function __construct(
        public array $movementIds,
        public bool $accepted,
        public ?string $protocol,
    ) {}
}
