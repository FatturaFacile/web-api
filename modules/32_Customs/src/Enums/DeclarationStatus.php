<?php

declare(strict_types=1);

namespace Modules\Customs\Enums;

enum DeclarationStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * Whether the content of the declaration can still change.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Validated, self::Rejected], true);
    }
}
