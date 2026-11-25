<?php

declare(strict_types=1);

namespace Modules\Intercompany\Services;

use App\Models\PassiveInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationMath;
use Modules\Intercompany\Enums\DocumentKind;
use Modules\Intercompany\Enums\LinkStatus;
use Modules\Intercompany\Enums\PaymentState;
use Modules\Intercompany\Enums\SyncEventType;
use Modules\Intercompany\Exceptions\IntercompanyConflictException;
use Modules\Intercompany\Models\TransactionLink;
use Modules\Intercompany\Support\GroupScope;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * Carries the settlement of an intercompany document over to its mirror. What the counterparty has paid on the source
 * invoice is applied to the supplier document of the mirror: a partial payment leaves it `partial`, a payment that
 * covers the amount (within the amount tolerance of the group, for bank charges and rounding) closes it `paid`, and the
 * rest is kept as `paid_variance` and logged. Every step is an event of the sync log; applying the same payment twice
 * changes nothing.
 */
final readonly class PaymentSyncService
{
    public function __construct(
        private GroupScope $scope,
        private SettingsService $settings,
        private SyncLog $log,
    ) {}

    public function sync(ConsolidationGroup $group, TransactionLink $link, ?User $user = null, bool $enforceSetting = false): TransactionLink
    {
        return $this->scope->runAs((string) $group->tenant_id, function () use ($group, $link, $user, $enforceSetting): TransactionLink {
            $settings = $this->settings->forGroup($group);

            if ($enforceSetting && ! $settings->sync_payments) {
                return $link;
            }

            if ($link->mirror_document_id === null || in_array($link->status, [LinkStatus::Reversed->value, LinkStatus::Failed->value, LinkStatus::Pending->value], true)) {
                throw new IntercompanyConflictException('The mirror document does not exist or was reversed: there is nothing to synchronise.', 'mirror_not_available');
            }

            if ($link->document_type === DocumentKind::CreditNote->value) {
                return $link;
            }

            $source = SalesInvoice::withoutGlobalScopes()->where('tenant_id', $link->source_tenant_id)->findOrFail($link->source_document_id);
            $paid = ConsolidationMath::norm((string) ($source->total_paid ?? '0'));
            $gross = ConsolidationMath::norm((string) $link->gross_amount);
            $tolerance = ConsolidationMath::norm((string) $settings->amount_tolerance);

            if (ConsolidationMath::cmp($paid, (string) $link->synced_paid_amount) === 0) {
                return $link;
            }

            $remaining = ConsolidationMath::sub($gross, $paid);
            $closes = ConsolidationMath::cmp($paid, '0') > 0 && ConsolidationMath::cmp(ConsolidationMath::abs($remaining), $tolerance) <= 0;
            $applied = $closes ? $gross : ConsolidationMath::min($paid, $gross);
            $state = match (true) {
                $closes, ConsolidationMath::cmp($applied, $gross) >= 0 => PaymentState::Paid,
                ConsolidationMath::cmp($applied, '0') > 0 => PaymentState::Partial,
                default => PaymentState::Unpaid,
            };
            $variance = $closes ? $remaining : ConsolidationMath::zero();

            return DB::transaction(function () use ($group, $link, $user, $paid, $applied, $state, $variance): TransactionLink {
                $this->scope->runAs((string) $link->mirror_tenant_id, function () use ($link, $applied, $state): void {
                    $passive = PassiveInvoice::query()->findOrFail($link->mirror_document_id);
                    $passive->forceFill(['total_paid' => $applied, 'payment_status' => $state->value])->save();
                });

                $link->forceFill([
                    'synced_paid_amount' => $paid,
                    'payment_status' => $state->value,
                    'paid_variance' => $variance,
                    'version' => $link->version + 1,
                ])->save();

                $this->log->record((string) $group->tenant_id, (string) $group->id, $link, SyncEventType::PaymentSynced, null, ['payment_status' => $state->value, 'applied' => $applied], $paid, $user?->id);

                if (! ConsolidationMath::isZero($variance)) {
                    $this->log->record((string) $group->tenant_id, (string) $group->id, $link, SyncEventType::PaymentVariance, 'intercompany.error.payment_variance', ['variance' => $variance, 'currency' => $link->currency], $variance, $user?->id);
                }

                return $link->refresh();
            });
        });
    }
}
