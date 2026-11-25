<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Commands;

final readonly class SendNoticeCommand
{
    public function __construct(
        public string $noticeId,
        public ?string $channel,
        public ?string $actorId,
    ) {}
}
