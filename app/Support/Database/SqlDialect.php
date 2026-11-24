<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * SQL fragments for the few expressions the query builder has no portable method for. The product runs on PostgreSQL,
 * SQLite and MySQL/MariaDB, so no query may use a function of a single engine directly: it goes through this class,
 * which returns the spelling of the connection in use. Column arguments are trusted SQL, never user input.
 */
final class SqlDialect
{
    /**
     * Characters-typed copy of a column, e.g. to group or join integers with text keys.
     */
    public static function castText(string $column): string
    {
        return match (self::driver()) {
            'mysql', 'mariadb' => "CAST({$column} AS CHAR)",
            default => "CAST({$column} AS TEXT)",
        };
    }

    /**
     * Month of a date column as an integer, 1 to 12.
     */
    public static function monthNumber(string $column): string
    {
        return match (self::driver()) {
            'sqlite' => "CAST(strftime('%m', {$column}) AS INTEGER)",
            'mysql', 'mariadb' => "MONTH({$column})",
            default => "CAST(EXTRACT(MONTH FROM {$column}) AS INTEGER)",
        };
    }

    /**
     * Calendar date of a date or timestamp column, as a `Y-m-d` value comparable with a bound date.
     */
    public static function dateOf(string $column): string
    {
        return match (self::driver()) {
            'sqlite' => "date({$column})",
            default => "CAST({$column} AS DATE)",
        };
    }

    /**
     * `YYYY-MM` bucket of a date column.
     */
    public static function monthBucket(string $column): string
    {
        return match (self::driver()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m')",
            default => "to_char({$column}, 'YYYY-MM')",
        };
    }

    /**
     * `YYYY-MM-DD` of the first day of the bucket the column falls in. The granularity is one of day, week (Monday
     * first), month, quarter and year.
     */
    public static function periodStart(string $column, string $granularity): string
    {
        return match (self::driver()) {
            'sqlite' => self::sqlitePeriodStart($column, $granularity),
            'mysql', 'mariadb' => self::mysqlPeriodStart($column, $granularity),
            default => $granularity === 'day'
                ? "to_char(CAST({$column} AS DATE), 'YYYY-MM-DD')"
                : "to_char(date_trunc('{$granularity}', CAST({$column} AS TIMESTAMP)), 'YYYY-MM-DD')",
        };
    }

    /**
     * Seconds between two timestamp columns, end minus start.
     */
    public static function secondsBetween(string $start, string $end): string
    {
        return match (self::driver()) {
            'sqlite' => "((julianday({$end}) - julianday({$start})) * 86400.0)",
            'mysql', 'mariadb' => "TIMESTAMPDIFF(SECOND, {$start}, {$end})",
            default => "EXTRACT(EPOCH FROM ({$end} - {$start}))",
        };
    }

    private static function sqlitePeriodStart(string $column, string $granularity): string
    {
        return match ($granularity) {
            'day' => "date({$column})",
            'week' => "date({$column}, '-' || ((CAST(strftime('%w', {$column}) AS INTEGER) + 6) % 7) || ' days')",
            'month' => "strftime('%Y-%m-01', {$column})",
            'quarter' => "(strftime('%Y-', {$column}) || substr('010101040404070707101010', (CAST(strftime('%m', {$column}) AS INTEGER) - 1) * 2 + 1, 2) || '-01')",
            default => "strftime('%Y-01-01', {$column})",
        };
    }

    private static function mysqlPeriodStart(string $column, string $granularity): string
    {
        return match ($granularity) {
            'day' => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            'week' => "DATE_FORMAT(DATE_SUB(DATE({$column}), INTERVAL WEEKDAY({$column}) DAY), '%Y-%m-%d')",
            'month' => "DATE_FORMAT({$column}, '%Y-%m-01')",
            'quarter' => "DATE_FORMAT(MAKEDATE(YEAR({$column}), 1) + INTERVAL (QUARTER({$column}) - 1) QUARTER, '%Y-%m-%d')",
            default => "DATE_FORMAT({$column}, '%Y-01-01')",
        };
    }

    private static function driver(): string
    {
        return DB::connection()->getDriverName();
    }
}
