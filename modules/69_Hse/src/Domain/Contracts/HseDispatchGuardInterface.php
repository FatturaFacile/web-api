<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Contracts;

use Carbon\CarbonImmutable;
use Modules\Hse\Domain\Enums\ClearanceContext;
use Modules\Hse\Domain\ValueObjects\ClearanceResult;

/**
 * The call site used by MES (Mod 064), field service (Mod 084) and construction sites (Mod 108): resolves the worker
 * from an employee or a user id and enforces the hard safety block.
 */
interface HseDispatchGuardInterface
{
    /**
     * Throws a ValidationException (key "hse") when the worker is not cleared. A user or technician that is not an
     * employee has no safety dossier and is let through.
     */
    public function assertCleared(string $tenantId, ?string $employeeId, ?string $userId, ClearanceContext $context, string $targetId, ?CarbonImmutable $at = null): ClearanceResult;
}
