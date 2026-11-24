<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Domain\Enums;

enum SuggestionStatus: string
{
    case Pending = 'pending';
    case AutoApproved = 'auto_approved';
    case Approved = 'approved';
    case EditedApproved = 'edited_approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Failed = 'failed';

    /**
     * States of the review queue.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Pending, self::Failed];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }
}
