<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\JournalNumberSequence;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Libro Giornale Srl', 'legal_name' => 'Libro Giornale Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->year2026 = app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2026);
    $this->year2027 = app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2027);

    $account = fn (string $code, string $name, string $class): ChartOfAccount => ChartOfAccount::create([
        'tenant_id' => $this->tenant->id, 'account_code' => $code, 'name' => $name, 'account_class' => $class, 'account_type' => $class,
        'normal_balance' => $class === 'asset' ? 'debit' : 'credit', 'is_active' => true,
    ]);
    $this->cash = $account('1000', 'Cassa', 'asset');
    $this->revenue = $account('4000', 'Ricavi', 'revenue');

    $this->post = function (string $date, float $amount, string $status = 'posted'): JournalEntry {
        return app(DoubleEntryPostingServiceInterface::class)->createAndPost([
            'tenant_id' => (string) $this->tenant->id,
            'fiscal_period_id' => app(FiscalPeriodResolver::class)->forDate((string) $this->tenant->id, $date)->id,
            'posting_date' => $date,
            'entry_number' => 'RND-'.Str::upper(Str::random(6)),
            'description' => "Incasso {$date}",
            'status' => $status,
            'lines' => [
                ['account_id' => $this->cash->id, 'debit_amount' => $amount, 'credit_amount' => 0.0],
                ['account_id' => $this->revenue->id, 'debit_amount' => 0.0, 'credit_amount' => $amount],
            ],
        ]);
    };
});

test('posted entries take a progressive number per tenant and fiscal year, drafts only when posted', function (): void {
    $first = ($this->post)('2026-03-10', 100.0);
    $second = ($this->post)('2026-02-01', 50.0);
    $draft = ($this->post)('2026-04-01', 10.0, 'draft');
    $nextYear = ($this->post)('2027-01-05', 70.0);

    expect($first->journal_number)->toBe(1)
        ->and($second->journal_number)->toBe(2)
        ->and($draft->journal_number)->toBeNull()
        ->and($nextYear->journal_number)->toBe(1)
        ->and((string) $first->fiscal_year_id)->toBe((string) $this->year2026->id)
        ->and((string) $nextYear->fiscal_year_id)->toBe((string) $this->year2027->id);

    $draft->update(['status' => 'posted', 'posted_at' => now()]);
    expect($draft->fresh()->journal_number)->toBe(3);

    // Another tenant starts from one.
    $other = Tenant::create(['name' => 'Altra Srl', 'legal_name' => 'Altra Srl']);
    $otherYear = app(OpenFiscalYearAction::class)->execute((string) $other->id, 2026);
    $otherPeriod = FiscalPeriod::withoutGlobalScopes()->where('fiscal_year_id', $otherYear->id)->orderBy('period_number')->first();
    expect(app(JournalNumberSequence::class)->next((string) $other->id, (string) $otherYear->id))->toBe(1)
        ->and($otherPeriod)->not->toBeNull();
});

test('the libro giornale export lists the posted lines by date and progressive number, tenant scoped', function (): void {
    ($this->post)('2026-03-10', 100.0);
    ($this->post)('2026-02-01', 50.5);
    ($this->post)('2026-04-01', 10.0, 'draft');
    ($this->post)('2027-01-05', 70.0);

    $user = User::create(['name' => 'Contabile', 'email' => Str::random(10).'@example.it', 'password' => bcrypt('secret123')]);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'accountant']);
    Sanctum::actingAs($user);

    $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
        ->get("/api/v1/general-ledger/journal-book/export?fiscal_year_id={$this->year2026->id}")
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($response->headers->get('content-disposition'))->toContain('libro-giornale-');

    $lines = explode("\r\n", rtrim(substr($response->getContent(), 3), "\r\n"));
    expect(str_getcsv($lines[0], ';', '"', '')[0])->toBe('journal_number')->and($lines)->toHaveCount(5);

    $rows = array_map(fn (string $line): array => str_getcsv($line, ';', '"', ''), array_slice($lines, 1));
    expect(array_column($rows, 0))->toBe(['2', '2', '1', '1'])
        ->and(array_column($rows, 1))->toBe(['2026-02-01', '2026-02-01', '2026-03-10', '2026-03-10'])
        ->and($rows[0][9])->toBe('1000')
        ->and($rows[0][12])->toBe('50,50')
        ->and($rows[1][13])->toBe('50,50');

    $foreign = Tenant::create(['name' => 'Estranea Srl', 'legal_name' => 'Estranea Srl']);
    $foreignYear = app(OpenFiscalYearAction::class)->execute((string) $foreign->id, 2026);
    $this->get("/api/v1/general-ledger/journal-book/export?fiscal_year_id={$foreignYear->id}")->assertNotFound();
    $this->getJson('/api/v1/general-ledger/journal-book/export')->assertUnprocessable();
});

test('a member without ledger access cannot export the libro giornale', function (): void {
    $user = User::create(['name' => 'Magazziniere', 'email' => Str::random(10).'@example.it', 'password' => bcrypt('secret123')]);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'warehouse']);
    Sanctum::actingAs($user);

    $this->withHeader('X-Tenant-Id', $this->tenant->id)
        ->getJson("/api/v1/general-ledger/journal-book/export?fiscal_year_id={$this->year2026->id}")
        ->assertForbidden();
});
