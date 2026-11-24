<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

/**
 * Priority of a ticket from its impact and urgency, on the matrix of config/helpdesk.php.
 */
final class TicketPriorityMatrix
{
    /** @var list<string> */
    public const array Levels = ['low', 'medium', 'high'];

    public function priorityFor(?string $impact, ?string $urgency): ?string
    {
        if ($impact === null || $urgency === null) {
            return null;
        }

        $matrix = (array) config('helpdesk.priority_matrix', []);
        $priority = $matrix[$impact][$urgency] ?? null;

        return is_string($priority) ? $priority : null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function matrix(): array
    {
        return (array) config('helpdesk.priority_matrix', []);
    }
}
