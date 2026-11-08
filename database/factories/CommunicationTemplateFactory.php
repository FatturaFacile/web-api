<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Communication\Models\CommunicationTemplate;

/**
 * @extends Factory<CommunicationTemplate>
 */
final class CommunicationTemplateFactory extends Factory
{
    protected $model = CommunicationTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => 'payment.reminder',
            'channel' => 'email',
            'locale' => 'en',
            'subject' => 'Reminder {{ number }}',
            'body' => 'Dear {{ name }}, invoice {{ number }} is due.',
            'is_active' => true,
        ];
    }
}
