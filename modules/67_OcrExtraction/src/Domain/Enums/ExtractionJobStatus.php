<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Domain\Enums;

enum ExtractionJobStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case ReadyForReview = 'ready_for_review';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Rejected = 'rejected';

    /**
     * States shown in the AI Inbox queue.
     *
     * @return list<self>
     */
    public static function inbox(): array
    {
        return [self::Queued, self::Processing, self::ReadyForReview, self::Failed];
    }

    public function isFinal(): bool
    {
        return $this === self::Confirmed || $this === self::Rejected;
    }
}
