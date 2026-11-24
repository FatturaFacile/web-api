<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\Privacy\BreachIncidentStatus;
use App\Enums\Privacy\DsarStatus;
use App\Models\Concerns\HasEncryptedFields;
use App\Models\Privacy\DataBreachIncident;
use App\Models\Privacy\PrivacySubjectRequestDsar;
use App\Queries\Audit\VerifyAuditChainHandler;
use App\Queries\Audit\VerifyAuditChainQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The KPIs of the Security, Privacy & Compliance Center for one tenant.
 */
final class SecurityPostureService
{
    public function __construct(private readonly VerifyAuditChainHandler $verifier) {}

    /**
     * @return array<string, mixed>
     */
    public function forTenant(string $tenantId): array
    {
        $certificate = $this->verifier->handle(new VerifyAuditChainQuery($tenantId));
        $coverage = $this->coverage($tenantId);

        $openDsar = PrivacySubjectRequestDsar::query()
            ->whereNotIn('status', [DsarStatus::Completed->value, DsarStatus::PartiallyRefusedFiscalRetention->value, DsarStatus::Rejected->value]);
        $holds = PrivacySubjectRequestDsar::query()->where('status', DsarStatus::PartiallyRefusedFiscalRetention->value)->count();
        $openIncidents = DataBreachIncident::query()->where('status', '!=', BreachIncidentStatus::Closed->value)->count();
        $nearestDue = (clone $openDsar)->min('statutory_deadline_at');

        $checks = [
            $certificate->isValid,
            $coverage['percent'] >= 100.0,
            ! DataBreachIncident::query()->where('status', '!=', BreachIncidentStatus::Closed->value)->whereNull('authority_notified_at')->where('notification_deadline_72h', '<', now())->exists(),
            ! (clone $openDsar)->where('statutory_deadline_at', '<', now())->exists(),
        ];

        return [
            'audit_chain' => [
                'status' => $certificate->isValid ? 'verified' : 'tamper_alert',
                'last_verified_at' => $certificate->verifiedAt,
                'last_hash' => $certificate->merkleRoot,
                'broken_at_sequence' => $certificate->brokenAtSequence,
            ],
            'encrypted_fields_coverage' => $coverage,
            'active_dsar' => ['count' => (clone $openDsar)->count(), 'nearest_due_at' => $nearestDue],
            'legal_holds_active' => $holds,
            'open_incidents' => $openIncidents,
            'readiness_score' => (int) round(count(array_filter($checks)) / count($checks) * 100),
        ];
    }

    /**
     * @return array{encrypted: int, total: int, percent: float}
     */
    private function coverage(string $tenantId): array
    {
        $encrypted = 0;
        $total = 0;

        /** @var array<string, class-string<Model>> $models */
        $models = config('field_encryption.models', []);

        foreach ($models as $class) {
            $model = new $class;

            if (! in_array(HasEncryptedFields::class, class_uses_recursive($model), true) || ! method_exists($model, 'encryptedFieldMap')) {
                continue;
            }

            foreach (array_keys($model->encryptedFieldMap()) as $field) {
                $base = DB::table($model->getTable())->where('tenant_id', $tenantId)->whereNotNull($field)->where($field, '!=', '');
                $total += (clone $base)->count();
                $encrypted += (clone $base)->where($field, 'like', 'ff1:%')->count();
            }
        }

        return ['encrypted' => $encrypted, 'total' => $total, 'percent' => $total === 0 ? 100.0 : round($encrypted / $total * 100, 1)];
    }
}
