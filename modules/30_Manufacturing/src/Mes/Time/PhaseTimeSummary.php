<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Time;

use Modules\Manufacturing\Support\Money;

/**
 * Accumulated times of a phase in whole seconds, with the derived man time and machine time.
 *
 * Man time (Italian: tempo uomo) is setup plus attended run; machine time (tempo macchina) is setup plus every run,
 * attended or not. Suspensions count as neither: they are downtime (fermo).
 */
final readonly class PhaseTimeSummary
{
    /**
     * @param  array<string, int>  $manSecondsByOperator  Man seconds keyed by operator id ('' for no operator).
     * @param  array<string, int>  $downtimeSecondsByReason  Suspension seconds keyed by reason code.
     */
    public function __construct(
        public int $setupSeconds,
        public int $attendedRunSeconds,
        public int $unattendedRunSeconds,
        public int $downtimeSeconds,
        public array $manSecondsByOperator,
        public array $downtimeSecondsByReason,
    ) {}

    /**
     * Run seconds, attended or not.
     */
    public function runSeconds(): int
    {
        return $this->attendedRunSeconds + $this->unattendedRunSeconds;
    }

    /**
     * Man seconds: setup plus attended run.
     */
    public function manSeconds(): int
    {
        return $this->setupSeconds + $this->attendedRunSeconds;
    }

    /**
     * Machine seconds: setup plus every run.
     */
    public function machineSeconds(): int
    {
        return $this->setupSeconds + $this->runSeconds();
    }

    /**
     * Seconds as minutes with two decimals, the unit of operation confirmations.
     */
    public static function minutes(int $seconds): string
    {
        return Money::round(Money::div((string) $seconds, '60'));
    }

    /**
     * The summary in the shape of the API: seconds, which clients add to a live counter, and minutes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'setup_seconds' => $this->setupSeconds,
            'run_seconds' => $this->runSeconds(),
            'attended_run_seconds' => $this->attendedRunSeconds,
            'unattended_run_seconds' => $this->unattendedRunSeconds,
            'downtime_seconds' => $this->downtimeSeconds,
            'man_seconds' => $this->manSeconds(),
            'machine_seconds' => $this->machineSeconds(),
            'setup_minutes' => self::minutes($this->setupSeconds),
            'run_minutes' => self::minutes($this->runSeconds()),
            'downtime_minutes' => self::minutes($this->downtimeSeconds),
            'man_minutes' => self::minutes($this->manSeconds()),
            'machine_minutes' => self::minutes($this->machineSeconds()),
            'man_seconds_by_operator' => $this->manSecondsByOperator,
            'downtime_seconds_by_reason' => $this->downtimeSecondsByReason,
        ];
    }
}
