<?php

declare(strict_types=1);

namespace Modules\Hospitality\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;
use Modules\Hospitality\Models\FolioEntry;

/**
 * Group blocks, the stays picked up from them, the availability grid and the invoice of a folio. Each route names the
 * payload it validates; a PUT only validates the fields it sends.
 */
final class GroupBookingRequest extends FormRequest
{
    /** Longest interval the availability grid covers, in days. */
    public const int GridMaxDays = 62;

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
            'group-blocks.store', 'group-blocks.update' => [
                'name' => [$required, 'string', 'max:160'],
                'organizer' => ['nullable', 'string', 'max:160'],
                'contact_id' => ['nullable', 'uuid', $this->ownRow('contacts')],
                'check_in_date' => [$put ? 'prohibited' : 'required', 'date_format:Y-m-d'],
                'check_out_date' => [$put ? 'prohibited' : 'required', 'date_format:Y-m-d', 'after:check_in_date'],
                'release_date' => [$required, 'date_format:Y-m-d', 'before_or_equal:check_in_date'],
                'notes' => ['nullable', 'string', 'max:2000'],
                'rooms' => [$required, 'array', 'min:1', 'max:50'],
                'rooms.*.room_type_id' => ['required', 'uuid', 'distinct', $this->ownRow('hosp_room_types')],
                'rooms.*.rooms_blocked' => ['required', 'integer', 'min:1', 'max:500'],
                'rooms.*.nightly_rate' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            ],
            'group-blocks.dates' => [
                'check_in_date' => ['required', 'date_format:Y-m-d'],
                'check_out_date' => ['required', 'date_format:Y-m-d', 'after:check_in_date'],
                'release_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:check_in_date'],
            ],
            'group-blocks.stays.store' => [
                'guest_name' => ['required', 'string', 'max:160'],
                'guest_email' => ['nullable', 'email', 'max:190'],
                'room_type_id' => ['required', 'uuid', $this->ownRow('hosp_room_types')],
                'rate_plan_id' => ['nullable', 'uuid', $this->ownRow('hosp_rate_plans')],
                'check_in_date' => ['sometimes', 'date_format:Y-m-d'],
                'check_out_date' => ['sometimes', 'date_format:Y-m-d', 'after:check_in_date'],
                'adults' => ['required', 'integer', 'min:1', 'max:20'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            'stays.invoice' => [
                'contact_id' => ['required', 'uuid', $this->ownRow('contacts')],
                'tax_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
                'category_tax_rates' => ['sometimes', 'array:'.implode(',', FolioEntry::Categories)],
                'category_tax_rates.*' => ['decimal:0,2', 'min:0', 'max:100'],
                'date' => ['nullable', 'date_format:Y-m-d'],
            ],
            'stays.availability-grid' => [
                'from' => ['required', 'date_format:Y-m-d'],
                'to' => ['required', 'date_format:Y-m-d', 'after:from'],
            ],
            default => [],
        };
    }

    /**
     * The availability grid covers a bounded number of nights.
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! str_ends_with((string) $this->route()?->getName(), 'availability-grid') || $validator->errors()->isNotEmpty()) {
                return;
            }

            if (CarbonImmutable::parse((string) $this->query('from'))->diffInDays(CarbonImmutable::parse((string) $this->query('to'))) > self::GridMaxDays) {
                $validator->errors()->add('to', 'The interval cannot exceed '.self::GridMaxDays.' days.');
            }
        }];
    }

    /**
     * A row of the table that belongs to the active company.
     */
    private function ownRow(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', (string) app(TenantContext::class)->getTenantId());
    }
}
