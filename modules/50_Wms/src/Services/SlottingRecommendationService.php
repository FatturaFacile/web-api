<?php

declare(strict_types=1);

namespace Modules\Wms\Services;

use Modules\Wms\Models\WmsSlottingRecommendation;

/**
 * Plain create, update and delete of slotting recommendations.
 */
final class SlottingRecommendationService extends WmsCrudService
{
    protected function modelClass(): string
    {
        return WmsSlottingRecommendation::class;
    }
}
