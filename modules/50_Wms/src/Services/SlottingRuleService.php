<?php

declare(strict_types=1);

namespace Modules\Wms\Services;

use Modules\Wms\Models\WmsSlottingRule;

/**
 * Plain create, update and delete of slotting rules.
 */
final class SlottingRuleService extends WmsCrudService
{
    protected function modelClass(): string
    {
        return WmsSlottingRule::class;
    }
}
