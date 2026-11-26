<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Treasury\Currency\Application\Commands\PostRealizedFxDifferenceCommand;
use Modules\Treasury\Currency\Application\Handlers\PostRealizedFxDifferenceHandler;
use Modules\Treasury\Currency\Domain\Events\PaymentAllocated;
use Throwable;

/**
 * A receipt or payment was allocated to an open item: records and, when the settlement entry did not, posts its realised
 * exchange difference. When the ledger already posted the difference with the settlement, a failure of this record is logged and
 * never undoes the payment; when it did not, the failure is raised so the payment is not left without its exchange difference.
 */
final class PostRealizedFxOnPaymentAllocated
{
    public function __construct(private readonly PostRealizedFxDifferenceHandler $handler) {}

    public function handle(PaymentAllocated $event): void
    {
        try {
            $this->handler->handle(new PostRealizedFxDifferenceCommand($event));
        } catch (Throwable $exception) {
            if (! $event->ledgerPostedDifference) {
                throw $exception;
            }

            Log::warning('Realised exchange difference not recorded.', ['allocation' => $event->allocationId, 'reason' => $exception->getMessage()]);
        }
    }
}
