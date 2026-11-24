<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Warranty;

use Carbon\CarbonImmutable;

/**
 * Immutable input of the warranty calculation.
 */
final readonly class WarrantyContext
{
    public const CUSTOMER_TYPE_B2B = 'b2b';

    public const CUSTOMER_TYPE_B2C = 'b2c';

    /**
     * @param  list<ExtendedCarePackExtension>  $extensions
     */
    public function __construct(
        public string $jurisdiction,
        public string $customerType,
        public CarbonImmutable $deliveryDate,
        public ?int $contractWarrantyMonths = null,
        public array $extensions = [],
    ) {}

    public function isB2b(): bool
    {
        return $this->customerType === self::CUSTOMER_TYPE_B2B;
    }

    public function normalizedJurisdiction(): string
    {
        return strtoupper(trim($this->jurisdiction));
    }
}
