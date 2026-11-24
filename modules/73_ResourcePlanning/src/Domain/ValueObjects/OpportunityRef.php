<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\ValueObjects;

/**
 * An opportunity of the CRM (Mod 035); only an open one can hold a tentative booking.
 */
final readonly class OpportunityRef
{
    public function __construct(public string $id, public string $name, public bool $isOpen) {}
}
