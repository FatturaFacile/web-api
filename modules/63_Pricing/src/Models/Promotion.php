<?php

declare(strict_types=1);

namespace Modules\Pricing\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A time-bounded promotion whose reward is described by its type-specific `rules`.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property array<string, mixed> $rules
 * @property bool $is_stackable
 * @property bool $is_active
 */
final class Promotion extends BaseEnterpriseModel
{
    public const string BuyXGetY = 'BUY_X_GET_Y';

    public const string FreeGift = 'FREE_GIFT';

    public const string BundleDiscount = 'BUNDLE_DISCOUNT';

    public const string CartValueTier = 'CART_VALUE_TIER';

    /** @var list<string> */
    public const array Types = [self::BuyXGetY, self::FreeGift, self::BundleDiscount, self::CartValueTier];

    public const string WithVatRecourse = 'WITH_VAT_RECOURSE';

    public const string WithoutVatRecourse = 'WITHOUT_VAT_RECOURSE';

    protected $table = 'promotions';

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_stackable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function isValidOn(CarbonInterface $day): bool
    {
        return $this->is_active
            && ($this->valid_from === null || $day->greaterThanOrEqualTo($this->valid_from->startOfDay()))
            && ($this->valid_to === null || $day->lessThanOrEqualTo($this->valid_to->endOfDay()));
    }
}
