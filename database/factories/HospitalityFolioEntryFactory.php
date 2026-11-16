<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Hospitality\Models\FolioEntry;

/**
 * @extends Factory<FolioEntry>
 */
final class HospitalityFolioEntryFactory extends Factory
{
    protected $model = FolioEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'stay_id' => HospitalityStayFactory::new(),
            'entry_type' => 'charge',
            'category' => 'extra',
            'description' => fake()->words(2, true),
            'quantity' => '1.00',
            'unit_amount' => '10.00',
            'amount' => '10.00',
            'posted_on' => now()->toDateString(),
        ];
    }
}
