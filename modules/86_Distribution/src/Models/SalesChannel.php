<?php

declare(strict_types=1);

namespace Modules\Distribution\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Distribution\Domain\Enums\ChannelType;

/**
 * A sales channel (B2B portal, e-commerce, point of sale, marketplace, direct sales or a custom one) whose available stock is
 * computed from the warehouses it is mapped to.
 *
 * @property string $code
 * @property string $name
 * @property ChannelType $channel_type
 * @property int $priority
 * @property bool $is_active
 * @property string|null $description
 */
final class SalesChannel extends BaseEnterpriseModel
{
    protected $table = 'distribution_sales_channels';

    protected function casts(): array
    {
        return [
            'channel_type' => ChannelType::class,
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<SalesChannelWarehouse, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(SalesChannelWarehouse::class, 'sales_channel_id');
    }
}
