<?php

declare(strict_types=1);

namespace Modules\Pricing\Observers;

use App\Models\PriceListItem;
use Modules\Pricing\Models\CustomerSpecialPrice;
use Modules\Pricing\Services\PriceHistoryRecorder;

/**
 * Records every price or cascade change of a price list item or a customer special price in the price history.
 */
final class PriceChangeObserver
{
    public function __construct(private readonly PriceHistoryRecorder $recorder) {}

    public function created(PriceListItem|CustomerSpecialPrice $model): void
    {
        $this->record($model, 'created');
    }

    public function updated(PriceListItem|CustomerSpecialPrice $model): void
    {
        $this->record($model, 'updated');
    }

    public function deleted(PriceListItem|CustomerSpecialPrice $model): void
    {
        $this->record($model, 'deleted');
    }

    private function record(PriceListItem|CustomerSpecialPrice $model, string $event): void
    {
        if ($model instanceof PriceListItem) {
            $this->recorder->recordItem($model, $event);

            return;
        }

        $this->recorder->recordSpecialPrice($model, $event);
    }
}
