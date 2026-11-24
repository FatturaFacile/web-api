<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A commission zone: a country, optionally narrowed to provinces / cantons.
 *
 * @property string $code
 * @property string $name
 * @property string|null $country
 * @property list<string>|null $regions
 * @property bool $is_active
 */
final class Zone extends BaseEnterpriseModel
{
    protected $table = 'agc_zones';

    protected function casts(): array
    {
        return [
            'regions' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
