<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\WorkspaceSync\Models\WorkspaceConnection;

/**
 * @extends Factory<WorkspaceConnection>
 */
final class WorkspaceConnectionFactory extends Factory
{
    protected $model = WorkspaceConnection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => (string) Str::uuid7(),
            'provider' => 'google',
            'scopes' => ['calendar'],
            'access_token' => Str::random(40),
            'refresh_token' => Str::random(40),
            'token_expires_at' => now()->addHour(),
            'consent_given_at' => now(),
        ];
    }
}
