<?php

declare(strict_types=1);

namespace Modules\Workflow\DTOs;

use Illuminate\Database\Eloquent\Model;

final readonly class StartWorkflowDTO
{
    /**
     * @param  array<string, mixed>  $contextSnapshot
     */
    public function __construct(
        public string $workflowCode,
        public Model $approvable,
        public array $contextSnapshot = [],
        public ?string $tenantId = null,
        public ?string $companyId = null,
        public ?string $initiatorUserId = null
    ) {}
}
