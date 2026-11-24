<?php

declare(strict_types=1);

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Models\DiscountPolicy;

/**
 * Writes the discount policies (promotions) and their rules, in one transaction each.
 */
final class DiscountPolicyService
{
    /**
     * Saves the header of a policy and, when `rules` is given, replaces its rules.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(DiscountPolicy $policy, array $data): DiscountPolicy
    {
        return DB::transaction(function () use ($policy, $data): DiscountPolicy {
            $rules = $data['rules'] ?? null;
            unset($data['rules']);
            $policy->fill($data)->save();

            if (is_array($rules)) {
                $policy->rules()->get()->each->delete();

                foreach ($rules as $rule) {
                    $policy->rules()->create([
                        'tenant_id' => $policy->tenant_id,
                        'product_id' => $rule['product_id'] ?? null,
                        'customer_group_id' => $rule['customer_group_id'] ?? null,
                        'contact_id' => $rule['contact_id'] ?? null,
                        'min_threshold' => $rule['min_threshold'] ?? '0',
                        'discount_percent' => $rule['discount_percent'] ?? '0',
                        'discount_fixed_amount' => $rule['discount_fixed_amount'] ?? '0',
                    ]);
                }
            }

            return $policy->load('rules');
        });
    }

    /**
     * Deletes a policy with its rules.
     */
    public function delete(DiscountPolicy $policy): void
    {
        DB::transaction(function () use ($policy): void {
            $policy->rules()->get()->each->delete();
            $policy->delete();
        });
    }
}
