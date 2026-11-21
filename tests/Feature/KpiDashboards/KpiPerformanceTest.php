<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/KpiTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Bulk-inserts balanced two-line journal entries spread over 2026 (revenue/cash and COGS/cash).
 */
function kpiSeedLedger(string $tenantId, int $entries, string $revenue, string $cogs, string $cash): void
{
    $now = now()->toDateTimeString();
    $entryRows = [];
    $lineRows = [];

    for ($i = 0; $i < $entries; $i++) {
        $id = (string) Str::uuid();
        $date = '2026-'.str_pad((string) (($i % 6) + 1), 2, '0', STR_PAD_LEFT).'-'.str_pad((string) (($i % 27) + 1), 2, '0', STR_PAD_LEFT);
        $isSale = $i % 2 === 0;
        $amount = 100 + ($i % 50);
        $entryRows[] = ['id' => $id, 'tenant_id' => $tenantId, 'fiscal_period_id' => 'fp', 'entry_number' => "JE-{$i}", 'posting_date' => $date, 'document_date' => $date, 'ledger_type' => 'statutory', 'journal_type' => 'standard', 'description' => 'perf', 'status' => 'posted', 'total_amount' => $amount, 'created_at' => $now, 'updated_at' => $now];
        $lineRows[] = ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'journal_entry_id' => $id, 'posting_date' => $date, 'line_number' => 1, 'account_id' => $cash, 'description' => 'l', 'debit_amount' => $isSale ? $amount : 0, 'credit_amount' => $isSale ? 0 : $amount, 'functional_debit' => $isSale ? $amount : 0, 'functional_credit' => $isSale ? 0 : $amount, 'currency' => 'CHF', 'exchange_rate' => 1, 'amount_in_functional_currency' => 0, 'created_at' => $now, 'updated_at' => $now];
        $lineRows[] = ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'journal_entry_id' => $id, 'posting_date' => $date, 'line_number' => 2, 'account_id' => $isSale ? $revenue : $cogs, 'description' => 'l', 'debit_amount' => $isSale ? 0 : $amount, 'credit_amount' => $isSale ? $amount : 0, 'functional_debit' => $isSale ? 0 : $amount, 'functional_credit' => $isSale ? $amount : 0, 'currency' => 'CHF', 'exchange_rate' => 1, 'amount_in_functional_currency' => 0, 'created_at' => $now, 'updated_at' => $now];

        if (count($entryRows) === 200) {
            DB::table('journal_entries')->insert($entryRows);
            DB::table('journal_lines')->insert($lineRows);
            $entryRows = $lineRows = [];
        }
    }

    if ($entryRows !== []) {
        DB::table('journal_entries')->insert($entryRows);
        DB::table('journal_lines')->insert($lineRows);
    }
}

it('loads the CEO cockpit of a 120,000-line multi-tenant ledger in under 500 ms from the cache and recomputes after a refresh', function (): void {
    kpiDeferForeignKeys();
    $tenant = kpiTenant();
    $other = kpiTenant();
    $accounts = fn ($t): array => [kpiAccount($t, '4000', 'revenue'), kpiAccount($t, '5000', 'cogs'), kpiAccount($t, '1000', 'asset', 'cash')];
    [$revenue, $cogs, $cash] = $accounts($tenant);
    [$otherRevenue, $otherCogs, $otherCash] = $accounts($other);

    kpiSeedLedger((string) $tenant->id, 50000, $revenue, $cogs, $cash);        // 100,000 lines
    kpiSeedLedger((string) $other->id, 10000, $otherRevenue, $otherCogs, $otherCash); // 20,000 lines of another tenant
    $lines = DB::table('journal_lines')->count();
    expect($lines)->toBeGreaterThanOrEqual(120000);

    kpiActAs($tenant, 'owner');

    $started = hrtime(true);
    $cold = kpiCockpit($this, 'ceo')->assertOk();
    $coldMs = (hrtime(true) - $started) / 1e6;

    // Expected revenue: half of the 50,000 entries are sales of 100 + (i mod 50), for i even.
    $expected = 0;
    for ($i = 0; $i < 50000; $i += 2) {
        $expected += 100 + ($i % 50);
    }
    expect(kpiWidget($cold, 'revenue_ytd_vs_budget')['value'])->toEqual((float) $expected);

    $samples = [];
    for ($run = 0; $run < 20; $run++) {
        $started = hrtime(true);
        kpiCockpit($this, 'ceo')->assertOk();
        $samples[] = (hrtime(true) - $started) / 1e6;
    }
    sort($samples);
    $p95 = $samples[(int) ceil(0.95 * count($samples)) - 1];

    fwrite(STDERR, sprintf("\n[kpi-perf] lines=%d cold=%.0fms warm p95=%.1fms\n", $lines, $coldMs, $p95));
    expect($p95)->toBeLessThan(500.0);

    // A new posting plus a refresh drops the cached payload: the next load shows it.
    kpiPost($tenant, '2026-06-01', [[$cash, 1000000, 0], [$revenue, 0, 1000000]]);
    Cache::flush();
    $this->postJson('/api/v1/kpi-dashboards/cubes/refresh')->assertOk();
    expect(kpiWidget(kpiCockpit($this, 'ceo')->assertOk(), 'revenue_ytd_vs_budget')['value'])->toEqual((float) ($expected + 1000000));
})->group('performance');
