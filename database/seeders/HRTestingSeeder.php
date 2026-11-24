<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class HRTestingSeeder extends Seeder
{
    public function run(): void
    {
        if (Tenant::count() === 0) {
            Tenant::create([
                'id' => (string) Str::uuid7(),
                'name' => 'HR Enterprise Testing Tenant',
                'vat_number' => 'IT99887766554',
                'country_code' => 'IT',
                'currency' => 'EUR',
            ]);
        }

        $this->call([
            HRReferenceSeeder::class,
        ]);
    }
}
