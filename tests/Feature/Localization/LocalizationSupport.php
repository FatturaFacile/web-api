<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Two companies (a Swiss one and an Italian one) and an owner of the Swiss one signed in.
 */
function locSetUp(object $test): void
{
    $test->tenant = Tenant::create(['name' => 'Alfa SA', 'legal_name' => 'Alfa SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $test->otherTenant = Tenant::create(['name' => 'Beta Srl', 'legal_name' => 'Beta Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $test->owner = locActor($test, 'owner');
}

function locActor(object $test, string $role, ?Tenant $tenant = null, string $locale = 'en'): User
{
    $tenant ??= $test->tenant;
    $user = User::create(['name' => ucfirst($role), 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => $locale]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);
    locActAs($test, $user, $tenant);

    return $user;
}

function locActAs(object $test, User $user, ?Tenant $tenant = null): void
{
    Sanctum::actingAs($user);
    $test->withHeader('X-Tenant-Id', ($tenant ?? $test->tenant)->id);
    app(TenantContext::class)->setTenant($tenant ?? $test->tenant);
}

/**
 * @template TReturn
 *
 * @param  callable(): TReturn  $callback
 * @return TReturn
 */
function locInTenant(Tenant $tenant, callable $callback): mixed
{
    return app(TenantContext::class)->runWithTenant($tenant, $callback);
}

/**
 * A text of the system dictionary (no tenant).
 */
function locSystemText(string $locale, string $key, string $value, bool $locked = false): void
{
    locText(null, $locale, $key, $value, $locked);
}

function locText(?string $tenantId, string $locale, string $key, string $value, bool $locked = false): void
{
    DB::table('translations')->insert([
        'tenant_id' => $tenantId, 'locale' => $locale, 'group' => explode('.', $key, 2)[0], 'key' => $key, 'value' => $value,
        'is_locked' => $locked, 'is_deprecated' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * The text a tenant override row holds, or null.
 */
function locStored(?string $tenantId, string $locale, string $key): ?string
{
    $value = DB::table('translations')->where('tenant_id', $tenantId)->where('locale', $locale)->where('key', $key)->value('value');

    return $value === null ? null : (string) $value;
}

/**
 * A small system dictionary in English and German.
 */
function locSeedDictionary(): void
{
    locSystemText('en', 'invoicing.title', 'Invoice');
    locSystemText('en', 'invoicing.subtitle', 'Sales invoices');
    locSystemText('en', 'crm.title', 'Contacts');
    locSystemText('de', 'invoicing.title', 'Rechnung');
    locSystemText('de', 'crm.title', 'Kontakte');
    locSystemText('en', 'statutory.ch.vat_suffix', 'VAT', true);
    locSystemText('de', 'statutory.ch.vat_suffix', 'MWST', true);
}

/**
 * A csv upload: the first row is the header.
 *
 * @param  list<list<string>>  $rows
 */
function locCsv(array $rows, string $name = 'translations.csv'): UploadedFile
{
    $content = implode("\n", array_map(static fn (array $row): string => implode(',', array_map(static fn (string $cell): string => '"'.str_replace('"', '""', $cell).'"', $row)), $rows))."\n";

    return UploadedFile::fake()->createWithContent($name, $content);
}

/**
 * An xlsx upload written with the same library the importer reads with.
 *
 * @param  list<list<string>>  $rows
 */
function locXlsx(array $rows, string $name = 'translations.xlsx'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'loc-xlsx-');
    $writer = new Writer;
    $writer->openToFile($path);

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function locContact(Tenant $tenant, ?string $documentLanguage = null): Contact
{
    return locInTenant($tenant, fn (): Contact => Contact::create([
        'tenant_id' => $tenant->id, 'type' => 'client', 'company_name' => 'Cliente '.Str::random(4), 'country' => 'CH', 'document_language' => $documentLanguage,
    ]));
}
