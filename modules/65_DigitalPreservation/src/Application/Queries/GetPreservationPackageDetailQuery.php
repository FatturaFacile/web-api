<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Queries;

/**
 * Full detail of one batch: PdV.xml, items, time stamp and receipt.
 */
final readonly class GetPreservationPackageDetailQuery
{
    public function __construct(public string $batchId) {}
}
