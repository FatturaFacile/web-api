<?php

declare(strict_types=1);

namespace Database\Factories\Privacy;

use App\Models\Privacy\PrivacyDsrEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PrivacyDsrEvent>
 */
final class PrivacyDsrEventFactory extends Factory
{
    protected $model = PrivacyDsrEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'event' => 'received',
            'from_status' => null,
            'to_status' => 'received',
        ];
    }
}
