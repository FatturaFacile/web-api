<?php

declare(strict_types=1);

namespace Tests\Feature\AssetManagement;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\AssetManagement\Contracts\AssetLifecycleServiceInterface;
use Modules\AssetManagement\Contracts\DepreciationEngineServiceInterface;
use Modules\AssetManagement\DTOs\CapitalizeAssetDTO;
use Modules\AssetManagement\DTOs\DisposeAssetDTO;
use Modules\AssetManagement\DTOs\RegisterAssetDTO;
use Modules\AssetManagement\DTOs\RunDepreciationDTO;
use Modules\AssetManagement\Exceptions\AssetOperationException;
use Modules\AssetManagement\Models\AssetBook;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Notifications\AssetCoverageExpiringNotification;
use Modules\AssetManagement\Services\AssetCoverageAlertService;
use Modules\AssetManagement\Services\DepreciationCalculator;
use Modules\AssetManagement\Support\DepreciationPeriod;
use Modules\AssetManagement\Support\Money;
use Modules\GeneralLedger\Models\JournalEntry;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Libro Cespiti', 'legal_name' => 'Libro Cespiti Srl', 'vat_number' => 'IT55555555555', 'country' => 'IT']);
    app(TenantContext::class)->setTenant($this->tenant);
    fiscalCalendar($this->tenant, (int) now()->year);

    $this->user = User::create(['name' => 'Accountant', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $this->category = AssetCategory::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'EQ',
        'name' => 'Equipment',
        'depreciation_method' => 'straight_line',
        'useful_life_years' => 5,
        'useful_life_months' => 60,
        'statutory_rate_percent' => 0,
        'salvage_value_percent' => 0,
        'pro_rata_convention' => 'monthly',
        'is_depreciable' => true,
        'is_active' => true,
    ]);
    $this->lifecycle = app(AssetLifecycleServiceInterface::class);
    $this->engine = app(DepreciationEngineServiceInterface::class);
});

/**
 * A capitalized asset of the test tenant.
 */
function ledgerTestAsset(string $cost = '12000.00', ?string $date = null): FixedAsset
{
    $asset = test()->lifecycle->registerAsset(new RegisterAssetDTO(
        tenantId: (string) test()->tenant->id,
        companyId: (string) test()->tenant->id,
        name: 'Press',
        categoryId: (string) test()->category->id,
        acquisitionCost: $cost,
    ), (string) test()->user->id);

    return test()->lifecycle->capitalizeAsset(new CapitalizeAssetDTO(
        assetId: (string) $asset->id,
        capitalizedCost: $cost,
        capitalizationDate: $date ?? now()->toDateString(),
    ), (string) test()->user->id);
}

/**
 * Debit and credit of every line of the journal entries of a source document, keyed by account code.
 *
 * @return array<string, array{debit: string, credit: string}>
 */
function ledgerLines(string $sourceType, string $sourceId): array
{
    $entry = JournalEntry::where('source_document_type', $sourceType)->where('source_document_id', $sourceId)->firstOrFail();
    $lines = [];
    $debit = '0';
    $credit = '0';

    foreach ($entry->lines()->with('account')->get() as $line) {
        $code = (string) $line->account->account_code;
        $lines[$code] = ['debit' => Money::round(Money::add($lines[$code]['debit'] ?? '0', $line->debit_amount)), 'credit' => Money::round(Money::add($lines[$code]['credit'] ?? '0', $line->credit_amount))];
        $debit = Money::add($debit, $line->debit_amount);
        $credit = Money::add($credit, $line->credit_amount);
    }

    expect(Money::round($debit))->toBe(Money::round($credit));

    return $lines;
}

test('capitalization posts Dr fixed asset / Cr clearing on the capitalization date', function (): void {
    $asset = ledgerTestAsset();

    expect(ledgerLines('eam_fixed_asset', (string) $asset->id))->toBe([
        '1600' => ['debit' => '12000.00', 'credit' => '0.00'],
        '2150' => ['debit' => '0.00', 'credit' => '12000.00'],
    ]);
    expect((string) JournalEntry::where('source_document_id', $asset->id)->value('posting_date'))->toStartWith(now()->toDateString());
});

