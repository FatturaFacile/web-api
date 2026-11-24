<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ApprovalStatus;
use App\Models\ApprovalStatusTranslation;
use App\Models\ChartOfAccount;
use App\Models\ContactCategoryTranslation;
use App\Models\ContactTypeTranslation;
use App\Models\Country;
use App\Models\CountryTranslation;
use App\Models\CurrencyTranslation;
use App\Models\DocumentStatusTranslation;
use App\Models\ExchangeRate;
use App\Models\ExpenseCategoryTranslation;
use App\Models\GeneralLedgerEntry;
use App\Models\GeneralLedgerLine;
use App\Models\InventoryMovementTypeTranslation;
use App\Models\LookupItemTranslation;
use App\Models\NotificationTypeTranslation;
use App\Models\PaymentMethodTranslation;
use App\Models\PaymentTermTranslation;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderStage;
use App\Models\ProjectStatusTranslation;
use App\Models\Region;
use App\Models\RegionTranslation;
use App\Models\TaskStatusTranslation;
use App\Models\Translation;
use App\Models\VatCodeTranslation;
use App\Models\WorkflowStateTranslation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;

test('has translations trait resolves current, specific, and fallback translations', function (): void {
    $approvalStatus = new ApprovalStatus([
        'code' => 'APPROVED',
        'color_badge' => '#00FF00',
    ]);

    // Test relationship definitions
    expect($approvalStatus->translations())->toBeInstanceOf(HasMany::class);
    expect($approvalStatus->currentTranslation())->toBeInstanceOf(HasOne::class);
    expect($approvalStatus->translationForLocale('it'))->toBeInstanceOf(HasOne::class);

    // Mock collection of translations on the model instance
    $itTrans = new ApprovalStatusTranslation([
        'locale' => 'it',
        'name' => 'Approvato',
        'description' => 'Documento approvato',
    ]);
    $enTrans = new ApprovalStatusTranslation([
        'locale' => 'en',
        'name' => 'Approved',
        'description' => 'Document approved',
    ]);

    $approvalStatus->setRelation('translations', collect([$itTrans, $enTrans]));

    App::setLocale('it');
    expect($approvalStatus->getTranslation('name'))->toBe('Approvato')
        ->and($approvalStatus->getTranslation('name', 'en'))->toBe('Approved')
        ->and($approvalStatus->getTranslation('name', 'de'))->toBe('Approved') // fallback to en
        ->and($approvalStatus->getTranslation('non_existent'))->toBeNull();

    // When empty translations
    $emptyStatus = new ApprovalStatus;
    $emptyStatus->setRelation('translations', collect());
    expect($emptyStatus->getTranslation('name'))->toBeNull();
});

test('general ledger line model configuration, casts, and relations', function (): void {
    $line = new GeneralLedgerLine([
        'general_ledger_entry_id' => '00000000-0000-0000-0000-000000000001',
        'account_id' => '00000000-0000-0000-0000-000000000002',
        'line_number' => 1,
        'debit' => 150.50,
        'credit' => 0.00,
        'description' => 'Inventory purchase debit',
    ]);

    expect($line->getTable())->toBe('general_ledger_lines')
        ->and($line->line_number)->toBe(1)
        ->and($line->entry())->toBeInstanceOf(BelongsTo::class)
        ->and($line->entry()->getRelated())->toBeInstanceOf(GeneralLedgerEntry::class)
        ->and($line->account())->toBeInstanceOf(BelongsTo::class)
        ->and($line->account()->getRelated())->toBeInstanceOf(ChartOfAccount::class);
});

test('production order stage model configuration, casts, and relations', function (): void {
    $stage = new ProductionOrderStage([
        'production_order_id' => '00000000-0000-0000-0000-000000000001',
        'stage_name' => 'Assembly',
        'sequence' => 2,
        'planned_hours' => 12.50,
        'actual_hours' => 10.00,
        'status' => 'in_progress',
    ]);

    expect($stage->getTable())->toBe('production_order_stages')
        ->and($stage->sequence)->toBe(2)
        ->and($stage->stage_name)->toBe('Assembly')
        ->and($stage->productionOrder())->toBeInstanceOf(BelongsTo::class)
        ->and($stage->productionOrder()->getRelated())->toBeInstanceOf(ProductionOrder::class);
});

