<?php

declare(strict_types=1);

namespace Modules\Returns\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The return rules of a company: how many days after the invoice a customer can still return goods.
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $return_window_days
 */
final class ReturnsSetting extends BaseEnterpriseModel
{
    public const int DefaultWindowDays = 30;

    protected $table = 'returns_settings';

    protected function casts(): array
    {
        return ['return_window_days' => 'integer'];
    }
}
