<?php

declare(strict_types=1);

namespace Modules\CRM\Contracts;

use DateTimeInterface;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadActivityEvent;

interface LeadEventServiceInterface
{
    /**
     * Records a behavioural event (idempotent on external_id) and re-scores the lead when it is new.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{event: LeadActivityEvent, created: bool, lead: Lead}
     */
    public function record(
        Lead $lead,
        string $type,
        ?DateTimeInterface $occurredAt = null,
        array $metadata = [],
        ?string $externalId = null,
    ): array;
}
