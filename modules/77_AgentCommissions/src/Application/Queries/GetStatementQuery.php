<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Queries;

final readonly class GetStatementQuery
{
    public function __construct(
        public string $statementId,
    ) {}
}
