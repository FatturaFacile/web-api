<?php

declare(strict_types=1);

namespace Modules\Observability\Profiler;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Observability\Privacy\PiiScrubber;
use Modules\Observability\Privacy\SqlSanitizer;
use Throwable;

/**
 * Reads PostgreSQL pg_stat_statements (the slowest statements by mean execution time). When the extension is not available the report says why and
 * how to enable it, instead of failing.
 */
final class PostgresStatStatements
{
    public function __construct(private readonly SqlSanitizer $sanitizer, private readonly PiiScrubber $scrubber) {}

    /**
     * @return array{available: bool, reason: string|null, hint: string|null, statements: list<array{query: string, calls: int, mean_ms: float, total_ms: float, rows: int}>}
     */
    public function top(int $limit = 20): array
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return $this->unavailable('not_postgres');
        }

        try {
            $installed = $connection->selectOne("SELECT 1 AS present FROM pg_extension WHERE extname = 'pg_stat_statements'");

            if ($installed === null) {
                return $this->unavailable('extension_missing');
            }

            try {
                $rows = $connection->select('SELECT query, calls, mean_exec_time AS mean_ms, total_exec_time AS total_ms, rows FROM pg_stat_statements WHERE dbid = (SELECT oid FROM pg_database WHERE datname = current_database()) ORDER BY mean_exec_time DESC LIMIT ?', [$limit]);
            } catch (QueryException $exception) {
                if (str_contains($exception->getMessage(), 'mean_exec_time')) {
                    // PostgreSQL 12 and older name the columns mean_time / total_time.
                    $rows = $connection->select('SELECT query, calls, mean_time AS mean_ms, total_time AS total_ms, rows FROM pg_stat_statements WHERE dbid = (SELECT oid FROM pg_database WHERE datname = current_database()) ORDER BY mean_time DESC LIMIT ?', [$limit]);
                } else {
                    throw $exception;
                }
            }
        } catch (Throwable $exception) {
            $message = $exception->getMessage();

            return $this->unavailable(str_contains($message, '42501') || str_contains(strtolower($message), 'permission denied') ? 'permission_denied' : 'extension_missing');
        }

        $statements = [];

        foreach ($rows as $row) {
            $statements[] = [
                'query' => substr($this->scrubber->scrubString($this->sanitizer->normalize((string) $row->query)), 0, 2000),
                'calls' => (int) $row->calls,
                'mean_ms' => round((float) $row->mean_ms, 3),
                'total_ms' => round((float) $row->total_ms, 3),
                'rows' => (int) $row->rows,
            ];
        }

        return ['available' => true, 'reason' => null, 'hint' => null, 'statements' => $statements];
    }

    /**
     * @return array{available: bool, reason: string|null, hint: string|null, statements: list<array{query: string, calls: int, mean_ms: float, total_ms: float, rows: int}>}
     */
    private function unavailable(string $reason): array
    {
        return ['available' => false, 'reason' => $reason, 'hint' => __('observability.hints.'.$reason), 'statements' => []];
    }
}
