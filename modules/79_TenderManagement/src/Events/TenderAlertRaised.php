<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Events;

/**
 * The scheduled scan raised an alert (a deadline or vault expiry milestone). Listeners deliver it (mail, push, chat).
 */
final readonly class TenderAlertRaised
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public string $alertId,
        public string $tenantId,
        public string $alertType,
        public string $milestone,
        public string $severity,
        public string $messageKey,
        public array $params,
    ) {}
}
