<?php

declare(strict_types=1);

namespace Modules\Observability\Profiler;

use Illuminate\Support\Facades\DB;
use Modules\Observability\Domain\Enums\PlanStatus;
use Modules\Observability\Privacy\SqlSanitizer;
use Throwable;

/**
 * Obtains the execution plan of a captured query on PostgreSQL. Only a single read-only SELECT is ever explained; the plan is requested with
 * ANALYZE off (nothing runs) unless the platform enabled ANALYZE, which then runs inside a READ ONLY transaction with a statement timeout that is
 * always rolled back, so a mutation can never be re-executed. The plan is stripped of the literals before it is stored.
 */
final class ExplainRunner
{
    private const string FORBIDDEN = '/\b(insert|update|delete|merge|truncate|alter|drop|create|grant|revoke|copy|call|do|lock|vacuum|refresh|set|nextval|setval|pg_advisory\w*|pg_sleep\w*|dblink\w*|lo_\w+|into\s+\w+)\b/i';

    public function __construct(private readonly SqlSanitizer $sanitizer) {}

    /**
     * A single SELECT / WITH ... SELECT statement without any write or side-effect function.
     */
    public function isExplainable(string $sql): bool
    {
        $statement = rtrim(trim($sql), ';');

        if ($statement === '' || str_contains($statement, ';') || ! $this->sanitizer->isSelect($statement)) {
            return false;
        }

        // The words are checked on the literal-free text, so a string such as 'update' does not block the statement.
        return preg_match(self::FORBIDDEN, $this->sanitizer->normalize($statement)) !== 1
            && preg_match('/\bfor\s+(update|share|no\s+key\s+update|key\s+share)\b/i', $statement) !== 1;
    }

    /**
     * @param  list<mixed>  $bindings
     * @return array{status: PlanStatus, plan: array<array-key, mixed>|null}
     */
    public function explain(string $connectionName, string $sql, array $bindings, bool $analyze, int $timeoutMs): array
    {
        $connection = DB::connection($connectionName);

        if ($connection->getDriverName() !== 'pgsql') {
            return ['status' => PlanStatus::Unsupported, 'plan' => null];
        }

        // SET TRANSACTION READ ONLY is only legal before the first statement of a transaction: never inside the caller's one.
        if (! $this->isExplainable($sql) || $connection->transactionLevel() > 0) {
            return ['status' => PlanStatus::Skipped, 'plan' => null];
        }

        try {
            $connection->beginTransaction();
            $connection->statement('SET TRANSACTION READ ONLY');
            $connection->statement('SET LOCAL statement_timeout = '.max(100, $timeoutMs));

            $options = $analyze ? 'ANALYZE true, BUFFERS true, FORMAT JSON' : 'ANALYZE false, '.$this->supportsPlanningBuffers($connectionName).'FORMAT JSON';
            $rows = $connection->select('EXPLAIN ('.$options.') '.rtrim(trim($sql), ';'), $bindings);
            $raw = $rows === [] ? null : ((array) $rows[0])['QUERY PLAN'] ?? null;
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            $plan = is_array($decoded) && isset($decoded[0]) && is_array($decoded[0]) ? $decoded[0] : null;
        } catch (Throwable) {
            return ['status' => PlanStatus::Failed, 'plan' => null];
        } finally {
            try {
                $connection->rollBack();
            } catch (Throwable) {
                // nothing left to roll back
            }
        }

        if ($plan === null) {
            return ['status' => PlanStatus::Failed, 'plan' => null];
        }

        return ['status' => PlanStatus::Available, 'plan' => $this->sanitizer->sanitizePlan($plan)];
    }

    /**
     * BUFFERS without ANALYZE (planning buffers) exists from PostgreSQL 18; older servers reject the option.
     */
    private function supportsPlanningBuffers(string $connectionName): string
    {
        try {
            $version = (int) (((array) DB::connection($connectionName)->selectOne('SHOW server_version_num'))['server_version_num'] ?? 0);
        } catch (Throwable) {
            return '';
        }

        return $version >= 180000 ? 'BUFFERS true, ' : '';
    }
}
