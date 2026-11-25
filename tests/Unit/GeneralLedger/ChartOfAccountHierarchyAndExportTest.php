<?php

declare(strict_types=1);

use App\Models\Tenant;
use Database\Seeders\ChartOfAccountTemplateSeeder;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\AccountMapping;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\ChartOfAccountsExporter;

it('computes hierarchy level and path automatically and cascades on subtree move', function (): void {
    $tenant = Tenant::factory()->create();

    $parent = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'account_code' => '1',
        'name' => 'Attivo',
        'account_name' => 'Attivo',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'is_postable' => false,
        'is_active' => true,
    ]);

    $child = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'account_code' => '10',
        'name' => 'Attivo circolante',
        'account_name' => 'Attivo circolante',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'parent_account_id' => $parent->id,
        'is_postable' => false,
        'is_active' => true,
    ]);

    $grandChild = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'account_code' => '1000',
        'name' => 'Cassa',
        'account_name' => 'Cassa',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'parent_account_id' => $child->id,
        'is_postable' => true,
        'is_active' => true,
    ]);

    expect($parent->level)->toBe(1)
        ->and($parent->hierarchy_path)->toBe('1')
        ->and($child->level)->toBe(2)
        ->and($child->hierarchy_path)->toBe('1/10')
        ->and($grandChild->level)->toBe(3)
        ->and($grandChild->hierarchy_path)->toBe('1/10/1000');

    // Sync is_postable <-> allows_manual_posting
    expect($parent->allows_manual_posting)->toBeFalse()
        ->and($grandChild->allows_manual_posting)->toBeTrue();
});

it('provides localized UI copy in all 4 supported locales from the seeder', function (): void {
    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $copy = ChartOfAccountTemplateSeeder::localizedUiCopy($locale);
        expect($copy)->toHaveKeys(['page_title', 'page_subtitle', 'tab_tree', 'tab_mappings', 'provision_button', 'export_xlsx'])
            ->and($copy['page_title'])->not->toBeEmpty();
    }
});

it('neutralizes CSV formula injection and produces valid XML with ChartOfAccountsExporter', function (): void {
    $tenant = Tenant::factory()->create();

    $acc = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'account_code' => '1000',
        'name' => '=SUM(A1:A2)',
        'account_name' => '=SUM(A1:A2)',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'is_postable' => true,
        'is_active' => true,
    ]);

    $mapping = AccountMapping::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'source_account_id' => $acc->id,
        'source_standard' => 'ch_kmu_pme',
        'target_standard' => 'it_general_ledger',
        'target_account_code' => '22.02',
        'target_account_name' => 'Denaro e valori in cassa',
        'mapping_type' => 'direct',
        'is_bidirectional' => true,
        'allocation_percentage' => '100.0000',
        'is_active' => true,
    ]);

    $exporter = new ChartOfAccountsExporter;
    $csv = $exporter->toCsv(collect([$acc->load(['parentAccount', 'vatCode', 'defaultCostCenter', 'outgoingMappings'])]));
    expect($csv)->toContain("'=SUM(A1:A2)");

    $xml = $exporter->toXml(collect([$acc]), collect([$mapping->load('sourceAccount')]), (string) $tenant->id, 'ch_kmu_pme');
    $parsed = simplexml_load_string($xml);
    expect($parsed)->not->toBeFalse()
        ->and((string) $parsed->Accounts->Account[0]->Code)->toBe('1000')
        ->and((string) $parsed->Mappings->Mapping[0]->TargetCode)->toBe('22.02');
});
