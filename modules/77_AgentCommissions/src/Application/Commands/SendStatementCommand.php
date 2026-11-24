<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Commands;

final readonly class SendStatementCommand
{
    public function __construct(
        public string $statementId,
        public string $locale,
        public ?string $recipient = null,
    ) {}
}
