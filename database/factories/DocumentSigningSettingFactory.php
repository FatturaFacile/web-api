<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\DocumentTrust\Models\DocumentSigningSetting;

/**
 * @extends Factory<DocumentSigningSetting>
 */
final class DocumentSigningSettingFactory extends Factory
{
    protected $model = DocumentSigningSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'driver' => 'local',
            'certificate' => null,
            'private_key' => null,
            'passphrase' => null,
            'certificate_expires_at' => null,
        ];
    }
}
