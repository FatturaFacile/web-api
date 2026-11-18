<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Services;

/**
 * Holds the legal entity (company) a request or job is working for, inside the active tenant.
 *
 * - `activeId` narrows every company-scoped query to that company (rows without company stay visible as generic data).
 * - `allowedIds` is the set of companies the current user may see when no company was picked (null = unrestricted).
 * Without either, nothing is filtered and the tenant-wide behaviour is unchanged.
 */
final class CompanyContext
{
    private ?string $activeId = null;

    /** @var list<string>|null */
    private ?array $allowedIds = null;

    public function setActive(?string $companyId): void
    {
        $this->activeId = $companyId !== '' ? $companyId : null;
    }

    public function activeId(): ?string
    {
        return $this->activeId;
    }

    public function hasActive(): bool
    {
        return $this->activeId !== null;
    }

    /**
     * @param  list<string>|null  $companyIds
     */
    public function setAllowed(?array $companyIds): void
    {
        $this->allowedIds = $companyIds === null ? null : array_values(array_unique($companyIds));
    }

    /**
     * @return list<string>|null
     */
    public function allowedIds(): ?array
    {
        return $this->allowedIds;
    }

    public function clear(): void
    {
        $this->activeId = null;
        $this->allowedIds = null;
    }

    /**
     * Run a callback as one company, restoring the previous context afterwards.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runWithCompany(?string $companyId, callable $callback): mixed
    {
        $previousActive = $this->activeId;
        $previousAllowed = $this->allowedIds;

        $this->activeId = $companyId;
        $this->allowedIds = null;

        try {
            return $callback();
        } finally {
            $this->activeId = $previousActive;
            $this->allowedIds = $previousAllowed;
        }
    }
}
