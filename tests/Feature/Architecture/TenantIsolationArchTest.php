<?php

declare(strict_types=1);

use App\Models\Concerns\BelongsToTenant;
use App\Models\ContactAddress;
use App\Models\DeliveryNoteItem;
use App\Models\EiInboundLog;
use App\Models\ExchangeRate;
use App\Models\GeneralLedgerLine;
use App\Models\GoodsReceiptItem;
use App\Models\InvoiceItem;
use App\Models\InvoiceQrBillData;
use App\Models\InvoiceSdiMetadata;
use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use App\Models\PaymentTermInstallment;
use App\Models\PosSaleItem;
use App\Models\PriceListItem;
use App\Models\ProductionOrderStage;
use App\Models\PurchaseOrderItem;
use App\Models\QuoteItem;
use App\Models\RtFiscalReceipt;
use App\Models\SalesOrderItem;
use App\Models\SubscriptionItem;
use App\Models\Tenant;
use App\Models\Translation;
use App\Models\User;
use App\Models\WorkOrderLine;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;

arch('api controllers do not use raw DB facade or DatabaseManager')
    ->expect('App\Http\Controllers\Api')
    ->not->toUse([
        DB::class,
        DatabaseManager::class,
    ]);

test('api controllers never call DB table directly', function (): void {
    $finder = (new Finder)
        ->in(app_path('Http/Controllers/Api'))
        ->files()
        ->name('*.php');

    foreach ($finder as $file) {
        $content = $file->getContents();
        expect($content)->not->toMatch('/DB::table\s*\(/', "Controller {$file->getRelativePathname()} contains a direct DB::table call.");
    }
});

test('concrete domain models use BelongsToTenant trait', function (): void {
    $finder = (new Finder)
        ->in(app_path('Models'))
        ->files()
        ->name('*.php');

    $excludedModels = [
        // Central models
        Tenant::class,
        User::class,
        Translation::class,
        ExchangeRate::class,
        EiInboundLog::class,
        LookupCategory::class,
        LookupItem::class,
        LookupItemTranslation::class,
        // Child lines, sub-resources, and dependent metadata
        ContactAddress::class,
        DeliveryNoteItem::class,
        GeneralLedgerLine::class,
        GoodsReceiptItem::class,
        InvoiceItem::class,
        InvoiceQrBillData::class,
        InvoiceSdiMetadata::class,
        PaymentTermInstallment::class,
        PosSaleItem::class,
        PriceListItem::class,
        ProductionOrderStage::class,
        PurchaseOrderItem::class,
        QuoteItem::class,
        RtFiscalReceipt::class,
        SalesOrderItem::class,
        SubscriptionItem::class,
        WorkOrderLine::class,
    ];

    $nonCompliant = [];

    foreach ($finder as $file) {
        // Skip subdirectories (e.g. Scopes, Concerns)
        if (str_contains($file->getRelativePathname(), DIRECTORY_SEPARATOR)) {
            continue;
        }

        $className = 'App\\Models\\'.$file->getFilenameWithoutExtension();
        if (! class_exists($className)) {
            continue;
        }

        $reflection = new ReflectionClass($className);
        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        if (in_array($className, $excludedModels, true)) {
            continue;
        }

        $traits = class_uses_recursive($className);
        if (! in_array(BelongsToTenant::class, $traits, true)) {
            $nonCompliant[] = $className;
        }
    }

    expect($nonCompliant)->toBeEmpty(
        'The following concrete models are missing BelongsToTenant: '.implode(', ', $nonCompliant)
    );
});
