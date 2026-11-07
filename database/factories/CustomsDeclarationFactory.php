<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Enums\DeclarationStatus;
use Modules\Customs\Enums\DeclarationType;
use Modules\Customs\Models\CustomsDeclaration;

/**
 * @extends Factory<CustomsDeclaration>
 */
class CustomsDeclarationFactory extends Factory
{
    protected $model = CustomsDeclaration::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'declaration_type' => DeclarationType::Import,
            'status' => DeclarationStatus::Draft,
            'trader_declaration_number' => strtoupper(fake()->unique()->bothify('CD-########')),
        ];
    }

    public function transit(): self
    {
        return $this->state(['declaration_type' => DeclarationType::Transit]);
    }
}
