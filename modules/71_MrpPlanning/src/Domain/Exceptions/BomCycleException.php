<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Exceptions;

/** The bills of materials contain a cycle, so no low-level code exists. */
final class BomCycleException extends MrpException
{
    /** @param list<string> $path item ids along the cycle */
    public function __construct(public readonly array $path)
    {
        parent::__construct('Cyclic bill of materials: '.implode(' -> ', $path));
    }
}
