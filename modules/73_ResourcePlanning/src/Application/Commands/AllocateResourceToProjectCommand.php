<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Commands;

use Carbon\CarbonImmutable;
use Modules\ResourcePlanning\Domain\Enums\BookingType;

/**
 * Books an employee on a project (and WBS node) or holds them for an open CRM opportunity.
 */
final readonly class AllocateResourceToProjectCommand
{
    /**
     * @param  list<string>  $requiredSkillIds  skills the booking needs: a lapsed skill or certificate becomes a warning
     */
    public function __construct(
        public string $tenantId,
        public ?string $userId,
        public string $employeeId,
        public BookingType $bookingType,
        public CarbonImmutable $startDate,
        public CarbonImmutable $endDate,
        public float $hoursPerDay,
        public ?string $projectId = null,
        public ?string $wbsNodeId = null,
        public ?string $roleId = null,
        public bool $billable = true,
        public ?string $opportunityId = null,
        public ?string $notes = null,
        public bool $allowOverload = true,
        public array $requiredSkillIds = [],
    ) {}
}
