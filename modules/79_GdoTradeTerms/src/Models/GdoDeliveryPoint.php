<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\GdoTradeTerms\Domain\Enums\DeliveryPointType;

/**
 * A store, Ce.Di. (distribution centre) or head office of a retail chain that goods are delivered to.
 *
 * @property string $contact_id
 * @property string $code
 * @property string $name
 * @property DeliveryPointType $point_type
 * @property string|null $city
 * @property string|null $postal_code
 * @property string $country
 * @property bool $is_active
 */
final class GdoDeliveryPoint extends BaseEnterpriseModel
{
    protected $table = 'gdt_delivery_points';

    protected function casts(): array
    {
        return ['point_type' => DeliveryPointType::class, 'is_active' => 'boolean'];
    }
}
