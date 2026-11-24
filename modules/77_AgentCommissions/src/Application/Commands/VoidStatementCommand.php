<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Commands;

final readonly class VoidStatementCommand
{
    public function __construct(
        public string $statementId,
    ) {}
}
