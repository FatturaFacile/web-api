<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\LiquidityPlanning\Models\LiquidityScenario;
use Modules\LiquidityPlanning\Support\Levers;

/**
 * Parameters of the overview, the report and the exports: the date, an optional saved scenario and the ad-hoc what-if levers
 * (which win over the saved scenario).
 */
final class OverviewRequest extends FormRequest
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
        $tenantId = (string) app(TenantContext::class)->getTenantId();

        return [
            'as_of' => ['nullable', 'date'],
            'locale' => ['nullable', 'string', Rule::in(['it', 'de', 'fr', 'en'])],
            'scenario_id' => ['nullable', 'uuid', Rule::exists('lp_scenarios', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'levers' => ['nullable', 'array'],
            'levers.collection_delay_days' => ['nullable', 'integer', 'between:0,180'],
            'levers.collection_delay_share_percent' => ['nullable', 'integer', 'between:0,100'],
            'levers.inflow_change_percent' => ['nullable', 'numeric', 'between:-100,100'],
            'levers.outflow_change_percent' => ['nullable', 'numeric', 'between:-100,300'],
            'levers.raw_material_increase_percent' => ['nullable', 'numeric', 'between:0,300'],
            'levers.capex_acceleration_days' => ['nullable', 'integer', 'between:0,365'],
            'levers.capex_change_percent' => ['nullable', 'numeric', 'between:-100,300'],
            'levers.additional_financing_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'levers.additional_financing_date' => ['nullable', 'date'],
        ];
    }

    public function asOf(): ?CarbonImmutable
    {
        return $this->filled('as_of') ? CarbonImmutable::parse((string) $this->input('as_of'))->startOfDay() : null;
    }

    public function locale(): string
    {
        return (string) ($this->input('locale') ?: app()->getLocale());
    }

    /**
     * The levers of the request: the saved scenario, overridden by the levers given in the request; null for the baseline only.
     */
    public function levers(): ?Levers
    {
        $levers = [];

        if ($this->filled('scenario_id')) {
            $levers = LiquidityScenario::query()->findOrFail((string) $this->input('scenario_id'))->levers;
        }

        $levers = array_merge($levers, array_filter((array) $this->input('levers', []), static fn (mixed $value): bool => $value !== null && $value !== ''));

        return $levers === [] ? null : Levers::fromArray($levers);
    }

    /**
     * @return array{id: string|null, name: string|null}|null
     */
    public function scenarioInfo(): ?array
    {
        if ($this->filled('scenario_id')) {
            $scenario = LiquidityScenario::query()->findOrFail((string) $this->input('scenario_id'));

            return ['id' => (string) $scenario->id, 'name' => $scenario->name];
        }

        return null;
    }
}
