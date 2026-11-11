<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Quality\Models\QmsAudit;
use Modules\Quality\Models\QmsAuditChecklistItem;

/**
 * @extends Factory<QmsAuditChecklistItem>
 */
final class QmsAuditChecklistItemFactory extends Factory
{
    protected $model = QmsAuditChecklistItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'audit_id' => QmsAudit::factory(),
            'position' => 1,
            'clause' => fake()->numerify('#.#'),
            'requirement' => fake()->sentence(),
            'result' => 'pending',
        ];
    }
}
