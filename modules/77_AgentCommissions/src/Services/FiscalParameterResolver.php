<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Services;

use Illuminate\Support\Collection;
use Modules\AgentCommissions\Exceptions\AgentCommissionException;
use Modules\AgentCommissions\Models\FiscalParameter;

/**
 * Reads the configurable fiscal parameters (Enasarco rates, ceilings and floors, FIRR brackets, withholding, VAT).
 * Nothing is hardcoded in the calculators: the seeder ships the global rows (tenant_id null) and a tenant may override
 * any of them. Resolution order: the tenant row beats the global row, a row of the mandate beats a row for any mandate,
 * and the latest year not after the requested one wins.
 */
final class FiscalParameterResolver
{
    /** @var array<string, Collection<int, FiscalParameter>> */
    private array $cache = [];

    public function find(string $tenantId, string $code, ?string $mandate, int $year): ?FiscalParameter
    {
        $candidates = $this->rows($tenantId, $code)
            ->filter(static fn (FiscalParameter $row): bool => ($row->mandate_type === null || $row->mandate_type === $mandate)
                && ($row->year === null || (int) $row->year <= $year));

        return $candidates
            ->sortByDesc(static fn (FiscalParameter $row): string => sprintf(
                '%d-%d-%05d',
                $row->tenant_id === null ? 0 : 1,
                $row->mandate_type === null ? 0 : 1,
                $row->year ?? 0,
            ))
            ->first();
    }

    public function value(string $tenantId, string $code, ?string $mandate, int $year): string
    {
        $row = $this->find($tenantId, $code, $mandate, $year);

        if ($row === null || $row->value === null) {
            throw new AgentCommissionException("Fiscal parameter [{$code}] is not configured for {$year}.");
        }

        return (string) $row->value;
    }

    public function valueOrNull(string $tenantId, string $code, ?string $mandate, int $year): ?string
    {
        $row = $this->find($tenantId, $code, $mandate, $year);

        return $row?->value === null ? null : (string) $row->value;
    }

    /**
     * @return list<array{up_to: string|null, rate: string}>
     */
    public function brackets(string $tenantId, string $code, ?string $mandate, int $year): array
    {
        $row = $this->find($tenantId, $code, $mandate, $year);
        $payload = $row?->payload;

        if (! is_array($payload) || $payload === []) {
            return [];
        }

        return array_values(array_map(static fn (array $bracket): array => [
            'up_to' => isset($bracket['up_to']) ? (string) $bracket['up_to'] : null,
            'rate' => (string) $bracket['rate'],
        ], $payload));
    }

    /**
     * @return Collection<int, FiscalParameter>
     */
    private function rows(string $tenantId, string $code): Collection
    {
        return $this->cache[$tenantId.'|'.$code] ??= FiscalParameter::query()->where('code', $code)->get();
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
