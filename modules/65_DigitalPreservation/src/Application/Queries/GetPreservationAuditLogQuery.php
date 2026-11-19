<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Queries;

/**
 * Paginated audit trail.
 */
final readonly class GetPreservationAuditLogQuery
{
    public function __construct(public ?string $action = null, public ?string $batchId = null, public int $perPage = 50) {}
}
