<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\SkiOutdoorSchool\Domain\Enums\PackageItemStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\PackageItemType;

/**
 * Add-on of a lesson or of a course enrolment: equipment rental, ARTVA kit or skipass.
 *
 * @property string|null $lesson_id
 * @property string|null $enrollment_id
 * @property PackageItemType $item_type
 * @property string|null $description
 * @property string $quantity
 * @property int $days
 * @property string $unit_price_net
 * @property string $vat_rate
 * @property string|null $product_id
 * @property PackageItemStatus $status
 */
final class PackageItem extends BaseEnterpriseModel
{
    protected $table = 'ski_lesson_package_items';

    protected function casts(): array
    {
        return [
            'item_type' => PackageItemType::class,
            'quantity' => 'decimal:2',
            'days' => 'integer',
            'unit_price_net' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'status' => PackageItemStatus::class,
        ];
    }

    public function lineNet(): float
    {
        return round((float) $this->quantity * $this->days * (float) $this->unit_price_net, 2);
    }
}
