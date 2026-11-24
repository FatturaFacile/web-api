<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\CannedResponse;

/**
 * @extends Factory<CannedResponse>
 */
class CannedResponseFactory extends Factory
{
    protected $model = CannedResponse::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'is_shared' => true,
            'title' => 'Greeting',
            'body' => 'Dear {{requester.name}}, we received ticket {{ticket.number}}.',
        ];
    }
}
