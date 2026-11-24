<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Models\CustomsGatewayMessage;

/**
 * @extends Factory<CustomsGatewayMessage>
 */
final class CustomsGatewayMessageFactory extends Factory
{
    protected $model = CustomsGatewayMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $messageId = (string) Str::uuid7();

        return [
            'tenant_id' => Tenant::factory(),
            'declaration_id' => static fn (array $attributes): string => (string) CustomsDeclaration::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'direction' => 'out',
            'gateway' => 'local',
            'message_type' => 'declaration',
            'storage_path' => static fn (array $attributes): string => "customs/{$attributes['tenant_id']}/messages/{$attributes['declaration_id']}/{$messageId}.xml",
            'sha256' => hash('sha256', $messageId),
            'outcome' => null,
        ];
    }
}
