<?php

declare(strict_types=1);

namespace Modules\Wms\Services;

use Modules\Wms\Models\WmsWaveRule;

/**
 * Plain create, update and delete of wave rules.
 */
final class WaveRuleService extends WmsCrudService
{
    protected function modelClass(): string
    {
        return WmsWaveRule::class;
    }
}
