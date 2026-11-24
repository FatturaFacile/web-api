<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Skill;

/**
 * @extends Factory<Skill>
 */
class SkillFactory extends Factory
{
    protected $model = Skill::class;

    public function definition(): array
    {
        $code = 'SK-'.strtoupper(Str::random(5));

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => $code,
            'name' => 'Enterprise Architecture '.$code,
            'category' => 'technical',
            'is_active' => true,
            'translations' => [
                'it' => 'Architettura Enterprise',
                'en' => 'Enterprise Architecture',
                'de' => 'Unternehmensarchitektur',
                'fr' => 'Architecture d\'Entreprise',
            ],
        ];
    }
}
