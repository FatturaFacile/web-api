<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance;

use App\Enums\Privacy\BreachIncidentStatus;
use App\Enums\Privacy\BreachJurisdiction;
use App\Models\Privacy\DataBreachIncident;
use App\Services\Privacy\PrivacyAudit;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data breach incidents with the 72 hour clock (GDPR art. 33, nLPD art. 24) and the pre-filled notification dossier.
 */
final readonly class BreachIncidentService
{
    public function __construct(private PrivacyAudit $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): DataBreachIncident
    {
        $detected = CarbonImmutable::parse($data['detected_at'] ?? now());
        $incident = DB::transaction(function () use ($tenantId, $data, $detected): DataBreachIncident {
            $count = DataBreachIncident::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)
                ->where('incident_code', 'like', "BRE-{$detected->year}-%")->lockForUpdate()->count();

            return DataBreachIncident::withoutGlobalScope(TenantScope::class)->create($data + [
                'tenant_id' => $tenantId,
                'incident_code' => sprintf('BRE-%d-%04d', $detected->year, $count + 1),
                'detected_at' => $detected,
                'notification_deadline_72h' => $detected->addHours(72),
                'status' => BreachIncidentStatus::Investigating,
            ]);
        });

        $this->audit->record($tenantId, 'PRIVACY_BREACH_INCIDENT_CREATED', DataBreachIncident::class, (string) $incident->id, ['code' => $incident->incident_code, 'severity' => $incident->severity->value]);

        return $incident;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(DataBreachIncident $incident, array $data): DataBreachIncident
    {
        if (isset($data['detected_at'])) {
            $data['notification_deadline_72h'] = CarbonImmutable::parse($data['detected_at'])->addHours(72);
        }

        if (isset($data['authority_notified_at']) && ! isset($data['status']) && $incident->status === BreachIncidentStatus::Investigating) {
            $data['status'] = BreachIncidentStatus::NotifiedAuthority;
        }

        $incident->update($data);
        $this->audit->record((string) $incident->tenant_id, 'PRIVACY_BREACH_INCIDENT_UPDATED', DataBreachIncident::class, (string) $incident->id, ['status' => $incident->status->value]);

        return $incident->refresh();
    }

    /**
     * Incidents whose authority notification is still pending, closest deadline first.
     *
     * @return Collection<int, DataBreachIncident>
     */
    public function activeCountdown(string $tenantId): Collection
    {
        return DataBreachIncident::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)
            ->whereNull('authority_notified_at')->where('status', '!=', BreachIncidentStatus::Closed->value)
            ->orderBy('notification_deadline_72h')->get();
    }

    /**
     * Pre-filled notification draft for the competent authority (or both).
     *
     * @return array<string, mixed>
     */
    public function dossier(DataBreachIncident $incident, string $companyName): array
    {
        $authorities = match ($incident->jurisdiction) {
            BreachJurisdiction::ItGdprGarante => [$this->garante()],
            BreachJurisdiction::ChNlpdFdpic => [$this->fdpic()],
            BreachJurisdiction::BothChIt => [$this->garante(), $this->fdpic()],
        };

        $facts = [
            'incident_code' => $incident->incident_code,
            'controller' => $companyName,
            'title' => $incident->title,
            'description' => $incident->description,
            'detected_at' => $incident->detected_at->toIso8601String(),
            'notification_deadline' => $incident->notification_deadline_72h->toIso8601String(),
            'severity' => $incident->severity->value,
            'risk_to_rights_and_freedoms' => $incident->risk_to_rights_and_freedoms->value,
            'affected_subjects_count' => $incident->affected_subjects_count,
            'affected_data_categories' => $incident->affected_data_categories ?? [],
            'containment_measures' => $incident->containment_measures,
            'remediation_plan' => $incident->remediation_plan,
            'late_notification_reason_required' => $incident->authority_notified_at === null && $incident->notification_deadline_72h->isPast(),
        ];

        return ['facts' => $facts, 'authorities' => $authorities];
    }

    /**
     * @return array<string, mixed>
     */
    private function garante(): array
    {
        return [
            'code' => 'IT_GDPR_Garante',
            'name' => 'Garante per la protezione dei dati personali',
            'legal_basis' => 'Art. 33 GDPR; Art. 34 GDPR (communication to data subjects when high risk)',
            'channel' => 'Online procedure for data breach notification of the Garante',
            'mandatory_fields' => ['nature of the breach', 'categories and approximate number of data subjects and records', 'DPO contact', 'likely consequences', 'measures taken or proposed'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fdpic(): array
    {
        return [
            'code' => 'CH_nLPD_FDPIC',
            'name' => 'FDPIC / IFPDT / EDÖB (Federal Data Protection and Information Commissioner)',
            'legal_basis' => 'Art. 24 nLPD; Art. 15 ODPA (content of the notification)',
            'channel' => 'DataBreach portal of the FDPIC',
            'mandatory_fields' => ['nature of the breach', 'time and duration', 'categories of personal data and data subjects', 'consequences including risks', 'measures taken or planned'],
        ];
    }
}
