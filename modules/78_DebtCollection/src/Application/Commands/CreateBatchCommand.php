<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Commands;

final readonly class CreateBatchCommand
{
    public function __construct(
        public string $tenantId,
        /** @var list<string> */
        public array $noticeIds,
        public string $channel,
        public ?string $actorId,
    ) {}
}
