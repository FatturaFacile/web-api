<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Database\Seeders\CustomsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Customs\Models\CustomsCountry;
use Modules\Customs\Models\CustomsDocumentType;
use Modules\Customs\Models\CustomsPackagingType;
use Modules\Customs\Models\CustomsStatisticalKey;
use Modules\Customs\Models\CustomsTariffNumber;
use Modules\Customs\Services\CustomsMasterDataImporter;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = customsTenant();
    $this->user = customsMember($this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function customsGenericPackaging(string $code = 'CT'): CustomsPackagingType
{
    app(TenantContext::class)->clear();

    return CustomsPackagingType::createGlobal(['code' => $code, 'name_it' => 'Cartone generico', 'source' => 'bazg_edec']);
}

test('the seeder loads the official domains and countries as generic rows', function (): void {
    app(CustomsReferenceSeeder::class)->run(app(CustomsMasterDataImporter::class));

    expect(CustomsCountry::withoutGlobalScopes()->whereNull('tenant_id')->where('code', 'CH')->exists())->toBeTrue()
        ->and(CustomsPackagingType::withoutGlobalScopes()->whereNull('tenant_id')->count())->toBeGreaterThan(10)
        ->and(CustomsDocumentType::withoutGlobalScopes()->whereNull('tenant_id')->where('code', 'C641')->count())->toBe(1);

    app(CustomsReferenceSeeder::class)->run(app(CustomsMasterDataImporter::class));

    expect(CustomsDocumentType::withoutGlobalScopes()->where('code', 'C641')->count())->toBe(1);
});

test('the tariff file gives tariff numbers and their statistical keys', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'tariff');
    file_put_contents($path, '<?xml version="1.0" encoding="ISO-8859-1"?><tariffMasterData><commodityCodes>'
        .'<commodityCode value="0101.2110" validFrom="2012-01-01" validTo="2999-12-31"><rate value="120"/><statisticalCode value="911" validFrom="2019-01-01" validTo="2999-12-31"/><statisticalCode value="999" validFrom="2019-01-01" validTo="2999-12-31"/></commodityCode>'
        .'</commodityCodes></tariffMasterData>');

    expect(app(CustomsMasterDataImporter::class)->importTariff($path))->toBe(['tariff_numbers' => 1, 'statistical_keys' => 2])
        ->and(CustomsTariffNumber::withoutGlobalScopes()->where('code', '0101.2110')->exists())->toBeTrue()
        ->and(CustomsStatisticalKey::withoutGlobalScopes()->where('tariff_code', '0101.2110')->pluck('code')->sort()->values()->all())->toBe(['911', '999']);
});

test('a company sees generic rows and adds its own override', function (): void {
    customsGenericPackaging();

    customsApi($this->user, $this->tenant)->postJson('/api/v1/customs/master-data/packaging-types', ['code' => 'CT', 'name_it' => 'Cartone nostro'])
        ->assertCreated()->assertJsonPath('data.is_generic', false);

    $rows = $this->getJson('/api/v1/customs/master-data/packaging-types')->assertOk()->json('data');

    expect($rows)->toHaveCount(2)->and($rows[0]['name_it'])->toBe('Cartone nostro');
});

test('generic rows are read-only for a company', function (): void {
    $generic = customsGenericPackaging();

    customsApi($this->user, $this->tenant)->putJson("/api/v1/customs/master-data/packaging-types/{$generic->id}", ['name_it' => 'Cambiato'])->assertForbidden();
    $this->deleteJson("/api/v1/customs/master-data/packaging-types/{$generic->id}")->assertForbidden();

    expect($generic->fresh()->name_it)->toBe('Cartone generico');
});

test('the codes of a company stay inside it', function (): void {
    customsApi($this->user, $this->tenant)->postJson('/api/v1/customs/master-data/countries', ['code' => 'XK', 'name_it' => 'Nostro'])->assertCreated();
    $id = CustomsCountry::withoutGlobalScopes()->where('code', 'XK')->value('id');

    $other = customsTenant();
    customsApi(customsMember($other), $other)->getJson('/api/v1/customs/master-data/countries')->assertOk()->assertJsonCount(0, 'data');
    $this->deleteJson("/api/v1/customs/master-data/countries/{$id}")->assertNotFound();
});

test('an unknown table does not exist', function (): void {
    customsApi($this->user, $this->tenant)->getJson('/api/v1/customs/master-data/pets')->assertNotFound();
});

test('a statistical key needs its tariff number and other tables refuse one', function (): void {
    customsApi($this->user, $this->tenant)->postJson('/api/v1/customs/master-data/statistical-keys', ['code' => '911'])->assertUnprocessable();
    $this->postJson('/api/v1/customs/master-data/countries', ['code' => 'CH', 'tariff_code' => '1'])->assertUnprocessable();
});
