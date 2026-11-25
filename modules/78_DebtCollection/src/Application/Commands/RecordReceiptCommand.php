<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Commands;

final readonly class RecordReceiptCommand
{
    public function __construct(
        public string $noticeId,
        public string $kind,
        public ?string $messageId,
        public ?string $receivedAt,
        public ?string $notes,
        public ?string $actorId,
    ) {}
}
