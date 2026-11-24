<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * In-process notice that a helpdesk ticket changed, for notifications and outbound e-mail. Dispatched once the
 * transaction that made the change has committed.
 */
final class HelpdeskTicketChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const string Created = 'ticket_created';

    public const string Replied = 'ticket_replied';

    public const string StatusChanged = 'status_changed';

    public const string Assigned = 'ticket_assigned';

    public const string SlaWarning = 'sla_warning';

    public const string SlaBreached = 'sla_breached';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $ticketId,
        public readonly string $tenantId,
        public readonly string $type,
        public readonly ?string $actorUserId = null,
        public readonly array $payload = [],
    ) {}

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [self::Created, self::Replied, self::StatusChanged, self::Assigned, self::SlaWarning, self::SlaBreached];
    }
}
