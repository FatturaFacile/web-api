<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Events;

/**
 * A veterinary treatment was entered in the register: the apiary (or the treated hives) is now locked for honey until the withdrawal ends.
 */
final readonly class VarroaTreatmentRegistered
{
    public function __construct(public string $treatmentId, public string $apiaryId, public string $withdrawalEndDate) {}
}