test('region model configuration, casts, and relations', function (): void {
    $region = new Region([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'country_id' => '00000000-0000-0000-0000-000000000002',
        'code' => 'LOM',
        'type' => 'region',
    ]);

    expect($region->getTable())->toBe('regions')
        ->and($region->code)->toBe('LOM')
        ->and($region->country())->toBeInstanceOf(BelongsTo::class)
        ->and($region->country()->getRelated())->toBeInstanceOf(Country::class)
        ->and($region->translations())->toBeInstanceOf(HasMany::class)
        ->and($region->translations()->getRelated())->toBeInstanceOf(RegionTranslation::class);
});

test('approval status model configuration and relations', function (): void {
    $status = new ApprovalStatus([
        'code' => 'REJECTED',
        'color_badge' => '#FF0000',
    ]);

    expect($status->getTable())->toBe('approval_statuses')
        ->and($status->code)->toBe('REJECTED')
        ->and($status->color_badge)->toBe('#FF0000')
        ->and($status->translations())->toBeInstanceOf(HasMany::class)
        ->and($status->translations()->getRelated())->toBeInstanceOf(ApprovalStatusTranslation::class);
});

test('exchange rate model configuration and casts', function (): void {
    $rate = new ExchangeRate([
        'source' => 'EUR',
        'target' => 'USD',
        'rate' => 1.085000,
        'effective_date' => '2026-10-31',
    ]);

    expect($rate->getTable())->toBe('exchange_rates')
        ->and($rate->source)->toBe('EUR')
        ->and($rate->target)->toBe('USD')
        ->and($rate->effective_date)->toBeInstanceOf(Carbon::class);
});

test('product category model configuration and relations', function (): void {
    $cat = new ProductCategory([
        'name' => 'Hardware',
        'parent_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    expect($cat->parent())->toBeInstanceOf(BelongsTo::class)
        ->and($cat->parent()->getRelated())->toBeInstanceOf(ProductCategory::class)
        ->and($cat->products())->toBeInstanceOf(HasMany::class)
        ->and($cat->products()->getRelated())->toBeInstanceOf(Product::class);
});

test('translation models instantiate and relate correctly', function (): void {
    $models = [
        new ApprovalStatusTranslation(['locale' => 'it', 'name' => 'Test']),
        new ContactCategoryTranslation(['locale' => 'it', 'name' => 'Test']),
        new ContactTypeTranslation(['locale' => 'it', 'name' => 'Test']),
        new CountryTranslation(['locale' => 'it', 'name' => 'Italia']),
        new CurrencyTranslation(['locale' => 'it', 'name' => 'Euro']),
        new DocumentStatusTranslation(['locale' => 'it', 'name' => 'Bozza']),
        new ExpenseCategoryTranslation(['locale' => 'it', 'name' => 'Spese']),
        new InventoryMovementTypeTranslation(['locale' => 'it', 'name' => 'Carico']),
        new LookupItemTranslation(['locale' => 'it', 'name' => 'Item']),
        new NotificationTypeTranslation(['locale' => 'it', 'name' => 'Email']),
        new PaymentMethodTranslation(['locale' => 'it', 'name' => 'Bonifico']),
        new PaymentTermTranslation(['locale' => 'it', 'name' => '30gg']),
        new ProjectStatusTranslation(['locale' => 'it', 'name' => 'Aperto']),
        new RegionTranslation(['locale' => 'it', 'name' => 'Lombardia']),
        new TaskStatusTranslation(['locale' => 'it', 'name' => 'In corso']),
        new Translation(['locale' => 'it', 'key' => 'general.save', 'value' => 'Salva']),
        new VatCodeTranslation(['locale' => 'it', 'name' => '22%']),
        new WorkflowStateTranslation(['locale' => 'it', 'name' => 'Approvato']),
    ];

    foreach ($models as $model) {
        expect($model->locale ?? $model->getAttribute('locale'))->toBe('it');
    }
});
