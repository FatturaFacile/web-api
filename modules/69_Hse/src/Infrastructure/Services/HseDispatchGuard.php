<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Hse\Domain\Contracts\HseClearanceGatekeeperInterface;
use Modules\Hse\Domain\Contracts\HseDispatchGuardInterface;
use Modules\Hse\Domain\Enums\ClearanceContext;
use Modules\Hse\Domain\ValueObjects\BlockingReason;
use Modules\Hse\Domain\ValueObjects\ClearanceRequest;
use Modules\Hse\Domain\ValueObjects\ClearanceResult;
use Modules\HumanResources\Models\Employee;

final class HseDispatchGuard implements HseDispatchGuardInterface
{
    public function __construct(private readonly HseClearanceGatekeeperInterface $gatekeeper) {}

    public function assertCleared(string $tenantId, ?string $employeeId, ?string $userId, ClearanceContext $context, string $targetId, ?CarbonImmutable $at = null): ClearanceResult
    {
        $employee = $this->resolveEmployee($tenantId, $employeeId, $userId);

        if ($employee === null) {
            return new ClearanceResult(true);
        }

        $result = $this->gatekeeper->evaluate(new ClearanceRequest($tenantId, (string) $employee->getKey(), $context, $targetId, $at ?? CarbonImmutable::now()));

        if (! $result->isCleared) {
            throw ValidationException::withMessages([
                'hse' => array_map(
                    static fn (BlockingReason $reason): string => trim("HSE block: {$reason->code->value} {$reason->subject}".($reason->expiresAt !== null ? " (expires {$reason->expiresAt})" : '')),
                    $result->blockingReasons,
                ),
            ]);
        }

        return $result;
    }

    private function resolveEmployee(string $tenantId, ?string $employeeId, ?string $userId): ?Employee
    {
        $query = Employee::query()->where('tenant_id', $tenantId);

        if ($employeeId !== null && $employeeId !== '') {
            return $query->whereKey($employeeId)->first();
        }

        if ($userId !== null && $userId !== '') {
            return $query->where('user_id', $userId)->first();
        }

        return null;
    }
}
