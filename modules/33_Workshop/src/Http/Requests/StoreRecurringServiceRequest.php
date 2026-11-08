<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Requests;

use App\Enums\SubscriptionInterval;
use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Workshop\Models\RecurringService;

/**
 * Validates a recurring service (for example yearly tyre storage) billed through a subscription.
 */
final class StoreRecurringServiceRequest extends FormRequest
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
        return [
            'vehicle_id' => ['required', 'uuid', $this->tenantOwns('vehicles')],
            'tire_hotel_record_id' => ['nullable', 'uuid', $this->tenantOwns('tire_hotel_records')],
            'service_type' => ['required', Rule::in(RecurringService::Types)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'interval' => ['required', Rule::enum(SubscriptionInterval::class)],
            'interval_count' => ['nullable', 'integer', 'min:1', 'max:12'],
            'start_date' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'unit_price' => ['required', 'decimal:0,4', 'gt:0'],
            'tax_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
        ];
    }
}
