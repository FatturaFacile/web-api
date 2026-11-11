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
use App\Models\PasswordHistory;
use App\Models\PasswordResetToken;
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
use App\Models\TenantAccessRequest;
use App\Models\Translation;
use App\Models\User;
use App\Models\WorkOrderLine;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;

arch('api controllers do not use raw DB facade or DatabaseManager')
    ->expect('App\Http\Controllers\Api')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Database\DatabaseManager',
    ]);

test('api controllers never call DB facade directly', function (): void {
    $apiControllersPath = app_path('Http/Controllers/Api');

    if (! is_dir($apiControllersPath)) {
        return;
    }

    $finder = (new Finder)
        ->in($apiControllersPath)
        ->files()
        ->name('*.php');

    $violations = [];

    foreach ($finder as $file) {
        $content = $file->getContents();

        if (preg_match('/\bDB::|\buse\s+Illuminate\\\\Support\\\\Facades\\\\DB\b/', $content)) {
            $violations[] = $file->getRelativePathname();
        }
    }

    expect($violations)->toBeEmpty(
        'Direct DB facade usage detected in API controllers: '.implode(', ', $violations)
    );
});

test('concrete domain models use BelongsToTenant trait', function (): void {
    $modelsPath = app_path('Models');

    if (! is_dir($modelsPath)) {
        return;
    }

    $finder = (new Finder)
        ->in($modelsPath)
        ->files()
        ->name('*.php');

    $excludedModels = [
        // Central models
        Tenant::class,
        TenantAccessRequest::class,
        PasswordResetToken::class,
        PasswordHistory::class,
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
        // Skip subdirectories (e.g. Scopes, Concerns, Enums)
        $relativePath = str_replace('\\', '/', $file->getRelativePathname());
        if (str_contains($relativePath, '/')) {
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
