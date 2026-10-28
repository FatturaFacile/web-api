<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ExchangeRateSeeder extends Seeder
{
    public function run(): void
    {
        $rates = [
            ['source' => 'EUR', 'target' => 'USD', 'rate' => 1.0850],
            ['source' => 'EUR', 'target' => 'GBP', 'rate' => 0.8550],
            ['source' => 'EUR', 'target' => 'CHF', 'rate' => 0.9650],
            ['source' => 'EUR', 'target' => 'JPY', 'rate' => 165.20],
            ['source' => 'USD', 'target' => 'EUR', 'rate' => 0.9216],
            ['source' => 'GBP', 'target' => 'EUR', 'rate' => 1.1696],
            ['source' => 'CHF', 'target' => 'EUR', 'rate' => 1.0363],
        ];

        $today = now()->toDateString();

        foreach ($rates as $r) {
            $existing = DB::table('exchange_rates')
                ->where('source', $r['source'])
                ->where('target', $r['target'])
                ->where('effective_date', $today)
                ->first();

            if ($existing) {
                DB::table('exchange_rates')
                    ->where('id', $existing->id)
                    ->update([
                        'rate' => $r['rate'],
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('exchange_rates')->insert([
                    'id' => (string) Str::uuid7(),
                    'source' => $r['source'],
                    'target' => $r['target'],
                    'rate' => $r['rate'],
                    'effective_date' => $today,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
