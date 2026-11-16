<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Services\SupplierQualificationService;
use Modules\SupplierPortal\Models\DocumentExpiryAlert;
use Modules\SupplierPortal\Models\SupplierDocumentSubmission;
use Modules\SupplierPortal\Notifications\SupplierDocumentExpiringNotification;

/**
 * Daily scan of the accepted supplier documents of the active company. Each document alerts the purchasing staff once
 * per step: the alert window of its requirement, the last week, and the day it expires (threshold 0). Then the
 * qualification of the suppliers with an expired mandatory document is marked expired in the procurement module and
 * the risk flag of the suppliers involved is recalculated.
 */
final readonly class DocumentExpiryAlertService
{
    /**
     * Roles that receive the alerts.
     *
     * @var list<string>
     */
    public const array RecipientRoles = ['owner', 'admin', 'purchasing', 'procurement_manager'];

    public function __construct(
        private SupplierDocumentService $documents,
        private SupplierQualificationService $qualification,
        private SupplierRiskService $risk,
        private SupplierPortalLedger $ledger,
    ) {}

    /**
     * @return array{alerts: int, qualifications_expired: int, suppliers_evaluated: int}
     */
    public function run(string $tenantId): array
    {
        $recipients = $this->recipients($tenantId);
        $alerts = 0;
        $touched = [];
        $windowDays = (int) (DB::table('sp_document_requirements')->where('tenant_id', $tenantId)->max('alert_days') ?? 30);

        foreach ($this->documents->expiring(max($windowDays, (int) config('supplier_portal.final_alert_days'))) as $submission) {
            $daysLeft = (int) now()->startOfDay()->diffInDays($submission->expires_at->startOfDay(), false);
            $threshold = $this->thresholdFor($daysLeft, (int) ($submission->requirement?->alert_days ?? 30));

            if ($threshold === null) {
                continue;
            }

            $touched[(string) $submission->supplier_id] = true;

            if (! $this->claim($submission, $threshold)) {
                continue;
            }

            Notification::send($recipients, new SupplierDocumentExpiringNotification(
                (string) ($submission->supplier?->company_name ?? ''),
                (string) ($submission->requirement?->name ?? ''),
                $submission->expires_at->toDateString(),
                $daysLeft,
                (string) $submission->supplier_id,
            ));

            $this->ledger->record('SUPPLIER_DOCUMENT_EXPIRY_ALERTED', $submission, ['supplier_id' => $submission->supplier_id, 'threshold_days' => $threshold]);
            $alerts++;
        }

        $expired = $this->qualification->expireQualifications();

        foreach (array_keys($touched) as $supplierId) {
            $supplier = Supplier::query()->find($supplierId);

            if ($supplier !== null) {
                $this->risk->evaluate($supplier);
            }
        }

        return ['alerts' => $alerts, 'qualifications_expired' => $expired, 'suppliers_evaluated' => count($touched)];
    }

    /**
     * Which alert step a document has reached, or null while it is outside every window.
     */
    public function thresholdFor(int $daysLeft, int $requirementAlertDays): ?int
    {
        return match (true) {
            $daysLeft < 0 => 0,
            $daysLeft <= (int) config('supplier_portal.final_alert_days') => (int) config('supplier_portal.final_alert_days'),
            $daysLeft <= $requirementAlertDays => $requirementAlertDays,
            default => null,
        };
    }

    private function claim(SupplierDocumentSubmission $submission, int $threshold): bool
    {
        $alert = DocumentExpiryAlert::query()->firstOrCreate(
            ['tenant_id' => $submission->tenant_id, 'submission_id' => $submission->id, 'threshold_days' => $threshold],
            ['sent_at' => now()],
        );

        return $alert->wasRecentlyCreated;
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(string $tenantId): Collection
    {
        $ids = DB::table('tenant_user')->where('tenant_id', $tenantId)->whereIn('role', self::RecipientRoles)->pluck('user_id')->all();

        return User::query()->whereIn('id', $ids)->get();
    }
}
