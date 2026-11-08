<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Procurement\Services\ProcurementApprovalService;

/**
 * An approval matrix of purchase orders or requisitions: levels by amount, optionally for one cost centre.
 */
final class StoreProcurementApprovalMatrixRequest extends FormRequest
{
    use TenantScopedRules;

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
            'target' => ['required', 'string', 'in:'.implode(',', array_keys(ProcurementApprovalService::Targets))],
            'name' => ['required', 'string', 'max:255'],
            'cost_center_id' => ['nullable', 'uuid', $this->tenantExists('cost_centers')],
            'tiers' => ['required', 'array', 'min:1'],
            'tiers.*.tier_level' => ['required', 'integer', 'min:1', 'max:20'],
            'tiers.*.approver_type' => ['required', 'string', 'in:role,user'],
            'tiers.*.approver_value' => ['required', 'string', 'max:255'],
            'tiers.*.threshold_min' => ['nullable', 'decimal:0,4', 'min:0'],
            'tiers.*.threshold_max' => ['nullable', 'decimal:0,4', 'min:0'],
        ];
    }

    /**
     * An approver named by id must be a member of the tenant.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $tenantId = app(TenantContext::class)->getTenantId();

            foreach ((array) $this->input('tiers', []) as $index => $tier) {
                if (($tier['approver_type'] ?? null) !== 'user') {
                    continue;
                }

                $value = (string) ($tier['approver_value'] ?? '');
                $isMember = str($value)->isUuid() && $this->user()->newQuery()->whereKey($value)->whereHas('tenants', static fn ($tenants) => $tenants->where('tenants.id', $tenantId))->exists();

                if (! $isMember) {
                    $validator->errors()->add("tiers.{$index}.approver_value", 'This user is not a member of the company.');
                }
            }
        }];
    }
}
