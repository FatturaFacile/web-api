<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Services;

use Modules\Beekeeping\Models\JarringRun;

/**
 * The warehouse side of a jarring run: consumes the jars, caps and labels of the run.
 */
interface PackagingStock
{
    /**
     * @return list<string> ids of the stock movements booked
     */
    public function consume(JarringRun $run): array;
}
