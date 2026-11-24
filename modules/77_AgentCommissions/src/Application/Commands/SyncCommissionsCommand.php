<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Commands;

final readonly class SyncCommissionsCommand
{
    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
    ) {}
}
