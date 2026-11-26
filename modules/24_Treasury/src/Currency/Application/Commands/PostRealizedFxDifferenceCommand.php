<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Commands;

use Modules\Treasury\Currency\Domain\Events\PaymentAllocated;

/**
 * Records, and posts when the settlement has not, the realised exchange difference of a receipt or payment allocated to an
 * open item.
 */
final readonly class PostRealizedFxDifferenceCommand
{
    public function __construct(public PaymentAllocated $allocation) {}
}
