<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Events;

/**
 * An inspection raised colony alerts (swarming risk, queenlessness...).
 */
final readonly class ColonyAlertRaised
{
    /**
     * @param  list<string>  $alerts
     */
    public function __construct(public string $hiveId, public string $inspectionId, public array $alerts) {}
}
