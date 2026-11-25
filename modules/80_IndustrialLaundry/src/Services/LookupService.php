<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\IndustrialLaundry\Domain\Enums\ArticleType;
use Modules\IndustrialLaundry\Domain\Enums\BillingLineKind;
use Modules\IndustrialLaundry\Domain\Enums\BillingModel;
use Modules\IndustrialLaundry\Domain\Enums\BillingRunStatus;
use Modules\IndustrialLaundry\Domain\Enums\CertificationStatus;
use Modules\IndustrialLaundry\Domain\Enums\CustomerSegment;
use Modules\IndustrialLaundry\Domain\Enums\FindingSeverity;
use Modules\IndustrialLaundry\Domain\Enums\GarmentEventType;
use Modules\IndustrialLaundry\Domain\Enums\Jurisdiction;
use Modules\IndustrialLaundry\Domain\Enums\KgBasis;
use Modules\IndustrialLaundry\Domain\Enums\LifecycleState;
use Modules\IndustrialLaundry\Domain\Enums\PpeAction;
use Modules\IndustrialLaundry\Domain\Enums\RabcRiskZone;
use Modules\IndustrialLaundry\Domain\Enums\ScanDirection;
use Modules\IndustrialLaundry\Models\WashProgram;

/**
 * Reference data of the screens: the enumerations (their labels are in the frontend dictionary served by the API), the wash
 * programs, the PPE standards, the defaults of the module and the customers a contract can point at.
 */
final class LookupService
{
    public function __construct(private readonly WashProgramCatalog $programs) {}

    /**
     * @return array<string, mixed>
     */
    public function reference(string $tenantId): array
    {
        $this->programs->ensure($tenantId);

        return [
            'enums' => [
                'article_type' => ArticleType::values(),
                'lifecycle_state' => LifecycleState::values(),
                'scan_direction' => ScanDirection::values(),
                'billing_model' => BillingModel::values(),
                'customer_segment' => CustomerSegment::values(),
                'jurisdiction' => Jurisdiction::values(),
                'kg_basis' => KgBasis::values(),
                'rabc_risk_zone' => RabcRiskZone::values(),
                'certification_status' => CertificationStatus::values(),
                'billing_line_kind' => BillingLineKind::values(),
                'billing_run_status' => BillingRunStatus::values(),
                'ppe_action' => PpeAction::values(),
                'garment_event_type' => GarmentEventType::values(),
                'finding_severity' => FindingSeverity::values(),
            ],
            'nominal_article_types' => array_values(array_map(static fn (ArticleType $type): string => $type->value, array_filter(ArticleType::cases(), static fn (ArticleType $type): bool => $type->isNominal()))),
            'programs' => WashProgram::query()->where('is_active', true)->orderBy('code')->get()->map(static fn (WashProgram $program): array => [
                'code' => $program->code,
                'is_thermal' => $program->is_thermal,
                'is_chemical' => $program->is_chemical,
                'disinfects' => $program->disinfects,
                'target_temperature_c' => (string) $program->target_temperature_c,
                'min_hold_minutes' => $program->min_hold_minutes,
                'min_chemical_dosing_ml_per_kg' => $program->min_chemical_dosing_ml_per_kg !== null ? (string) $program->min_chemical_dosing_ml_per_kg : null,
            ])->all(),
            'ppe_standards' => ['EN ISO 20471', 'EN ISO 11612', 'EN 13034', 'EN ISO 14116', 'EN 1149-5', 'EN ISO 11611'],
            'tax_rates' => (array) config('industrial_laundry.tax_rates'),
            'defaults' => [
                'dormant_days' => (int) config('industrial_laundry.dormant_days'),
                'unreturned_threshold_days' => 60,
                'ppe_warning_ratio' => (string) config('industrial_laundry.ppe.warning_ratio'),
                'rabc_max_cfu_per_25cm2' => (string) config('industrial_laundry.rabc.max_cfu_per_25cm2'),
                'min_rssi_dbm' => (float) config('industrial_laundry.rfid.min_rssi_dbm'),
            ],
            'customers' => $this->scoped('contacts', $tenantId)->whereIn('type', ['customer', 'both'])->orderBy('company_name')->limit(300)->get(['id', 'company_name', 'first_name', 'last_name', 'country'])->map(static fn ($row): array => ['id' => (string) $row->id, 'name' => $row->company_name ?: trim(($row->first_name ?? '').' '.($row->last_name ?? '')), 'country' => $row->country])->all(),
        ];
    }

    private function scoped(string $table, string $tenantId): Builder
    {
        return DB::table($table)->where('tenant_id', $tenantId)->whereNull('deleted_at');
    }
}
