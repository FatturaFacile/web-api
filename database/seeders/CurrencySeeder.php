<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'precision' => 2],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'precision' => 2],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'precision' => 2],
            ['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'precision' => 2],
            ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'precision' => 0],
        ];

        foreach ($currencies as $curr) {
            $existing = DB::table('currencies')->where('code', $curr['code'])->first();
            if ($existing) {
                DB::table('currencies')->where('code', $curr['code'])->update([
                    'name' => $curr['name'],
                    'symbol' => $curr['symbol'],
                    'precision' => $curr['precision'],
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('currencies')->insert([
                    'id' => (string) Str::uuid7(),
                    'code' => $curr['code'],
                    'name' => $curr['name'],
                    'symbol' => $curr['symbol'],
                    'precision' => $curr['precision'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
