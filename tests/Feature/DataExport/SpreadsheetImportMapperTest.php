<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $user = User::create(['name' => 'owner', 'email' => Str::uuid().'@example.com', 'password' => Str::random(24)]);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
});

afterEach(fn () => app(TenantContext::class)->clear());

function importCsv(): UploadedFile
{
    $lines = [
        'Company;E-mail;Phone;VAT number;Kind',
        'Rossi Srl;info@rossi.example;0212345;IT01234567890;supplier',
        'Bianchi SpA;info@bianchi.example;;;',
        ';not-an-email;;;',
        'Rossi Srl bis;info@rossi.example;;;',
    ];

    return UploadedFile::fake()->createWithContent('contacts.csv', implode("\n", $lines));
}

test('the preview suggests the mapping from the headers', function (): void {
    $data = $this->postJson('/api/v1/exports/imports/preview', ['dataset' => 'contacts', 'file' => importCsv()])->assertOk()->json('data');

    expect($data['headers'])->toBe(['Company', 'E-mail', 'Phone', 'VAT number', 'Kind'])
        ->and($data['row_count'])->toBe(4)
        ->and($data['sample'][0]['Company'])->toBe('Rossi Srl')
        ->and($data['suggested_mapping'])->toBe(['email' => 'E-mail', 'phone' => 'Phone', 'vat_number' => 'VAT number'])
        ->and($data['fields'])->toContain('company_name', 'email');
});

test('a dry run reports the outcome without writing, the import creates the valid rows only', function (): void {
    $mapping = ['company_name' => 'Company', 'email' => 'E-mail', 'phone' => 'Phone', 'vat_number' => 'VAT number', 'type' => 'Kind'];

    $dry = $this->postJson('/api/v1/exports/imports', ['dataset' => 'contacts', 'file' => importCsv(), 'mapping' => $mapping, 'dry_run' => true])->assertOk()->json('data');
    expect($dry['created'])->toBe(2)->and($dry['failed'])->toBe(1)->and($dry['skipped'])->toBe(1)->and(Contact::query()->count())->toBe(0);

    $done = $this->postJson('/api/v1/exports/imports', ['dataset' => 'contacts', 'file' => importCsv(), 'mapping' => $mapping])->assertOk()->json('data');
    expect($done['created'])->toBe(2)
        ->and($done['errors'][0]['line'])->toBe(4)
        ->and($done['duplicates'])->toBe([5])
        ->and(Contact::query()->count())->toBe(2)
        ->and(Contact::query()->where('vat_number', 'IT01234567890')->firstOrFail()->type)->toBe('supplier')
        ->and(Contact::query()->where('company_name', 'Bianchi SpA')->firstOrFail()->type)->toBe('customer');

    $again = $this->postJson('/api/v1/exports/imports', ['dataset' => 'contacts', 'file' => importCsv(), 'mapping' => $mapping])->assertOk()->json('data');
    expect($again['created'])->toBe(0)->and($again['skipped'])->toBe(3);
});

test('an xlsx file is read like a csv', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Name', 'Mail']));
    $writer->addRow(Row::fromValues(['Verdi Srl', 'info@verdi.example']));
    $writer->close();

    $file = new UploadedFile($path, 'contacts.xlsx', null, null, true);
    $this->postJson('/api/v1/exports/imports', ['dataset' => 'contacts', 'file' => $file, 'mapping' => ['company_name' => 'Name', 'email' => 'Mail']])
        ->assertOk()->assertJsonPath('data.created', 1);

    expect(Contact::query()->where('company_name', 'Verdi Srl')->exists())->toBeTrue();
    unlink($path);
});

test('the import needs a known dataset, a mapping and a spreadsheet file', function (): void {
    $this->postJson('/api/v1/exports/imports', ['dataset' => 'unknown', 'file' => importCsv(), 'mapping' => ['email' => 'E-mail']])->assertUnprocessable()->assertJsonValidationErrors('dataset');
    $this->postJson('/api/v1/exports/imports', ['dataset' => 'contacts', 'file' => importCsv()])->assertUnprocessable()->assertJsonValidationErrors('mapping');
    $this->postJson('/api/v1/exports/imports/preview', ['dataset' => 'contacts', 'file' => UploadedFile::fake()->create('x.pdf', 10)])->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->postJson('/api/v1/exports/imports/preview', ['dataset' => 'contacts', 'file' => UploadedFile::fake()->createWithContent('empty.csv', '')])->assertUnprocessable();
});
