<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Documents\Models\DmsDocument;
use Modules\DocumentTrust\Exceptions\PreservationImmutableException;
use Modules\DocumentTrust\Models\PreservationAccessLog;
use Modules\DocumentTrust\Models\PreservationDisposal;
use Modules\DocumentTrust\Models\PreservationItem;
use Modules\DocumentTrust\Models\PreservationPackage;

require_once __DIR__.'/PreservationExtraHelpers.php';

uses(RefreshDatabase::class);

/**
 * A package closed with a one year retention (confirmed by the accountant), two years later.
 *
 * @return array{package: string, item: PreservationItem}
 */
function peExpiredPackage(Tenant $tenant, bool $confirmed = true): array
{
    test()->putJson(PE_BASE.'/preservation-settings', ['retention_years' => ['issued_invoice' => 1], 'confirmed_by_accountant' => $confirmed])->assertOk();
    $closed = peClosedPackage($tenant);
    test()->travelTo(now()->addYears(2));

    return $closed;
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.private_disk' => 'local']);
    $this->tenant = complianceTenant();
    $this->first = actingAsMember($this->tenant, 'owner');
});

afterEach(function (): void {
    $this->travelBack();
    app(TenantContext::class)->clear();
});

test('the candidates show the computed expiry and what blocks each package', function (): void {
    peExpiredPackage($this->tenant, confirmed: false);
    $fresh = peClosedPackage($this->tenant, 'FT-2')['package'];

    $rows = collect($this->getJson(PE_BASE.'/preservation-disposal-candidates')->assertOk()->json('data'))->keyBy('package_id');
    $expired = $rows->first(fn (array $row): bool => $row['expired']);

    expect($rows)->toHaveCount(2)
        ->and($expired['eligible'])->toBeFalse()
        ->and($expired['blockers'])->toBe(['retention_not_confirmed'])
        ->and($expired['expiry_date'])->toBe(now()->subYear()->toDateString())
        ->and($rows[$fresh]['blockers'])->toContain('retention_not_expired');

    $this->putJson(PE_BASE.'/preservation-settings', ['confirmed_by_accountant' => true])->assertOk();

    expect($this->getJson(PE_BASE.'/preservation-disposal-candidates?only_eligible=1')->json('data.0.blockers'))->toBe([]);
});

test('the expiry follows a longer period set later, counted from the closing date', function (): void {
    $closed = peExpiredPackage($this->tenant);
    $this->putJson(PE_BASE.'/preservation-settings', ['retention_years' => ['issued_invoice' => 5], 'confirmed_by_accountant' => true])->assertOk();

    $row = $this->getJson(PE_BASE.'/preservation-disposal-candidates')->json('data.0');

    expect($row['package_id'])->toBe($closed['package'])->and($row['blockers'])->toBe(['retention_not_expired'])
        ->and($row['expiry_date'])->toBe(now()->subYears(2)->addYears(5)->toDateString());
    $this->postJson(PE_BASE.'/preservation-disposal-proposals', ['package_id' => $closed['package']])->assertUnprocessable();
});

test('a disposal needs a second owner or administrator, deletes the files and leaves an irrevocable record', function (): void {
    ['package' => $package, 'item' => $item] = peExpiredPackage($this->tenant);
    $hash = PreservationPackage::findOrFail($package)->package_hash;

    $proposal = $this->postJson(PE_BASE.'/preservation-disposal-proposals', ['package_id' => $package, 'note' => 'Scadenza 2027'])->assertCreated()->json('data');
    expect($proposal['status'])->toBe('pending');

    $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal['id']}/approve")->assertUnprocessable();
    $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal['id']}/execute")->assertUnprocessable();
    $this->postJson(PE_BASE.'/preservation-disposal-proposals', ['package_id' => $package])->assertUnprocessable();

    $second = peActAs($this->tenant, 'admin');
    $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal['id']}/approve", ['note' => 'ok'])->assertOk()->assertJsonPath('data.status', 'approved');
    expect(Storage::disk('local')->exists($item->storage_path))->toBeTrue();

    $record = $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal['id']}/execute")->assertOk()->json('data');

    expect(Storage::disk('local')->exists($item->storage_path))->toBeFalse()
        ->and($record['manifest_sha256'])->toBe($hash)
        ->and($record['proposed_by'])->toBe($this->first->id)
        ->and($record['approved_by'])->toBe($second->id)
        ->and($record['executed_by'])->toBe($second->id)
        ->and($record['files'][0])->toMatchArray(['item_id' => $item->id, 'sha256' => $item->sha256])
        ->and(PreservationPackage::findOrFail($package)->manifest_json)->not->toBeNull()
        ->and(PreservationItem::where('package_id', $package)->count())->toBe(1);

    $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal['id']}/execute")->assertUnprocessable();
    $this->postJson(PE_BASE."/preservation-packages/{$package}/verify")->assertUnprocessable();
    $this->getJson(PE_BASE."/preservation-packages/{$package}/export")->assertUnprocessable();
    $this->getJson(PE_BASE.'/preservation-disposal-candidates')->assertJsonCount(0, 'data');
    $this->getJson(PE_BASE.'/preservation-disposals')->assertOk()->assertJsonPath('data.0.package_id', $package);
    $this->getJson(PE_BASE."/preservation-packages/{$package}")->assertOk()->assertJsonPath('data.package.disposed_at', fn ($value) => $value !== null);

    $actions = PreservationAccessLog::where('package_id', $package)->pluck('action')->all();
    expect($actions)->toContain('disposal_propose')->toContain('disposal_approve')->toContain('disposal_execute');
    expect(DB::table('enterprise_audit_ledger')->where('resource_id', $package)->pluck('action')->all())->toContain('PRESERVATION_DISPOSAL_EXECUTE');
});

