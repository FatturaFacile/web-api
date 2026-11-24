<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A saved marketing filter over contacts or leads. The filter is stored, not the result, so the audience is always
 * evaluated against current data (and current consent) when it is previewed or added to a campaign.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property string $entity
 * @property list<array<string, mixed>>|null $filters
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Segment extends BaseEnterpriseModel
{
    public const array Entities = ['contacts', 'leads'];

    protected $table = 'crm_segments';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
