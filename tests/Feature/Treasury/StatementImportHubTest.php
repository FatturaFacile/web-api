<?php

declare(strict_types=1);

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

require_once __DIR__.'/TreasurySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    treasurySetUp($this);
});

/**
 * @return array<string, mixed>
 */
function hubCsvOptions(array $overrides = []): array
{
    return array_merge([
        'format' => 'csv',
        'auto_reconcile' => '0',
        'csv' => [
            'account_iban' => 'IT60X0542811101000000123456', 'currency' => 'EUR', 'delimiter' => ';', 'has_header' => '1',
            'date_format' => 'd/m/Y', 'decimal_separator' => ',', 'thousands_separator' => '.',
            'columns' => ['booking_date' => 'Data', 'amount' => 'Importo', 'description' => 'Causale', 'counterpart_name' => 'Controparte', 'entry_reference' => 'Id'],
        ],
    ], $overrides);
}

function hubCsv(string $name = 'extract.csv', ?string $content = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content ?? "Id;Data;Importo;Causale;Controparte\nB1;01/03/2026;1.234,50;Saldo fattura 12;Beta SRL\nB2;02/03/2026;-95,00;Commissioni;Banca\n");
}

test('a CSV statement is imported with an inline mapping', function (): void {
    $data = $this->post('/api/v1/treasury/statement-imports', hubCsvOptions(['file' => hubCsv()]), ['Accept' => 'application/json'])
        ->assertCreated()->json('data');

    expect($data['format'])->toBe('csv')
        ->and($data['entries'])->toBe(2)
        ->and($data['stored'])->toBe(2)
        ->and($data['duplicates'])->toBe(0)
        ->and($data['statement_date'])->toBe('2026-03-02')
        ->and((float) BankStatementLine::query()->where('bank_statement_id', $data['statement_id'])->where('direction', 'debit')->value('amount'))->toBe(95.0);
});

test('the same file is refused and an overlapping file stores only the new movements', function (): void {
    $this->post('/api/v1/treasury/statement-imports', hubCsvOptions(['file' => hubCsv()]), ['Accept' => 'application/json'])->assertCreated();
    $this->post('/api/v1/treasury/statement-imports', hubCsvOptions(['file' => hubCsv()]), ['Accept' => 'application/json'])->assertUnprocessable();

    $overlap = "Id;Data;Importo;Causale;Controparte\nB2;02/03/2026;-95,00;Commissioni;Banca\nB3;03/03/2026;10,00;Altro;Gamma\n";
    $second = $this->post('/api/v1/treasury/statement-imports', hubCsvOptions(['file' => hubCsv('overlap.csv', $overlap)]), ['Accept' => 'application/json'])->assertCreated()->json('data');

    expect($second['duplicates'])->toBe(1)->and($second['stored'])->toBe(1)
        ->and(BankStatementLine::query()->count())->toBe(3);
});

test('a broken mapping or an unreadable row is answered with the reason and stores nothing', function (): void {
    $missingColumn = hubCsvOptions(['file' => hubCsv()]);
    $missingColumn['csv']['columns']['description'] = 'Non esiste';
    $this->post('/api/v1/treasury/statement-imports', $missingColumn, ['Accept' => 'application/json'])->assertUnprocessable();

    $badDate = hubCsvOptions(['file' => hubCsv('bad.csv', "Id;Data;Importo;Causale;Controparte\nB1;31/02/2026;1,00;X;Y\n")]);
    $this->post('/api/v1/treasury/statement-imports', $badDate, ['Accept' => 'application/json'])->assertUnprocessable();

    expect(BankStatement::query()->count())->toBe(0);
});

test('a CSV import needs a mapping or a saved profile', function (): void {
    $this->post('/api/v1/treasury/statement-imports', ['file' => hubCsv(), 'format' => 'csv'], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors(['csv.account_iban', 'csv.columns']);
});

test('a saved CSV profile is used to import later files of the same bank', function (): void {
    $profile = $this->postJson('/api/v1/treasury/csv-profiles', [
        'name' => 'Intesa', 'account_iban' => 'IT60X0542811101000000123456', 'currency' => 'EUR', 'delimiter' => ';', 'has_header' => true,
        'date_format' => 'd/m/Y', 'decimal_separator' => ',', 'thousands_separator' => '.',
        'columns' => ['booking_date' => 'Data', 'amount' => 'Importo', 'description' => 'Causale', 'entry_reference' => 'Id'],
    ])->assertCreated()->json('data');

    $this->postJson('/api/v1/treasury/csv-profiles', ['name' => 'Intesa', 'currency' => 'EUR', 'delimiter' => ';', 'has_header' => true, 'date_format' => 'd/m/Y', 'decimal_separator' => ',', 'columns' => ['booking_date' => 'Data']])
        ->assertUnprocessable()->assertJsonValidationErrors(['name']);

    $this->getJson('/api/v1/treasury/csv-profiles')->assertOk()->assertJsonCount(1, 'data');

    $this->post('/api/v1/treasury/statement-imports', ['file' => hubCsv(), 'format' => 'csv', 'csv_profile_id' => $profile['id'], 'auto_reconcile' => '0'], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.stored', 2);

    $this->deleteJson("/api/v1/treasury/csv-profiles/{$profile['id']}")->assertOk();
    $this->getJson('/api/v1/treasury/csv-profiles')->assertOk()->assertJsonCount(0, 'data');
});

test('a profile of another company cannot be used', function (): void {
    $profile = $this->postJson('/api/v1/treasury/csv-profiles', [
        'name' => 'Intesa', 'account_iban' => 'IT60X0542811101000000123456', 'currency' => 'EUR', 'delimiter' => ';', 'has_header' => true,
        'date_format' => 'd/m/Y', 'decimal_separator' => ',', 'columns' => ['booking_date' => 'Data', 'amount' => 'Importo'],
    ])->assertCreated()->json('data');
    treasuryActor($this, 'accountant', $this->otherTenant);

    $this->post('/api/v1/treasury/statement-imports', ['file' => hubCsv(), 'format' => 'csv', 'csv_profile_id' => $profile['id']], ['Accept' => 'application/json'])->assertNotFound();
});

test('unsupported extensions are refused', function (): void {
    $this->post('/api/v1/treasury/statement-imports', ['file' => UploadedFile::fake()->create('statement.exe', 5), 'format' => 'auto'], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors(['file']);
});
