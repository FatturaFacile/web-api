<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Application\Commands;

final readonly class SubmitLeaveRequestCommand
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $tenantId,
        public array $payload,
        public ?string $actorUserId = null,
    ) {}
}
