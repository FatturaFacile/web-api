<?php

declare(strict_types=1);

namespace Modules\Hospitality\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Modules\Hospitality\Models\Accommodation;
use Modules\Hospitality\Models\FolioEntry;
use Modules\Hospitality\Models\HousekeepingTask;

/**
 * Room types, accommodations, rate plans, stays, folio lines and housekeeping tasks. Each route names the payload it
 * validates; a PUT only validates the fields it sends.
 */
final class LodgingRequest extends FormRequest
{
    /**
     * Who may call the endpoint is decided by the module.access middleware and the policy check of the controller.
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
        $put = $this->isMethod('PUT');
        $required = $put ? 'sometimes' : 'required';

        return match (str_replace('api.v1.hospitality.', '', (string) $this->route()?->getName())) {
            'room-types.store', 'room-types.update' => [
                'code' => [$required, 'string', 'max:30'],
                'name' => [$required, 'string', 'max:120'],
                'max_occupancy' => ['sometimes', 'integer', 'min:1', 'max:20'],
                'base_rate' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            'accommodations.store', 'accommodations.update' => [
                'room_type_id' => [$required, 'uuid', $this->ownRow('hosp_room_types')],
                'number' => [$required, 'string', 'max:20'],
                'floor' => ['nullable', 'string', 'max:20'],
                'status' => ['sometimes', Rule::in(Accommodation::Statuses)],
                'housekeeping_status' => ['sometimes', Rule::in(Accommodation::HousekeepingStatuses)],
            ],
            'rate-plans.store', 'rate-plans.update' => [
                'room_type_id' => ['nullable', 'uuid', $this->ownRow('hosp_room_types')],
                'code' => [$required, 'string', 'max:30'],
                'name' => [$required, 'string', 'max:120'],
                'meal_plan' => ['nullable', Rule::in(['room_only', 'bed_breakfast', 'half_board', 'full_board'])],
                'nightly_rate' => [$required, 'numeric', 'min:0', 'max:9999999'],
                'valid_from' => [$required, 'date_format:Y-m-d'],
                'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
                'min_stay' => ['sometimes', 'integer', 'min:1', 'max:365'],
                'priority' => ['sometimes', 'integer', 'min:-1000', 'max:1000'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            'stays.availability', 'stays.quote' => [
                'room_type_id' => ['required', 'uuid', $this->ownRow('hosp_room_types')],
                'from' => ['required', 'date_format:Y-m-d'],
                'to' => ['required', 'date_format:Y-m-d', 'after:from'],
                'rate_plan_id' => ['nullable', 'uuid', $this->ownRow('hosp_rate_plans')],
            ],
            'stays.store' => [
                'guest_name' => ['required', 'string', 'max:160'],
                'guest_email' => ['nullable', 'email', 'max:190'],
                'room_type_id' => ['required', 'uuid', $this->ownRow('hosp_room_types')],
                'rate_plan_id' => ['nullable', 'uuid', $this->ownRow('hosp_rate_plans')],
                'check_in_date' => ['required', 'date_format:Y-m-d'],
                'check_out_date' => ['required', 'date_format:Y-m-d', 'after:check_in_date'],
                'adults' => ['required', 'integer', 'min:1', 'max:20'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            'stays.check-in' => [
                'accommodation_id' => ['nullable', 'uuid', $this->ownRow('hosp_accommodations')],
                'city_tax_per_person_night' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            ],
            'stays.charges.store' => [
                'category' => ['required', Rule::in(FolioEntry::Categories)],
                'description' => ['required', 'string', 'max:190'],
                'quantity' => ['sometimes', 'numeric', 'gt:0', 'max:100000'],
                'unit_amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
                'posted_on' => ['sometimes', 'date_format:Y-m-d'],
            ],
            'stays.payments.store' => [
                'amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
                'payment_method' => ['required', Rule::in(['cash', 'card', 'bank_transfer', 'voucher', 'other'])],
                'description' => ['nullable', 'string', 'max:190'],
            ],
            'housekeeping-tasks.store' => [
                'accommodation_id' => ['required', 'uuid', $this->ownRow('hosp_accommodations')],
                'task_type' => ['required', Rule::in(HousekeepingTask::Types)],
                'due_on' => ['sometimes', 'date_format:Y-m-d'],
                'assigned_to' => ['nullable', 'uuid'],
            ],
            'housekeeping-tasks.start' => ['assigned_to' => ['nullable', 'uuid']],
            default => [],
        };
    }

    /**
     * A row of the table that belongs to the active company.
     */
    private function ownRow(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', (string) app(TenantContext::class)->getTenantId());
    }
}
