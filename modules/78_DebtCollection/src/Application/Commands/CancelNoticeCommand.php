<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Commands;

final readonly class CancelNoticeCommand
{
    public function __construct(
        public string $noticeId,
        public ?string $actorId,
    ) {}
}
