<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Associations\Models\Notice;

/**
 * @extends Factory<Notice>
 */
final class NoticeFactory extends Factory
{
    protected $model = Notice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'kind' => 'fee_due',
            'subject_id' => static fn (): string => (string) Str::uuid7(),
            'days_before' => fake()->randomElement([0, 7, 15, 30]),
            'sent_at' => '2026-11-01 08:00:00',
        ];
    }
}
