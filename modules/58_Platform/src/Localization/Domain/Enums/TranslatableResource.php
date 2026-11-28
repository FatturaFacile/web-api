<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Enums;

use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShippingReason;
use App\Models\StandardText;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Model;

/**
 * Master data whose text fields are translated per locale. Each resource maps to its base table, its translation table
 * (one row per record and locale) and the translated columns.
 */
enum TranslatableResource: string
{
    case Product = 'products';
    case ProductCategory = 'product-categories';
    case PaymentTerm = 'payment-terms';
    case ShippingReason = 'shipping-reasons';
    case UnitOfMeasure = 'units-of-measure';
    case StandardText = 'standard-texts';

    public function table(): string
    {
        return match ($this) {
            self::Product => 'products',
            self::ProductCategory => 'product_categories',
            self::PaymentTerm => 'payment_terms',
            self::ShippingReason => 'shipping_reasons',
            self::UnitOfMeasure => 'units_of_measure',
            self::StandardText => 'standard_texts',
        };
    }

    public function translationTable(): string
    {
        return match ($this) {
            self::Product => 'product_translations',
            self::ProductCategory => 'product_category_translations',
            self::PaymentTerm => 'payment_term_translations',
            self::ShippingReason => 'shipping_reason_translations',
            self::UnitOfMeasure => 'unit_of_measure_translations',
            self::StandardText => 'standard_text_translations',
        };
    }

    public function foreignKey(): string
    {
        return match ($this) {
            self::Product => 'product_id',
            self::ProductCategory => 'product_category_id',
            self::PaymentTerm => 'payment_term_id',
            self::ShippingReason => 'shipping_reason_id',
            self::UnitOfMeasure => 'unit_of_measure_id',
            self::StandardText => 'standard_text_id',
        };
    }

    /**
     * The translated columns; the first one is mandatory for a locale to count as translated.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return match ($this) {
            self::Product, self::ProductCategory => ['name', 'description'],
            self::PaymentTerm => ['name', 'invoice_clause'],
            self::ShippingReason => ['name', 'description'],
            self::UnitOfMeasure => ['name', 'symbol'],
            self::StandardText => ['title', 'body'],
        };
    }

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Product => Product::class,
            self::ProductCategory => ProductCategory::class,
            self::PaymentTerm => PaymentTerm::class,
            self::ShippingReason => ShippingReason::class,
            self::UnitOfMeasure => UnitOfMeasure::class,
            self::StandardText => StandardText::class,
        };
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_map(static fn (self $resource): string => $resource->value, self::cases());
    }
}
