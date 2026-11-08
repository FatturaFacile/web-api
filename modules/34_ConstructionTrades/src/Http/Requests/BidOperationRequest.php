<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ConstructionTrades\Models\SiteCost;

/**
 * Operations on a bid: status, measurements, billings and site costs. Each route names the operation it validates.
 */
final class BidOperationRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Always allowed here: who may call the endpoint is decided by the module.access middleware and the policy check
     * of the controller.
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
        $position = ['uuid', Rule::exists('ct_bid_positions', 'id')->where('bid_id', $this->route('id'))];

        return match ((string) $this->route()?->getName()) {
            'api.v1.construction-trades.bids.status' => ['status' => ['required', 'string', 'in:draft,submitted,accepted,rejected']],
            'api.v1.construction-trades.bids.measurements.store' => [
                'bid_position_id' => ['required', ...$position],
                'measured_on' => ['required', 'date'],
                'quantity' => ['required', 'decimal:0,4', 'not_in:0'],
                'note' => ['nullable', 'string', 'max:500'],
            ],
            'api.v1.construction-trades.bids.billings.store' => [
                'kind' => ['required', 'string', 'in:partial,final'],
                'basis' => ['required_if:kind,partial', 'string', 'in:percentage,measured'],
                'percentage' => ['required_if:basis,percentage', 'nullable', 'decimal:0,4', 'gt:0', 'max:100'],
                'date' => ['required', 'date'],
            ],
            'api.v1.construction-trades.bids.site-costs.store' => [
                'bid_position_id' => ['nullable', ...$position],
                'cost_type' => ['required', Rule::in(SiteCost::Types)],
                'cost_date' => ['required', 'date'],
                'worker_user_id' => ['nullable', 'uuid', 'required_if:cost_type,labour', $this->tenantMember()],
                'description' => ['required', 'string', 'max:500'],
                'quantity' => ['required', 'decimal:0,4', 'gt:0'],
                'unit_cost' => ['required', 'decimal:0,4', 'min:0'],
            ],
            default => [],
        };
    }
}
