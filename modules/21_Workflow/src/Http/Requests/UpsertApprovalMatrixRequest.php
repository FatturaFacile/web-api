<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Workflow\Services\ApprovableRegistry;

/**
 * A whole approval matrix: header, conditions (all must hold) and cumulative tiers. Owners and administrators only (the
 * route is behind the `workflow_design` module key).
 */
final class UpsertApprovalMatrixRequest extends FormRequest
{
    /**
     * Authorisation is the `workflow_design` module key of the route group.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $matrixId = $this->route('id');

        return [
            'code' => ['nullable', 'string', 'max:100', Rule::unique('approval_matrixes', 'code')->where('tenant_id', $tenantId)->ignore($matrixId)],
            'name' => ['required', 'string', 'max:255'],
            'subject_type' => ['required', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'auto_approve_below' => ['nullable', 'numeric', 'min:0'],
            'fallback_approver' => ['nullable', 'array'],
            'fallback_approver.approver_type' => ['required_with:fallback_approver', 'string', Rule::in(['role', 'user', 'reporting_manager'])],
            'fallback_approver.approver_value' => ['required_with:fallback_approver', 'string', 'max:255'],
            'conditions' => ['nullable', 'array', 'max:50'],
            'conditions.*.field' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/'],
            'conditions.*.operator' => ['required', 'string', Rule::in(['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'between', '=', '!=', '>', '>=', '<', '<='])],
            'conditions.*.value' => ['present'],
            'tiers' => ['nullable', 'array', 'max:50'],
            'tiers.*.tier_level' => ['required', 'integer', 'min:1', 'max:50'],
            'tiers.*.approver_type' => ['required', 'string', Rule::in(['role', 'user', 'reporting_manager', 'matrix_approver'])],
            'tiers.*.approver_value' => ['required_unless:tiers.*.approver_type,reporting_manager', 'nullable', 'string', 'max:255'],
            'tiers.*.approval_type' => ['nullable', 'string', Rule::in(['single', 'and_parallel', 'or_parallel', 'majority', 'supermajority', 'unanimous'])],
            'tiers.*.threshold_min' => ['nullable', 'numeric', 'min:0'],
            'tiers.*.threshold_max' => ['nullable', 'numeric', 'min:0'],
            'tiers.*.threshold_min_inclusive' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Cross-field checks: known subject type, ordered tier bounds, at least one way to find an approver.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! app(ApprovableRegistry::class)->has((string) $this->input('subject_type'))) {
                $validator->errors()->add('subject_type', __('workflow_errors.matrix.unknown_subject'));
            }

            foreach ((array) $this->input('tiers', []) as $index => $tier) {
                $min = $tier['threshold_min'] ?? null;
                $max = $tier['threshold_max'] ?? null;

                if ($min !== null && $max !== null && is_numeric($min) && is_numeric($max) && (float) $min > (float) $max) {
                    $validator->errors()->add("tiers.{$index}.threshold_min", __('workflow_errors.matrix.tier_range'));
                }
            }

            if ((array) $this->input('tiers', []) === [] && $this->input('fallback_approver') === null && $this->input('auto_approve_below') === null) {
                $validator->errors()->add('tiers', __('workflow_errors.matrix.needs_tier'));
            }
        }];
    }
}
