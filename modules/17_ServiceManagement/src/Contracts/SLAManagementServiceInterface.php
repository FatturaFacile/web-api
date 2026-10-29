<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Contracts;

interface SLAManagementServiceInterface
{
    public function calculateDueDate(?string $slaPolicyId, string $priority, string $type = 'resolution'): ?string;

    public function checkAndRecordBreaches(): int;
}
