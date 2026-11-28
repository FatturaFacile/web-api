<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\AdventureParks\Domain\Enums\PaymentStatus;
use Modules\AdventureParks\Models\Ticket;
use Modules\AdventureParks\Services\SlotAvailabilityService;

final class BookTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('operate', Ticket::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'slot_id' => ['required', 'uuid', Rule::exists('adv_slot_templates', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'slot_date' => ['required', 'date_format:Y-m-d'],
            'contact_name' => ['required', 'string', 'max:150'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'payment_status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'lines' => ['required', 'array', 'min:1', 'max:8'],
            'lines.*.size' => ['required', 'string', Rule::in(SlotAvailabilityService::BOOKABLE_SIZES)],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:200'],
        ];
    }
}
