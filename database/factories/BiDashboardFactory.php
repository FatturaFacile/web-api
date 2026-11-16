<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\BusinessIntelligence\Models\BiDashboard;
use Modules\BusinessIntelligence\Models\BiSavedQuery;

/**
 * @extends Factory<BiDashboard>
 */
final class BiDashboardFactory extends Factory
{
    protected $model = BiDashboard::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'owner_id' => User::factory(),
            'name' => 'Sales overview',
            'visibility' => BiSavedQuery::Private,
        ];
    }
}
