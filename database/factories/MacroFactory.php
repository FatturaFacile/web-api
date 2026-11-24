<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\Macro;

/**
 * @extends Factory<Macro>
 */
class MacroFactory extends Factory
{
    protected $model = Macro::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'is_shared' => true,
            'name' => 'Ask for details',
            'actions' => ['set_status' => 'pending_customer', 'reply' => ['body' => 'Hello {{requester.name}}, could you send more details?']],
        ];
    }
}
