<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\AssetManagement\Events\AssetDepreciationPostedEvent;
use Modules\AssetManagement\Events\AssetDisposedEvent;
use Modules\AssetManagement\Events\AssetImpairedEvent;
use Modules\AssetManagement\Events\AssetRegisteredEvent;
use Modules\AssetManagement\Events\AssetRevaluedEvent;
use Modules\GeneralLedger\Events\JournalPostedEvent;
use Modules\GeneralLedger\Events\JournalReversedEvent;
use Modules\GeneralLedger\Events\PassiveInvoicePostedEvent;
use Modules\Inventory\Events\InventoryRevaluedEvent;
use Modules\Inventory\Events\StockTransferredEvent;
use Modules\Invoicing\Events\CreditNoteIssuedEvent;
use Modules\Invoicing\Events\CustomerCreditLimitUpdatedEvent;
use Modules\Invoicing\Events\InvoicePaidEvent;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\TimeTravel\Console\BackfillAndSnapshotCommand;
use Modules\TimeTravel\Listeners\FixedAssetEventCapture;
use Modules\TimeTravel\Listeners\GeneralLedgerEventCapture;
use Modules\TimeTravel\Listeners\InventoryEventCapture;
use Modules\TimeTravel\Listeners\OpenItemsEventCapture;
use Modules\Treasury\Events\CollectionReceivedEvent;
use Modules\Treasury\Events\PaymentExecutedEvent;

/**
 * Mod 354 — bi-temporal event sourcing and time-travel audit engine (Issue #600). The listeners append the writes of the four domains
 * (general ledger, open items, warehouse, fixed assets) to the immutable event store inside the transaction of the write itself.
 */
final class TimeTravelServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([BackfillAndSnapshotCommand::class]);
        }

        Event::listen(JournalPostedEvent::class, [GeneralLedgerEventCapture::class, 'onJournalPosted']);
        Event::listen(JournalReversedEvent::class, [GeneralLedgerEventCapture::class, 'onJournalReversed']);

        Event::listen(InvoicePostedEvent::class, [OpenItemsEventCapture::class, 'onInvoicePosted']);
        Event::listen(CreditNoteIssuedEvent::class, [OpenItemsEventCapture::class, 'onCreditNoteIssued']);
        Event::listen(CollectionReceivedEvent::class, [OpenItemsEventCapture::class, 'onCollectionReceived']);
        Event::listen(InvoicePaidEvent::class, [OpenItemsEventCapture::class, 'onInvoicePaid']);
        Event::listen(CustomerCreditLimitUpdatedEvent::class, [OpenItemsEventCapture::class, 'onCreditLimitUpdated']);
        Event::listen(PassiveInvoicePostedEvent::class, [OpenItemsEventCapture::class, 'onPassiveInvoicePosted']);
        Event::listen(PaymentExecutedEvent::class, [OpenItemsEventCapture::class, 'onPaymentExecuted']);

        Event::listen(StockTransferredEvent::class, [InventoryEventCapture::class, 'onStockTransferred']);
        Event::listen(InventoryRevaluedEvent::class, [InventoryEventCapture::class, 'onRevalued']);

        Event::listen(AssetRegisteredEvent::class, [FixedAssetEventCapture::class, 'onRegistered']);
        Event::listen(AssetDepreciationPostedEvent::class, [FixedAssetEventCapture::class, 'onDepreciationPosted']);
        Event::listen(AssetRevaluedEvent::class, [FixedAssetEventCapture::class, 'onRevalued']);
        Event::listen(AssetImpairedEvent::class, [FixedAssetEventCapture::class, 'onImpaired']);
        Event::listen(AssetDisposedEvent::class, [FixedAssetEventCapture::class, 'onDisposed']);
    }
}
