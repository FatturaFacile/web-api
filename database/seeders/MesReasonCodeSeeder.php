<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\Manufacturing\Models\MfgReasonCode;
use Modules\Manufacturing\Models\MfgReasonCodeLabel;

/**
 * The default downtime, setup, micro-stop, scrap and rework reason codes of every company, with the label in the four
 * active locales. The codes are matched on their natural key (tenant, code), including the ones a company deleted, so a
 * rerun never duplicates or resurrects anything and never overwrites a label a company edited.
 */
final class MesReasonCodeSeeder extends Seeder
{
    /**
     * Code, category, classification and the labels by locale.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: array<string, string>}>
     */
    public const array Codes = [
        ['BREAKDOWN', 'UnplannedDowntime', 'AvailabilityLoss', ['it' => 'Guasto macchina', 'de' => 'Maschinenausfall', 'fr' => 'Panne machine', 'en' => 'Machine breakdown']],
        ['NO_MATERIAL', 'UnplannedDowntime', 'AvailabilityLoss', ['it' => 'Mancanza materiale', 'de' => 'Materialmangel', 'fr' => 'Manque de matière', 'en' => 'Material shortage']],
        ['NO_OPERATOR', 'UnplannedDowntime', 'AvailabilityLoss', ['it' => 'Operatore assente', 'de' => 'Bediener fehlt', 'fr' => 'Opérateur absent', 'en' => 'Operator missing']],
        ['QUALITY_HOLD', 'UnplannedDowntime', 'AvailabilityLoss', ['it' => 'Blocco qualità', 'de' => 'Qualitätssperre', 'fr' => 'Blocage qualité', 'en' => 'Quality hold']],
        ['CHANGEOVER', 'Setup', 'AvailabilityLoss', ['it' => 'Cambio formato', 'de' => 'Umrüstung', 'fr' => 'Changement de série', 'en' => 'Changeover']],
        ['MICRO_STOP', 'MicroStop', 'AvailabilityLoss', ['it' => 'Micro-fermata', 'de' => 'Kurzstillstand', 'fr' => 'Micro-arrêt', 'en' => 'Micro-stop']],
        ['PLANNED_MAINTENANCE', 'PlannedDowntime', 'PlannedExcluded', ['it' => 'Manutenzione programmata', 'de' => 'Geplante Wartung', 'fr' => 'Maintenance planifiée', 'en' => 'Planned maintenance']],
        ['BREAK', 'PlannedDowntime', 'PlannedExcluded', ['it' => 'Pausa', 'de' => 'Pause', 'fr' => 'Pause', 'en' => 'Break']],
        ['SCRAP_DEFECT', 'Scrap', 'NotApplicable', ['it' => 'Scarto per difetto', 'de' => 'Ausschuss wegen Fehler', 'fr' => 'Rebut pour défaut', 'en' => 'Scrap due to defect']],
        ['REWORK', 'Rework', 'NotApplicable', ['it' => 'Rilavorazione', 'de' => 'Nacharbeit', 'fr' => 'Retouche', 'en' => 'Rework']],
    ];

    public function run(): void
    {
        $context = app(TenantContext::class);

        foreach (Tenant::query()->get() as $tenant) {
            $context->setTenant($tenant);

            try {
                $this->seedTenant((string) $tenant->id);
            } finally {
                $context->clear();
            }
        }
    }

    private function seedTenant(string $tenantId): void
    {
        foreach (self::Codes as [$code, $category, $classification, $labels]) {
            $reasonCode = MfgReasonCode::withTrashed()->where('tenant_id', $tenantId)->where('code', $code)->first();

            if ($reasonCode?->trashed()) {
                continue;
            }

            $reasonCode ??= MfgReasonCode::create(['tenant_id' => $tenantId, 'code' => $code, 'category' => $category, 'classification' => $classification, 'is_active' => true]);

            foreach ($labels as $locale => $label) {
                if (MfgReasonCodeLabel::withTrashed()->where('reason_code_id', $reasonCode->id)->where('locale', $locale)->exists()) {
                    continue;
                }

                MfgReasonCodeLabel::create(['tenant_id' => $tenantId, 'reason_code_id' => $reasonCode->id, 'locale' => $locale, 'label' => $label]);
            }
        }
    }
}
