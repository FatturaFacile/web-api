<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Commands;

final readonly class PrepareSelfBillingCommand
{
    public function __construct(
        public string $statementId,
        public ?string $date = null,
    ) {}
}
