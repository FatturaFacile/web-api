<?php

declare(strict_types=1);

namespace Modules\Observability\Domain\Enums;

/**
 * Rolling status of an institutional connector, ordered by severity for the overall status.
 */
enum ConnectorState: string
{
    case Operational = 'operational';
    case Degraded = 'degraded';
    case Outage = 'outage';
    case Maintenance = 'maintenance';

    public function severity(): int
    {
        return match ($this) {
            self::Operational => 0,
            self::Maintenance => 1,
            self::Degraded => 2,
            self::Outage => 3,
        };
    }

    /** Value of the fatturafacile_connector_status gauge. */
    public function gauge(): int
    {
        return match ($this) {
            self::Outage => 0,
            self::Degraded => 1,
            self::Operational => 2,
            self::Maintenance => 3,
        };
    }

    /**
     * @param  list<self>  $states
     */
    public static function worst(array $states): self
    {
        $worst = self::Operational;

        foreach ($states as $state) {
            if ($state->severity() > $worst->severity()) {
                $worst = $state;
            }
        }

        return $worst;
    }
}
