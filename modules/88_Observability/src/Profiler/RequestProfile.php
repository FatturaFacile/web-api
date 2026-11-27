<?php

declare(strict_types=1);

namespace Modules\Observability\Profiler;

/**
 * What the database did during one request, job or command: the query count and time, the repeats per fingerprint (N+1) and the slow queries.
 * Bindings stay in memory until the profile is flushed and are never persisted.
 */
final class RequestProfile
{
    public ?string $traceId = null;

    public ?string $originType = null;

    public ?string $origin = null;

    public int $queryCount = 0;

    public float $dbMs = 0.0;

    /** @var array<string, array{count: int, total_ms: float, sql: string, select: bool}> */
    private array $fingerprints = [];

    /** @var list<array{fingerprint: string, sql: string, raw_sql: string, bindings: list<mixed>, ms: float, connection: string}> */
    private array $slow = [];

    public function recordQuery(string $fingerprint, string $normalizedSql, float $ms, bool $select): void
    {
        $this->queryCount++;
        $this->dbMs += $ms;
        $entry = $this->fingerprints[$fingerprint] ?? ['count' => 0, 'total_ms' => 0.0, 'sql' => $normalizedSql, 'select' => $select];
        $entry['count']++;
        $entry['total_ms'] += $ms;
        $this->fingerprints[$fingerprint] = $entry;
    }

    /**
     * @param  list<mixed>  $bindings
     */
    public function addSlow(string $fingerprint, string $normalizedSql, string $rawSql, array $bindings, float $ms, string $connection, int $max): void
    {
        if (count($this->slow) < $max) {
            $this->slow[] = ['fingerprint' => $fingerprint, 'sql' => $normalizedSql, 'raw_sql' => $rawSql, 'bindings' => $bindings, 'ms' => $ms, 'connection' => $connection];
        }
    }

    /**
     * @return array<string, array{count: int, total_ms: float, sql: string, select: bool}>
     */
    public function fingerprints(): array
    {
        return $this->fingerprints;
    }

    /**
     * @return list<array{fingerprint: string, sql: string, raw_sql: string, bindings: list<mixed>, ms: float, connection: string}>
     */
    public function slowQueries(): array
    {
        return $this->slow;
    }

    /**
     * Fingerprints of SELECT statements that ran at least $threshold times (the N+1 signature).
     *
     * @return array<string, array{count: int, total_ms: float, sql: string, select: bool}>
     */
    public function repeated(int $threshold): array
    {
        return array_filter($this->fingerprints, static fn (array $entry): bool => $entry['select'] && $entry['count'] >= $threshold);
    }

    public function clearQueries(): void
    {
        $this->queryCount = 0;
        $this->dbMs = 0.0;
        $this->fingerprints = [];
        $this->slow = [];
    }
}
