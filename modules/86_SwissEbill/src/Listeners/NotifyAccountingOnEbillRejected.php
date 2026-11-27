<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\SwissEbill\Application\Services\EbillPreflightService;
use Modules\SwissEbill\Domain\Events\EbillRejected;
use Modules\SwissEbill\Models\EbillTransmission;
use Modules\SwissEbill\Notifications\EbillRejectedNotification;
use Throwable;

/**
 * A debtor rejected the invoice: the owners, administrators and accountants of the company hear about it at once.
 */
final class NotifyAccountingOnEbillRejected
{
    private const array ROLES = ['owner', 'admin', 'accountant'];

    public function handle(EbillRejected $event): void
    {
        try {
            $transmission = EbillTransmission::withoutGlobalScopes()->with(['invoice', 'contact'])->find($event->transmissionId);

            if ($transmission === null) {
                return;
            }

            $recipients = User::query()
                ->whereHas('tenants', fn ($query) => $query->where('tenants.id', $event->tenantId)->whereIn('tenant_user.role', self::ROLES))
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            Notification::send($recipients, new EbillRejectedNotification(
                $transmission->id,
                $transmission->invoice_id,
                (string) $transmission->invoice?->invoice_number,
                $transmission->contact !== null ? EbillPreflightService::displayName($transmission->contact) : '',
                $transmission->rejection_code,
                $transmission->rejection_reason,
            ));
        } catch (Throwable $e) {
            // The state change is already committed: a mail problem must not undo or hide it.
            Log::warning('Swiss eBill rejection notification failed', ['transmission_id' => $event->transmissionId, 'error' => $e->getMessage()]);
        }
    }
}
