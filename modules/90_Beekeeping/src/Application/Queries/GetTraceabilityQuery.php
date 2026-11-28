<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Application\Queries;

/**
 * A forward trace starts from an apiary, an intake or a batch (`type` + `reference` = id); a backward trace from a jar lot code.
 */
final readonly class GetTraceabilityQuery
{
    /**
     * @param  'forward'|'backward'  $direction
     * @param  'apiary'|'batch'|'intake'|null  $type
     */
    public function __construct(public string $direction, public string $reference, public ?string $type = null) {}
}
