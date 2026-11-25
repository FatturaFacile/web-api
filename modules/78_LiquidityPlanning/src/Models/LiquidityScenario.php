<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\LiquidityPlanning\Support\Levers;

/**
 * A saved what-if scenario: a named set of levers applied to the baseline forecast.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed> $levers
 */
final class LiquidityScenario extends BaseEnterpriseModel
{
    protected $table = 'lp_scenarios';

    protected function casts(): array
    {
        return ['levers' => 'array'];
    }

    public function leversObject(): Levers
    {
        return Levers::fromArray($this->levers);
    }
}
