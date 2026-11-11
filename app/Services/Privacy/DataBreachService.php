<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\Privacy\PrivacyDataBreach;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Operations on the register of personal data breaches: containment, notification of the authority (72 hours from
 * awareness, GDPR art. 33(1); nLPD art. 24 "as soon as possible", which this system also tracks with the same 72 hour
 * clock, to be confirmed), notification of the data subjects, and closure. A notification after the deadline must carry
 * the reason for the delay (art. 33(1) GDPR). Every step goes to the audit ledger.
 */
final readonly class DataBreachService
{
    public function __construct(private PrivacyAudit $audit) {}

    public function find(string $tenantId, string $id): PrivacyDataBreach
    {
        return PrivacyDataBreach::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->whereKey($id)->firstOrFail();
    }

    /**
     * @return Collection<int, PrivacyDataBreach>
     */
    public function list(string $tenantId, ?string $status = null, bool $overdueOnly = false): Collection
    {
        $breaches = PrivacyDataBreach::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->when($status !== null, static fn ($query) => $query->where('status', $status))
            ->orderByDesc('discovered_at')
            ->get();

        return $overdueOnly ? $breaches->filter(static fn (PrivacyDataBreach $breach): bool => $breach->is_notification_overdue)->values() : $breaches;
    }

    public function contain(PrivacyDataBreach $breach, ?string $measures, ?string $actorId): PrivacyDataBreach
    {
        return $this->change($breach, ['investigating'], 'PRIVACY_BREACH_CONTAINED', function (PrivacyDataBreach $locked) use ($measures): array {
            return [
                'status' => 'contained',
                'contained_at' => CarbonImmutable::now(),
                'mitigation_measures' => $measures ?? $locked->mitigation_measures,
            ];
        }, $actorId);
    }

    /**
     * Records the notification to the supervisory authority (Swiss FDPIC for nLPD, the competent EU authority for GDPR).
     *
     * @param  string  $regulation  nLPD or GDPR
     */
    public function notifyAuthority(PrivacyDataBreach $breach, string $regulation, ?CarbonImmutable $notifiedAt, ?string $delayReason, ?string $actorId): PrivacyDataBreach
    {
        $notifiedAt ??= CarbonImmutable::now();

        return $this->change($breach, ['investigating', 'contained', 'notified'], 'PRIVACY_BREACH_AUTHORITY_NOTIFIED', function (PrivacyDataBreach $locked) use ($regulation, $notifiedAt, $delayReason): array {
            if ($locked->authorityNotifiedAt() !== null) {
                throw new RuntimeException('The authority was already notified for this breach.');
            }

            if ($notifiedAt->lessThan($locked->discovered_at)) {
                throw new RuntimeException('The notification cannot precede the discovery of the breach.');
            }

            if ($notifiedAt->greaterThan($locked->notification_deadline_at) && blank($delayReason)) {
                throw new RuntimeException('The 72 hour deadline passed: the reason for the delay is required (art. 33(1) GDPR).');
            }

            return [
                ($regulation === 'GDPR' ? 'gdpr_authority_notified_at' : 'fdpic_notified_at') => $notifiedAt,
                'delay_reason' => $delayReason ?? $locked->delay_reason,
                'requires_authority_notification' => true,
                'status' => 'notified',
            ];
        }, $actorId);
    }

    /**
     * The breach is unlikely to result in a risk: notification not required, with the reason (art. 33(1) GDPR). The
     * reason is the accountability record.
     */
    public function markAuthorityNotificationNotRequired(PrivacyDataBreach $breach, string $reason, ?string $actorId): PrivacyDataBreach
    {
        return $this->change($breach, ['investigating', 'contained'], 'PRIVACY_BREACH_NOTIFICATION_NOT_REQUIRED', static fn (): array => [
            'requires_authority_notification' => false,
            'authority_not_required_reason' => $reason,
        ], $actorId);
    }

    public function notifySubjects(PrivacyDataBreach $breach, ?CarbonImmutable $notifiedAt, ?string $actorId): PrivacyDataBreach
    {
        return $this->change($breach, ['investigating', 'contained', 'notified'], 'PRIVACY_BREACH_SUBJECTS_NOTIFIED', static fn (): array => [
            'subjects_notified_at' => $notifiedAt ?? CarbonImmutable::now(),
        ], $actorId);
    }

    /**
     * Closes the breach. The authority notification must be done, or declared not required with a reason; when the data
     * subjects have to be notified, that must be done too.
     */
    public function close(PrivacyDataBreach $breach, ?string $actorId): PrivacyDataBreach
    {
        return $this->change($breach, ['investigating', 'contained', 'notified'], 'PRIVACY_BREACH_CLOSED', function (PrivacyDataBreach $locked): array {
            if ($locked->awaitsAuthorityNotification()) {
                throw new RuntimeException('Notify the authority, or declare the notification not required with a reason, before closing the breach.');
            }

            if ($locked->requires_subject_notification && $locked->subjects_notified_at === null) {
                throw new RuntimeException('The data subjects must be notified before closing the breach.');
            }

            return ['status' => 'closed', 'closed_at' => CarbonImmutable::now()];
        }, $actorId);
    }

    /**
     * @param  list<string>  $from
     * @param  callable(PrivacyDataBreach): array<string, mixed>  $changes
     */
    private function change(PrivacyDataBreach $breach, array $from, string $action, callable $changes, ?string $actorId): PrivacyDataBreach
    {
        return DB::transaction(function () use ($breach, $from, $action, $changes, $actorId): PrivacyDataBreach {
            $locked = PrivacyDataBreach::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $breach->tenant_id)
                ->whereKey($breach->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw new RuntimeException("Breach is {$locked->status}: expected ".implode(' or ', $from).'.');
            }

            $previous = $locked->status;
            $locked->update($changes($locked));
            $this->audit->record((string) $locked->tenant_id, $action, PrivacyDataBreach::class, (string) $locked->id, [
                'from' => $previous,
                'to' => $locked->status,
                'overdue' => $locked->is_notification_overdue,
            ], $actorId);

            return $locked->refresh();
        });
    }
}
