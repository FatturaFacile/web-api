<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

use Modules\IndustrialLaundry\Services\LockerService;

final class GetLockerAssignmentsHandler
{
    public function __construct(private readonly LockerService $lockers) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(GetLockerAssignmentsQuery $query): array
    {
        return $this->lockers->assignments($query->customerId, $query->search);
    }
}
