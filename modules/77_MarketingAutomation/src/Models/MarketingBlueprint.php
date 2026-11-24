<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * Mod 155 Marketing Automation — MarketingBlueprint.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $kind
 * @property string $code
 * @property array<string, array<string, string>>|null $texts
 * @property array<string, mixed>|null $payload
 * @property bool $is_active
 * @property array<string, mixed>|null $texts
 * @property array<string, mixed>|null $payload
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class MarketingBlueprint extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'ma_blueprints';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'texts' => 'array',
            'payload' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