test('a depreciation run posts the statutory book at period end and keeps the tax book off the ledger', function (): void {
    $this->category->update(['fiscal_rate_percent' => '15.0000', 'rate_reference' => 'Configured in the test']);
    $asset = ledgerTestAsset();
    $period = now()->format('Y-m');

    $run = $this->engine->executeDepreciationRun(new RunDepreciationDTO((string) $this->tenant->id, (string) $this->tenant->id, $period), (string) $this->user->id);
    $schedule = $asset->depreciationSchedules()->where('ledger_type', 'statutory')->firstOrFail();

    expect($run->total_depreciation_amount)->toBe('200.00')
        ->and(ledgerLines('eam_depreciation_schedule', (string) $schedule->id))->toBe([
            '6400' => ['debit' => '200.00', 'credit' => '0.00'],
            '1650' => ['debit' => '0.00', 'credit' => '200.00'],
        ])
        ->and((string) JournalEntry::where('source_document_id', $schedule->id)->value('posting_date'))->toStartWith(now()->endOfMonth()->toDateString());

    $taxRun = $this->engine->executeDepreciationRun(new RunDepreciationDTO((string) $this->tenant->id, (string) $this->tenant->id, $period, ledgerType: 'fiscal'), (string) $this->user->id);
    $taxBook = AssetBook::where('asset_id', $asset->id)->where('ledger_type', 'tax')->firstOrFail();

    // 15% fiscal coefficient on 12,000 = 1,800 a year, 150 a month; the civil book stays at 200 a month.
    expect($taxRun->total_depreciation_amount)->toBe('150.00')
        ->and($taxRun->status)->toBe('calculated')
        ->and($taxBook->net_book_value)->toBe('11850.00')
        ->and($asset->fresh()->carrying_amount)->toBe('11800.00')
        ->and(JournalEntry::where('source_document_type', 'eam_depreciation_schedule')->count())->toBe(1);
});

test('a total disposal posts the derecognition with gain, a partial one only its share', function (): void {
    $asset = ledgerTestAsset();
    $this->engine->executeDepreciationRun(new RunDepreciationDTO((string) $this->tenant->id, (string) $this->tenant->id, now()->format('Y-m')), (string) $this->user->id);

    $partial = $this->lifecycle->disposeAsset(new DisposeAssetDTO(assetId: (string) $asset->id, disposalType: 'sale', proceedsAmount: '3000', disposedPercentage: '25'), (string) $this->user->id);
    expect(ledgerLines('eam_asset_disposal', (string) $partial->id))->toBe([
        '1650' => ['debit' => '50.00', 'credit' => '0.00'],
        '1100' => ['debit' => '3000.00', 'credit' => '0.00'],
        '1600' => ['debit' => '0.00', 'credit' => '3000.00'],
        '7500' => ['debit' => '0.00', 'credit' => '50.00'],
    ]);
    expect($asset->fresh())->carrying_amount->toBe('8850.00')->accumulated_depreciation->toBe('150.00')->capitalized_cost->toBe('9000.00')
        ->and(AssetBook::where('asset_id', $asset->id)->where('ledger_type', 'tax')->value('cost'))->toBe('9000.00');

    $total = $this->lifecycle->disposeAsset(new DisposeAssetDTO(assetId: (string) $asset->id, disposalType: 'sale', proceedsAmount: '10000', disposalCosts: '150'), (string) $this->user->id);
    expect($total->gain_loss_amount)->toBe('1000.00')
        ->and(ledgerLines('eam_asset_disposal', (string) $total->id))->toBe([
            '1650' => ['debit' => '150.00', 'credit' => '0.00'],
            '1100' => ['debit' => '9850.00', 'credit' => '0.00'],
            '1600' => ['debit' => '0.00', 'credit' => '9000.00'],
            '7500' => ['debit' => '0.00', 'credit' => '1000.00'],
        ])
        ->and($asset->fresh()->status)->toBe('sold');
});

test('scrapping posts the remaining carrying amount as a loss on the configured loss account', function (): void {
    $this->category->update(['gl_disposal_loss_code' => '7510']);
    $asset = ledgerTestAsset('5000.00');

    $disposal = $this->lifecycle->disposeAsset(new DisposeAssetDTO(assetId: (string) $asset->id, disposalType: 'scrap'), (string) $this->user->id);

    expect(ledgerLines('eam_asset_disposal', (string) $disposal->id))->toBe([
        '7510' => ['debit' => '5000.00', 'credit' => '0.00'],
        '1600' => ['debit' => '0.00', 'credit' => '5000.00'],
    ]);
});

test('posting into a missing fiscal period is refused as a validation error', function (): void {
    expect(fn () => ledgerTestAsset('1000.00', (now()->year - 3).'-06-30'))
        ->toThrow(AssetOperationException::class, 'No fiscal period');
    expect(FixedAsset::where('status', 'capitalized')->count())->toBe(0);
});

