<?php

declare(strict_types=1);

namespace Modules\Workflow\Enums;

/**
 * What an approver can do with a pending step. The API accepts both the present form (`approve`) and the stored past form
 * (`approved`).
 */
enum DecisionType: string
{
    case Approve = 'approved';
    case Reject = 'rejected';
    case RequestChanges = 'request_changes';
    case Reassign = 'reassign';

    /**
     * Normalises client input (`approve`, `approved`, `reject`, ...) to a case, or null when unknown.
     */
    public static function fromInput(string $value): ?self
    {
        return match (strtolower(trim($value))) {
            'approve', 'approved' => self::Approve,
            'reject', 'rejected' => self::Reject,
            'request_changes' => self::RequestChanges,
            'reassign' => self::Reassign,
            default => null,
        };
    }

    /**
     * Whether a written reason is mandatory.
     */
    public function requiresReason(): bool
    {
        return in_array($this, [self::Reject, self::RequestChanges], true);
    }
}
