<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\BusinessIntelligence\Models\BiSavedQuery;
use Modules\BusinessIntelligence\Models\BiSnapshot;

/**
 * @extends Factory<BiSnapshot>
 */
final class BiSnapshotFactory extends Factory
{
    protected $model = BiSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'source_type' => BiSnapshot::SavedQuery,
            'source_id' => BiSavedQuery::factory(),
            'taken_on' => '2027-03-15',
            'trigger' => 'manual',
            'status' => BiSnapshot::Ok,
            'row_count' => 0,
            'payload' => ['columns' => [], 'rows' => [], 'meta' => [], 'pivot' => null],
        ];
    }
}
