<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\InventoryCountPolicy;

/**
 * The counting policy of the tenant: ABC shares and cadences, recount and approval thresholds, ledger accounts.
 */
final class UpdateCountPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'abc_a_share' => ['sometimes', 'numeric', 'gt:0', 'lt:100'],
            'abc_b_share' => ['sometimes', 'numeric', 'gt:0', 'lte:100'],
            'abc_period_days' => ['sometimes', 'integer', 'min:1', 'max:3660'],
            'interval_days_a' => ['sometimes', 'integer', 'min:1', 'max:3660'],
            'interval_days_b' => ['sometimes', 'integer', 'min:1', 'max:3660'],
            'interval_days_c' => ['sometimes', 'integer', 'min:1', 'max:3660'],
            'new_item_class' => ['sometimes', 'string', Rule::in(['A', 'B', 'C'])],
            'blind_by_default' => ['sometimes', 'boolean'],
            'reveal_first_count_on_recount' => ['sometimes', 'boolean'],
            'recount_threshold_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'recount_threshold_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_recount_rounds' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'approval_mode' => ['sometimes', 'string', Rule::in([InventoryCountPolicy::MODE_WORKFLOW, InventoryCountPolicy::MODE_MULTI_LEVEL])],
            'auto_approve_within_tolerance' => ['sometimes', 'boolean'],
            'level1_threshold_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'level1_threshold_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'level2_threshold_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'level2_threshold_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'enforce_segregation_of_duties' => ['sometimes', 'boolean'],
            'inventory_account_code' => ['sometimes', 'string', 'max:20'],
            'gain_account_code' => ['sometimes', 'string', 'max:20'],
            'loss_account_code' => ['sometimes', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $stored = InventoryCountPolicy::forTenant((string) app(TenantContext::class)->getTenantId());
            $a = (string) ($this->input('abc_a_share') ?? $stored->abc_a_share);
            $b = (string) ($this->input('abc_b_share') ?? $stored->abc_b_share);

            if (bccomp($a, $b, 2) >= 0) {
                $validator->errors()->add('abc_b_share', 'The B share must be greater than the A share.');
            }

            foreach (['percent', 'value'] as $kind) {
                $one = $this->input("level1_threshold_{$kind}") ?? $stored->{"level1_threshold_{$kind}"};
                $two = $this->input("level2_threshold_{$kind}") ?? $stored->{"level2_threshold_{$kind}"};

                if ($one !== null && $two !== null && bccomp((string) $two, (string) $one, 2) < 0) {
                    $validator->errors()->add("level2_threshold_{$kind}", 'The level 2 threshold cannot be lower than the level 1 threshold.');
                }
            }
        }];
    }
}
