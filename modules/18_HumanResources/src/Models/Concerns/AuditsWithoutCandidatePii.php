<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models\Concerns;

use Enterprise\AuditLedger\Services\AuditLedgerService;
use Illuminate\Database\Eloquent\Model;

/**
 * Replaces the generic `Auditable` behaviour of enterprise models for recruiting records that hold candidate
 * personal data: the audit ledger only receives non-personal attributes, so that anonymising a candidate
 * (GDPR / nLPD) does not leave names, contacts or CV content behind in the immutable audit trail.
 */
trait AuditsWithoutCandidatePii
{
    /**
     * Overrides `Enterprise\Foundation\Concerns\Auditable::bootAuditable()`.
     */
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            self::recordRedactedAudit('CREATED', $model, null, $model->getAttributes());
        });

        static::updated(function (Model $model): void {
            self::recordRedactedAudit('UPDATED', $model, $model->getOriginal(), $model->getChanges());
        });

        static::deleted(function (Model $model): void {
            self::recordRedactedAudit('DELETED', $model, $model->getOriginal(), null);
        });
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private static function recordRedactedAudit(string $action, Model $model, ?array $before, ?array $after): void
    {
        if (! app()->bound(AuditLedgerService::class)) {
            return;
        }

        app(AuditLedgerService::class)->record(
            action: $action,
            model: $model,
            payloadBefore: $before === null ? null : self::withoutCandidatePii($before),
            payloadAfter: $after === null ? null : self::withoutCandidatePii($after),
            moduleCode: 'MODULE_18_HR'
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function withoutCandidatePii(array $payload): array
    {
        $safeKeys = [
            'id', 'tenant_id', 'reference', 'job_opening_id', 'application_id', 'stage', 'from_stage', 'to_stage',
            'status', 'source', 'locale', 'board_position', 'cv_parse_status', 'retention_months',
            'consent_given_at', 'consent_text_version', 'privacy_consent_expires_at', 'consent_extended_at',
            'rejection_reason_code', 'hired_employee_id', 'anonymized_at', 'interviewer_user_id', 'actor_user_id',
            'recommendation', 'overall_score', 'signature_status', 'signature_provider', 'sent_at', 'signed_at',
            'declined_at', 'created_at', 'updated_at', 'deleted_at',
        ];

        return array_intersect_key($payload, array_flip($safeKeys));
    }
}
