<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Modules\Platform\Localization\Domain\Events\TranslationsChanged;
use OpenSpout\Reader\XLSX\Reader;

require_once __DIR__.'/LocalizationSupport.php';

beforeEach(function (): void {
    locSetUp($this);
    locSeedDictionary();
});

const IMPORT_URL = '/api/v1/settings/translations/import';

/**
 * @return array<string, array<string, mixed>> the rows of an import response by "row:locale"
 */
function importRows(object $response): array
{
    return collect($response->json('data.rows'))->keyBy(fn (array $row): string => $row['row'].':'.$row['locale'])->all();
}

it('previews the diff of a csv without writing anything (dry run)', function (): void {
    $file = locCsv([
        ['key', 'de-CH'],
        ['invoicing.title', 'Faktura'],
        ['crm.title', 'Kontakte'],
        ['invoicing.subtitle', 'Verkaufsrechnungen'],
        ['does.not.exist', 'x'],
        ['statutory.ch.vat_suffix', 'Steuer'],
        ['bad key', 'x'],
    ]);

    $response = $this->post(IMPORT_URL.'?dry_run=1', ['file' => $file], ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('data.dry_run', true)
        ->assertJsonPath('data.has_errors', true)
        ->assertJsonPath('data.summary.created', 2)
        ->assertJsonPath('data.summary.unchanged', 1)
        ->assertJsonPath('data.summary.rejected', 2)
        ->assertJsonPath('data.summary.blocked', 1);

    $rows = importRows($response);

    expect($rows['2:de-CH'])->toMatchArray(['key' => 'invoicing.title', 'action' => 'create', 'old_value' => 'Rechnung', 'new_value' => 'Faktura'])
        ->and($rows['3:de-CH']['action'])->toBe('unchanged')
        ->and($rows['4:de-CH']['action'])->toBe('create')
        ->and($rows['5:de-CH']['action'])->toBe('rejected')
        ->and($rows['5:de-CH']['errors'])->not->toBeEmpty()
        ->and($rows['6:de-CH']['action'])->toBe('blocked')
        ->and($rows['7:de-CH']['action'])->toBe('rejected');

    expect(locStored($this->tenant->id, 'de-CH', 'invoicing.title'))->toBeNull();
});

it('commits a valid sheet as tenant overrides and refreshes the dictionary', function (): void {
    Event::fake([TranslationsChanged::class]);

    $file = locCsv([['key', 'de-CH', 'en'], ['invoicing.title', 'Faktura', 'Bill'], ['crm.title', 'Adressen', '']]);

    $this->post(IMPORT_URL, ['file' => $file], ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('data.dry_run', false)
        ->assertJsonPath('data.summary.created', 3)
        ->assertJsonPath('data.has_errors', false);

    expect(locStored($this->tenant->id, 'de-CH', 'invoicing.title'))->toBe('Faktura')
        ->and(locStored($this->tenant->id, 'de-CH', 'crm.title'))->toBe('Adressen')
        ->and(locStored($this->tenant->id, 'en', 'invoicing.title'))->toBe('Bill')
        ->and(locStored(null, 'de', 'invoicing.title'))->toBe('Rechnung');

    Event::assertDispatched(TranslationsChanged::class, fn (TranslationsChanged $event): bool => $event->tenantId === $this->tenant->id && $event->locale === 'de-CH');
    Event::assertDispatched(TranslationsChanged::class, fn (TranslationsChanged $event): bool => $event->locale === 'en');
});

it('updates an existing override and skips a text equal to the effective one', function (): void {
    locText($this->tenant->id, 'de-CH', 'invoicing.title', 'Faktura');

    $dryRun = $this->post(IMPORT_URL.'?dry_run=1', ['file' => locCsv([['key', 'de-CH'], ['invoicing.title', 'Faktura 2'], ['crm.title', 'Kontakte']])], ['Accept' => 'application/json'])->assertOk();

    expect($dryRun->json('data.summary'))->toMatchArray(['created' => 0, 'updated' => 1, 'unchanged' => 1]);

    $this->post(IMPORT_URL, ['file' => locCsv([['key', 'de-CH'], ['invoicing.title', 'Faktura 2'], ['crm.title', 'Kontakte']])], ['Accept' => 'application/json'])->assertOk();

    expect(locStored($this->tenant->id, 'de-CH', 'invoicing.title'))->toBe('Faktura 2')
        ->and(locStored($this->tenant->id, 'de-CH', 'crm.title'))->toBeNull();
});

it('reads an xlsx sheet and a csv with semicolons', function (): void {
    $this->post(IMPORT_URL, ['file' => locXlsx([['key', 'de-CH'], ['invoicing.title', 'Faktura']])], ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('data.summary.created', 1);

    $semicolons = UploadedFile::fake()->createWithContent('t.csv', "key;fr-CH\ncrm.title;Contacts clients\n");
    $this->post(IMPORT_URL, ['file' => $semicolons], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.summary.created', 1);

    expect(locStored($this->tenant->id, 'de-CH', 'invoicing.title'))->toBe('Faktura')
        ->and(locStored($this->tenant->id, 'fr-CH', 'crm.title'))->toBe('Contacts clients');
});

it('rejects the whole commit with 422 and row errors, writing nothing', function (): void {
    $file = locCsv([['key', 'de-CH'], ['invoicing.title', 'Faktura'], ['does.not.exist', 'x']]);

    $response = $this->post(IMPORT_URL, ['file' => $file], ['Accept' => 'application/json'])->assertStatus(422)
        ->assertJsonPath('data.has_errors', true);

    expect(collect($response->json('data.rows'))->firstWhere('key', 'does.not.exist')['errors'])->not->toBeEmpty()
        ->and(locStored($this->tenant->id, 'de-CH', 'invoicing.title'))->toBeNull();
});

it('answers 403 and writes nothing when a committed sheet touches a locked key', function (): void {
    $file = locCsv([['key', 'de-CH'], ['invoicing.title', 'Faktura'], ['statutory.ch.vat_suffix', 'Steuer']]);

    $this->post(IMPORT_URL, ['file' => $file], ['Accept' => 'application/json'])->assertForbidden()
        ->assertJsonPath('key', 'statutory.ch.vat_suffix');

    expect(locStored($this->tenant->id, 'de-CH', 'invoicing.title'))->toBeNull()
        ->and(locStored($this->tenant->id, 'de-CH', 'statutory.ch.vat_suffix'))->toBeNull();
});

it('reports header problems of the sheet', function (): void {
    $response = $this->post(IMPORT_URL.'?dry_run=1', ['file' => locCsv([['name', 'es-ES'], ['a.b', 'x']])], ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('data.has_errors', true);

    expect($response->json('data.header_errors'))->toHaveCount(2)->and($response->json('data.rows'))->toBe([]);
});

it('validates the uploaded file', function (): void {
    $this->post(IMPORT_URL, [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
    $this->post(IMPORT_URL, ['file' => UploadedFile::fake()->create('t.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
    $this->post(IMPORT_URL, ['file' => UploadedFile::fake()->create('big.csv', 6000, 'text/csv')], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
});

it('forbids a member who is not owner or administrator to import', function (): void {
    locActor($this, 'accountant');

    $this->post(IMPORT_URL, ['file' => locCsv([['key', 'de-CH'], ['invoicing.title', 'Faktura']])], ['Accept' => 'application/json'])->assertForbidden();
});

it('exports the effective texts of the requested locales as an xlsx download', function (): void {
    locText($this->tenant->id, 'de-CH', 'invoicing.title', 'Faktura');

    $response = $this->get('/api/v1/settings/translations/export?locales[]=it-CH&locales[]=de-CH', ['Accept' => 'application/json'])->assertOk();

    expect((string) $response->headers->get('Content-Type'))->toContain('spreadsheetml')
        ->and((string) $response->headers->get('Content-Disposition'))->toContain('translations.xlsx');

    $reader = new Reader;
    $reader->open($response->baseResponse->getFile()->getPathname());
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map('strval', $row->toArray());
        }

        break;
    }

    $reader->close();
    $byKey = collect(array_slice($rows, 1))->keyBy(0);

    expect($rows[0])->toBe(['key', 'it-CH', 'de-CH'])
        ->and($byKey['invoicing.title'][2])->toBe('Faktura')
        ->and($byKey['crm.title'][2])->toBe('Kontakte')
        ->and($byKey['crm.title'][1])->toBe('')
        ->and($byKey)->toHaveCount(4);
});

it('exports a csv and round-trips it through the import', function (): void {
    $response = $this->get('/api/v1/settings/translations/export?locales[]=de-CH&format=csv', ['Accept' => 'application/json'])->assertOk();
    $csv = (string) file_get_contents($response->baseResponse->getFile()->getPathname());

    expect($csv)->toContain('key,de-CH')->toContain('invoicing.title,Rechnung');

    $upload = UploadedFile::fake()->createWithContent('export.csv', $csv);

    $this->post(IMPORT_URL.'?dry_run=1', ['file' => $upload], ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('data.summary.created', 0)
        ->assertJsonPath('data.summary.unchanged', 3);
});

it('validates the export request', function (): void {
    $this->getJson('/api/v1/settings/translations/export')->assertStatus(422)->assertJsonValidationErrors('locales');
    $this->getJson('/api/v1/settings/translations/export?locales[]=es-ES')->assertStatus(422)->assertJsonValidationErrors('locales.0');
});
