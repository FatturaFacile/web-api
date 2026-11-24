<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\SupportMailbox;

/**
 * @extends Factory<SupportMailbox>
 */
class SupportMailboxFactory extends Factory
{
    protected $model = SupportMailbox::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'name' => 'Support',
            'email_address' => 'support-'.Str::lower(Str::random(6)).'@example.com',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_username' => 'support',
            'imap_password' => 'secret',
            'default_priority' => 'medium',
            'is_active' => true,
            'last_uid' => 0,
        ];
    }
}
