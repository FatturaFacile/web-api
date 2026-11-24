<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoicing\Models\DiscountPolicy;
use Modules\Invoicing\Models\DiscountPolicyRule;

/**
 * A discount policy or promotion with its rules.
 *
 * @mixin DiscountPolicy
 */
class DiscountPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'discount_type' => $this->discount_type,
            'value' => (string) $this->value,
            'min_order_amount' => self::decimalOrNull($this->resource->getAttribute('min_order_amount')),
            'min_quantity' => self::decimalOrNull($this->resource->getAttribute('min_quantity')),
            'valid_from' => self::dateOrNull($this->resource->getRawOriginal('valid_from')),
            'valid_to' => self::dateOrNull($this->resource->getRawOriginal('valid_to')),
            'is_active' => (bool) $this->is_active,
            'priority' => (int) $this->priority,
            'is_stackable' => (bool) $this->is_stackable,
            'auto_apply' => (bool) $this->auto_apply,
            'contact_id' => $this->contact_id,
            'rules' => $this->whenLoaded('rules', fn (): array => $this->rules->map(static fn (DiscountPolicyRule $rule): array => [
                'id' => $rule->id,
                'product_id' => $rule->product_id,
                'customer_group_id' => $rule->customer_group_id,
                'contact_id' => $rule->contact_id,
                'min_threshold' => self::decimalOrNull($rule->getAttribute('min_threshold')),
                'discount_percent' => self::decimalOrNull($rule->getAttribute('discount_percent')),
                'discount_fixed_amount' => self::decimalOrNull($rule->getAttribute('discount_fixed_amount')),
            ])->all()),
        ];
    }

    private static function decimalOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function dateOrNull(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }
}
