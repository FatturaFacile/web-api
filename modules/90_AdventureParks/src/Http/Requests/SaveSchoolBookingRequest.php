<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\AdventureParks\Models\SchoolBooking;

/**
 * A school trip. The CIG is mandatory for an invoice with split payment to the public administration.
 */
final class SaveSchoolBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', SchoolBooking::class);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('split_payment')) {
            $this->merge(['split_payment' => true]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'institute_name' => ['required', 'string', 'max:200'],
            'mechanographic_code' => ['nullable', 'string', 'max:20'],
            'ipa_office_code' => ['required', 'string', 'alpha_num', 'size:6'],
            'cig_code' => [Rule::requiredIf(fn (): bool => $this->boolean('split_payment')), 'nullable', 'string', 'alpha_num', 'size:10'],
            'cup_code' => ['nullable', 'string', 'alpha_num', 'size:15'],
            'split_payment' => ['required', 'boolean'],
            'visit_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'workshop_ids' => ['required', 'array', 'min:1', 'max:20'],
            'workshop_ids.*' => ['uuid', 'distinct'],
            'picnic_reserved' => ['sometimes', 'boolean'],
            'paying_students' => ['required', 'integer', 'min:1', 'max:2000'],
            'disabled_students' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'accompanying_teachers' => ['nullable', 'integer', 'min:0', 'max:500'],
            'customer_contact_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        /** @var array<string, mixed> $data */
        $data = parent::validated();
        $data['disabled_students'] = (int) ($data['disabled_students'] ?? 0);
        $data['accompanying_teachers'] = (int) ($data['accompanying_teachers'] ?? 0);

        return $data;
    }
}
