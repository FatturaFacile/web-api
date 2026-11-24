<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An SLA escalation step fired on a ticket (#43). Push gateways and real-time channels listen to it to alert the managers
 * and agents the step names.
 */
final readonly class SlaEscalationStepFired implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<string>  $recipientUserIds
     */
    public function __construct(
        public string $tenantId,
        public string $ticketId,
        public string $ticketNumber,
        public string $priority,
        public string $target,
        public int $thresholdPercent,
        public int $elapsedPercent,
        public string $action,
        public array $recipientUserIds,
    ) {}
}