test('the disposal record cannot be changed or deleted, and the immutability of the closed package is unchanged', function (): void {
    ['package' => $package] = peExpiredPackage($this->tenant);
    $proposal = $this->postJson(PE_BASE.'/preservation-disposal-proposals', ['package_id' => $package])->assertCreated()->json('data.id');
    peActAs($this->tenant, 'admin');
    $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal}/approve")->assertOk();
    $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal}/execute")->assertOk();
    $disposal = PreservationDisposal::firstOrFail();

    expect(fn () => $disposal->update(['executed_by' => $disposal->proposed_by]))->toThrow(PreservationImmutableException::class)
        ->and(fn () => $disposal->delete())->toThrow(PreservationImmutableException::class)
        ->and(fn () => PreservationPackage::findOrFail($package)->update(['name' => 'altro']))->toThrow(PreservationImmutableException::class);

    if (DB::getDriverName() === 'pgsql') {
        expect(fn () => DB::table('preservation_disposals')->update(['manifest_sha256' => str_repeat('a', 64)]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('preservation_disposals')->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('preservation_packages')->where('id', $package)->update(['name' => 'altro']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('preservation_items')->where('package_id', $package)->delete())->toThrow(QueryException::class);
    }
});

test('a legal hold blocks the proposal and the execution, until it is released', function (): void {
    ['package' => $package] = peExpiredPackage($this->tenant);

    $this->postJson(PE_BASE."/preservation-packages/{$package}/legal-hold", ['reason' => 'Verifica fiscale'])->assertCreated();
    $this->postJson(PE_BASE.'/preservation-disposal-proposals', ['package_id' => $package])->assertUnprocessable();
    expect($this->getJson(PE_BASE.'/preservation-disposal-candidates')->json('data.0.blockers'))->toBe(['legal_hold']);

    $this->deleteJson(PE_BASE."/preservation-packages/{$package}/legal-hold")->assertOk()->assertJsonPath('data.released', 1);
    $proposal = $this->postJson(PE_BASE.'/preservation-disposal-proposals', ['package_id' => $package])->assertCreated()->json('data.id');
    peActAs($this->tenant, 'admin');
    $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal}/approve")->assertOk();

    $this->postJson(PE_BASE."/preservation-packages/{$package}/legal-hold", ['reason' => 'Contenzioso'])->assertCreated();
    $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal}/execute")->assertUnprocessable();
    expect(PreservationDisposal::count())->toBe(0);
});

