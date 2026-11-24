<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Commands;

final readonly class MarkStatementPaidCommand
{
    public function __construct(
        public string $statementId,
        public ?string $reference = null,
        public ?string $paidOn = null,
    ) {}
}
