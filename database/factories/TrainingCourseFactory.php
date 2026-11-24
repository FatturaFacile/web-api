<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\TrainingCourse;

/**
 * @extends Factory<TrainingCourse>
 */
class TrainingCourseFactory extends Factory
{
    protected $model = TrainingCourse::class;

    public function definition(): array
    {
        $code = 'TRN-'.strtoupper(Str::random(5));

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'course_code' => $code,
            'title' => 'Industrial Safety & ISO 45001 '.$code,
            'category' => 'safety',
            'provider' => 'TÜV Italia Academy',
            'duration_hours' => 16.00,
            'cost_per_participant' => 650.00,
            'currency' => 'EUR',
            'validity_months' => 24,
            'is_mandatory_compliance' => true,
            'is_active' => true,
            'translations' => [
                'it' => 'Sicurezza Industriale e ISO 45001',
                'en' => 'Industrial Safety & ISO 45001',
                'de' => 'Arbeitssicherheit & ISO 45001',
                'fr' => 'Sécurité Industrielle & ISO 45001',
            ],
        ];
    }
}
