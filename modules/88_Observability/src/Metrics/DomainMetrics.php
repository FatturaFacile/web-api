<?php

declare(strict_types=1);

namespace Modules\Observability\Metrics;

use Closure;
use Throwable;

/**
 * Entry point of the business metrics for the other modules: QR-bill PDFs, fiscal transmissions (SDI, PEPPOL, RT, eBill, ELM) and MRP runs.
 * Every call is fire-and-forget: a metrics failure never reaches the caller.
 */
final class DomainMetrics
{
    /**
     * Runs the callback and records its duration in the given histogram, also when it throws.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function time(string $histogram, Closure $callback): mixed
    {
        $started = hrtime(true);

        try {
            return $callback();
        } finally {
            self::observe($histogram, (hrtime(true) - $started) / 1_000_000_000);
        }
    }

    public static function observe(string $histogram, float $seconds): void
    {
        try {
            app(MetricsRegistry::class)->observe($histogram, $seconds);
        } catch (Throwable) {
            // see class doc
        }
    }

    /**
     * @param  'SDI'|'PEPPOL'|'RT'|'EBILL'|'ELM'  $channel
     * @param  'success'|'failure'  $status
     */
    public static function fiscalTransmission(string $channel, string $status): void
    {
        try {
            app(MetricsRegistry::class)->increment(MetricCatalog::FISCAL_TRANSMISSION, ['channel' => strtoupper($channel), 'status' => $status]);
        } catch (Throwable) {
            // see class doc
        }
    }

    /**
     * Counts the outcome of a transmission around the callback (success when it returns, failure when it throws).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function transmission(string $channel, Closure $callback): mixed
    {
        try {
            $result = $callback();
        } catch (Throwable $exception) {
            self::fiscalTransmission($channel, 'failure');

            throw $exception;
        }

        self::fiscalTransmission($channel, 'success');

        return $result;
    }
}
