<?php

declare(strict_types=1);

namespace Modules\CRM\Contracts;

use Modules\CRM\DTOs\RoutingResult;
use Modules\CRM\Models\Lead;

interface LeadRoutingServiceInterface
{
    /**
     * Assigns the lead using the first matching active rule (by priority), else the default rule.
     * Notifies the assignee, or the tenant administrators when nobody could be assigned.
     */
    public function route(Lead $lead, ?string $actorUserId = null): RoutingResult;

    /**
     * @param  array<string, mixed>|null  $conditions
     */
    public function matches(Lead $lead, ?array $conditions): bool;

    /**
     * Rewrites rule priorities following the given order (10, 20, 30, ...). Rules not listed keep their
     * relative order after the listed ones.
     *
     * @param  list<string>  $ruleIds
     */
    public function reorder(string $tenantId, array $ruleIds): void;
}
