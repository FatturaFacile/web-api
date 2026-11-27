<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Requests;

use Closure;
use Modules\GreenCare\Domain\Compliance\ComplianceStrategyResolver;
use Modules\GreenCare\Models\GreenSpaceSite;

final class SaveWasteSlipRequest extends GreenCareRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'slip_number' => ['sometimes', 'nullable', 'string', 'max:60', $this->uniqueInTenant('green_waste_slips', 'slip_number', is_string($id) ? $id : null)],
            'site_id' => ['nullable', 'uuid', $this->inTenant('green_space_sites')],
            'route_schedule_id' => ['nullable', 'uuid', $this->inTenant('crew_route_schedules')],
            'disposal_date' => ['required', 'date'],
            'waste_code' => ['required', 'string', 'max:30', $this->wasteCodeRule()],
            'waste_description' => ['nullable', 'string', 'max:255'],
            'green_waste_weight_kg' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'compost_facility_id' => ['nullable', 'uuid', $this->inTenant('contacts')],
            'transporter_vehicle_plate' => ['nullable', 'string', 'max:20'],
            'document_reference' => ['nullable', 'string', 'max:80'],
            'disposal_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'is_billable_to_customer' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The waste code follows the shape of the jurisdiction of the site (CER in Italy, OTR / LVA in Switzerland); without a site the tenant country decides.
     */
    private function wasteCodeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $resolver = new ComplianceStrategyResolver;
            $siteId = $this->input('site_id');
            $site = is_string($siteId) ? GreenSpaceSite::query()->find($siteId) : null;
            $jurisdiction = $site->jurisdiction ?? $resolver->normalize((string) (\App\Models\Tenant::query()->find($this->tenantId())->country ?? 'IT'));

            if (! $resolver->for($jurisdiction)->isValidWasteCode((string) $value)) {
                $fail((string) __('green_care.validation.waste_code', ['jurisdiction' => $jurisdiction]));
            }
        };
    }
}