test('the calculator applies the pro-rata conventions, declining balance switch and units of production', function (): void {
    $calculator = new DepreciationCalculator;
    $book = new AssetBook([
        'depreciation_method' => 'straight_line', 'useful_life_months' => 60, 'pro_rata_convention' => 'monthly',
        'cost' => '12000.00', 'salvage_value' => '0.00', 'depreciable_base' => '12000.00', 'accumulated_depreciation' => '0.00',
        'net_book_value' => '12000.00', 'total_expected_units' => '0.00', 'consumed_units' => '0.00',
    ]);
    $book->depreciation_start_date = CarbonImmutable::parse('2026-04-15');
    $year = DepreciationPeriod::fromCode('2026', 'yearly');

    expect($calculator->periodCharge($book, $year))->toBe('1800.00')
        ->and($calculator->periodCharge($book, DepreciationPeriod::fromCode('2026-03', 'monthly')))->toBe('0.00')
        ->and($calculator->periodCharge($book, DepreciationPeriod::fromCode('2026-Q2', 'quarterly')))->toBe('600.00');

    $book->pro_rata_convention = 'half_year';
    expect($calculator->periodCharge($book, $year))->toBe('1200.00')
        ->and($calculator->periodCharge($book, DepreciationPeriod::fromCode('2026-04', 'monthly')))->toBe('133.33')
        ->and($calculator->periodCharge($book, DepreciationPeriod::fromCode('2027', 'yearly')))->toBe('2400.00');

    $book->pro_rata_convention = 'full';
    expect($calculator->periodCharge($book, $year))->toBe('2400.00');

    $book->pro_rata_convention = 'daily';
    expect($calculator->periodCharge($book, DepreciationPeriod::fromCode('2026-04', 'monthly')))->toBe('106.67');

    $book->depreciation_method = 'double_declining_balance';
    $book->pro_rata_convention = 'full';
    expect($calculator->periodCharge($book, $year))->toBe('4800.00');
    $book->net_book_value = '1000.00';
    $book->useful_life_months = 60;
    expect($calculator->periodCharge($book, DepreciationPeriod::fromCode('2031', 'yearly')))->toBe('1000.00');

    $book->depreciation_method = 'units_of_production';
    $book->net_book_value = '12000.00';
    $book->total_expected_units = '10000.00';
    expect($calculator->periodCharge($book, $year, '250'))->toBe('300.00');

    $book->depreciation_method = 'straight_line';
    $book->pro_rata_convention = 'half_year';
    $projection = $calculator->project($book, $year, 20);
    expect($projection)->toHaveCount(6)
        ->and($projection[0]['depreciation_amount'])->toBe('1200.00')
        ->and($projection[5]['depreciation_amount'])->toBe('1200.00')
        ->and(end($projection)['closing_book_value'])->toBe('0.00');
});

test('depreciation is charged once per period, in order, and refuses mixed period types', function (): void {
    $asset = ledgerTestAsset();
    $month = now()->format('Y-m');

    $this->engine->calculateSingleAssetDepreciation($asset, $month, 'monthly', 'statutory', 0, null, false);
    expect(fn () => $this->engine->calculateSingleAssetDepreciation($asset, now()->subMonth()->format('Y-m'), 'monthly', 'statutory', 0, null, false))
        ->toThrow(AssetOperationException::class, 'already depreciated');

    $this->engine->executeDepreciationRun(new RunDepreciationDTO((string) $this->tenant->id, (string) $this->tenant->id, now()->addMonth()->format('Y-m'), autoPostToGl: false), null);
    expect(fn () => $this->engine->executeDepreciationRun(new RunDepreciationDTO((string) $this->tenant->id, (string) $this->tenant->id, now()->format('Y'), 'yearly', autoPostToGl: false), null))
        ->toThrow(AssetOperationException::class);
});

test('warranty and insurance alerts are sent once per expiry date to managers and the custodian', function (): void {
    Notification::fake();
    $owner = User::create(['name' => 'Owner', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $owner->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    $custodian = User::create(['name' => 'Custodian', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $custodian->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    $asset = ledgerTestAsset();
    $asset->update(['warranty_expiry_date' => now()->addDays(10)->toDateString(), 'insurance_expiry_date' => now()->addDays(200)->toDateString(), 'custodian_user_id' => $custodian->id]);

    $service = app(AssetCoverageAlertService::class);
    expect($service->notifyExpiring((string) $this->tenant->id, 30))->toBe(1)
        ->and($service->notifyExpiring((string) $this->tenant->id, 30))->toBe(0);

    Notification::assertSentTo($owner, AssetCoverageExpiringNotification::class, fn (AssetCoverageExpiringNotification $notification): bool => $notification->coverage === 'warranty');
    Notification::assertSentTo($custodian, AssetCoverageExpiringNotification::class);
    expect($asset->fresh()->warranty_alerted_at)->not->toBeNull()->and($asset->fresh()->insurance_alerted_at)->toBeNull();
});
