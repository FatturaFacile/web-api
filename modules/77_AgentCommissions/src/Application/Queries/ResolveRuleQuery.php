<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Queries;

final readonly class ResolveRuleQuery
{
    public function __construct(
        public string $agentProfileId,
        public ?string $customerId,
        public ?string $productCategoryId,
        public string $date,
    ) {}
}