test('a legal hold on a library document of the package blocks the disposal, and a disposed package frees the document', function (): void {
    $this->putJson(PE_BASE.'/preservation-settings', ['retention_years' => ['contract' => 1], 'confirmed_by_accountant' => true])->assertOk();
    $document = $this->post('/api/v1/dms/documents', ['title' => 'Contratto', 'file' => UploadedFile::fake()->createWithContent('c.txt', 'testo')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $package = peOpenPackage('contract');
    $this->postJson(PE_BASE."/preservation-packages/{$package}/items", ['source_id' => $document])->assertCreated();
    $this->postJson(PE_BASE."/preservation-packages/{$package}/close")->assertOk();
    $this->travelTo(now()->addYears(2));

    DmsDocument::withoutGlobalScopes()->where('id', $document)->update(['legal_hold' => true]);
    expect($this->getJson(PE_BASE.'/preservation-disposal-candidates')->json('data.0.blockers'))->toBe(['legal_hold']);

    DmsDocument::withoutGlobalScopes()->where('id', $document)->update(['legal_hold' => false]);
    $this->putJson("/api/v1/dms/documents/{$document}", ['title' => 'Bloccato'])->assertConflict();

    $proposal = $this->postJson(PE_BASE.'/preservation-disposal-proposals', ['package_id' => $package])->assertCreated()->json('data.id');
    peActAs($this->tenant, 'admin');
    $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal}/approve")->assertOk();
    $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal}/execute")->assertOk();

    $this->putJson("/api/v1/dms/documents/{$document}", ['title' => 'Libero'])->assertOk();
});

test('a package whose manifest was tampered with is never disposed of', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The triggers exist on PostgreSQL only');
    }

    ['package' => $package, 'item' => $item] = peExpiredPackage($this->tenant);
    $proposal = $this->postJson(PE_BASE.'/preservation-disposal-proposals', ['package_id' => $package])->assertCreated()->json('data.id');
    peActAs($this->tenant, 'admin');
    $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal}/approve")->assertOk();

    peWithoutTrigger('preservation_packages', 'trg_preservation_packages_guard', function () use ($package): void {
        DB::table('preservation_packages')->where('id', $package)->update(['manifest_json' => DB::raw("replace(manifest_json, 'FT-1', 'FT-2')")]);
    });

    $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal}/execute")->assertUnprocessable();
    expect(Storage::disk('local')->exists($item->storage_path))->toBeTrue()->and(PreservationDisposal::count())->toBe(0);
});

test('a rejected proposal frees the package for a new one, by another person only', function (): void {
    ['package' => $package] = peExpiredPackage($this->tenant);
    $proposal = $this->postJson(PE_BASE.'/preservation-disposal-proposals', ['package_id' => $package])->assertCreated()->json('data.id');

    $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal}/reject")->assertUnprocessable();
    peActAs($this->tenant, 'admin');
    $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal}/reject", ['note' => 'Non ora'])->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal}/approve")->assertUnprocessable();
    $this->postJson(PE_BASE.'/preservation-disposal-proposals', ['package_id' => $package])->assertCreated();
    $this->getJson(PE_BASE.'/preservation-disposal-proposals?status=rejected')->assertJsonCount(1, 'data');
});

test('only owners and administrators work with disposals', function (): void {
    ['package' => $package] = peExpiredPackage($this->tenant);
    peActAs($this->tenant, 'accountant');

    $this->getJson(PE_BASE.'/preservation-disposal-candidates')->assertForbidden();
    $this->postJson(PE_BASE.'/preservation-disposal-proposals', ['package_id' => $package])->assertForbidden();
    $this->postJson(PE_BASE."/preservation-packages/{$package}/legal-hold", ['reason' => 'x'])->assertForbidden();
    $this->getJson(PE_BASE.'/preservation-disposals')->assertForbidden();
});

test('another tenant cannot see or act on the disposals of this one', function (): void {
    ['package' => $package] = peExpiredPackage($this->tenant);
    $proposal = $this->postJson(PE_BASE.'/preservation-disposal-proposals', ['package_id' => $package])->assertCreated()->json('data.id');
    peActAs($this->tenant, 'admin');
    $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal}/approve")->assertOk();

    $beta = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta Srl', 'vat_number' => '00000000019', 'country' => 'IT', 'default_currency' => 'EUR']);
    peActAs($beta, 'owner');

    $this->getJson(PE_BASE.'/preservation-disposal-candidates')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(PE_BASE.'/preservation-disposal-proposals')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(PE_BASE.'/preservation-disposals')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson(PE_BASE.'/preservation-disposal-proposals', ['package_id' => $package])->assertNotFound();
    $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal}/execute")->assertNotFound();
    $this->postJson(PE_BASE."/preservation-disposal-proposals/{$proposal}/approve")->assertNotFound();
    $this->postJson(PE_BASE."/preservation-packages/{$package}/legal-hold", ['reason' => 'x'])->assertNotFound();
    $this->deleteJson(PE_BASE."/preservation-packages/{$package}/legal-hold")->assertNotFound();
});
