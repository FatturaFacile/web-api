<?php

declare(strict_types=1);

namespace Modules\Platform\Listeners;

use App\Models\Product;
use Modules\Inventory\Events\StockBelowMinimumEvent;
use Modules\Invoicing\Events\InvoicePaidEvent;
use Modules\Platform\Services\TenantNotifier;
use Modules\SDI\Events\SdiInvoiceRejected;
use Modules\Workflow\Events\ApprovalTokenGeneratedEvent;
use Throwable;

/**
 * Routes the business events of the notification catalogue (invoice paid, SDI rejection, low stock, approval pending)
 * through the NotificationDispatcher, so the preference matrix, quiet hours, snooze, digest and the delivery register
 * apply to them. A notification failure never fails the business operation that raised the event.
 */
final readonly class NotifyBusinessEvents
{
    public function __construct(private TenantNotifier $notifier) {}

    public function handleInvoicePaid(InvoicePaidEvent $event): void
    {
        $this->safely(fn (): int => $this->notifier->notify(
            $event->tenantId,
            'billing',
            __('Invoice :number paid', ['number' => $event->invoiceNumber]),
            __('Invoice :number was fully paid (:amount :currency)', ['number' => $event->invoiceNumber, 'amount' => number_format($event->totalPaid, 2, '.', ''), 'currency' => $event->currency]),
            '/invoicing/invoices/'.$event->invoiceId,
        ));
    }

    public function handleSdiRejected(SdiInvoiceRejected $event): void
    {
        $first = $event->errors[0]['description'] ?? null;

        $this->safely(fn (): int => $this->notifier->notify(
            $event->tenantId,
            'billing',
            __('Invoice :number rejected by SDI', ['number' => $event->documentNumber]),
            $first !== null ? __('SDI rejection notice (NS): :error', ['error' => $first]) : __('SDI rejection notice (NS): correct and resubmit the invoice'),
            $event->invoiceId !== null ? '/invoicing/invoices/'.$event->invoiceId : '/sdi/documents',
        ));
    }

    public function handleStockBelowMinimum(StockBelowMinimumEvent $event): void
    {
        $this->safely(function () use ($event): int {
            $name = $event->productId !== null
                ? (string) (Product::query()->withoutGlobalScopes()->whereKey($event->productId)->value('name') ?? $event->productId)
                : (string) $event->stockItemId;

            return $this->notifier->notify(
                $event->tenantId,
                'inventory',
                __('Low stock: :product', ['product' => $name]),
                __('Available :available, minimum :minimum', ['available' => $event->availableStock, 'minimum' => $event->minimumStock]),
                '/inventory/stock',
            );
        });
    }

    public function handleApprovalPending(ApprovalTokenGeneratedEvent $event): void
    {
        $this->safely(fn (): int => $this->notifier->notify(
            $event->tenantId,
            'general',
            __('Approval pending'),
            __('A :action decision is waiting for you', ['action' => $event->actionType]),
            $event->actionUrl,
            [$event->authorizedUserId],
        ));
    }

    /**
     * @param  callable(): int  $send
     */
    private function safely(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
