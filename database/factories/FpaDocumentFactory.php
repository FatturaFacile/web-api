<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SDI\Enums\FpaDirection;
use Modules\SDI\Enums\FpaStatus;
use Modules\SDI\Models\FpaDocument;

/**
 * @extends Factory<FpaDocument>
 */
final class FpaDocumentFactory extends Factory
{
    protected $model = FpaDocument::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'direction' => FpaDirection::Outbound,
            'format_transmission' => 'FPR12',
            'document_type' => 'TD01',
            'document_number' => (string) fake()->numberBetween(1, 9999),
            'document_date' => now()->toDateString(),
            'sdi_identifier' => (string) Str::uuid(),
            'status' => FpaStatus::Transmitted,
            'transmitted_at' => now(),
        ];
    }
}
