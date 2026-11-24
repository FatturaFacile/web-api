<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Certification;
use Modules\HumanResources\Models\Employee;

/**
 * @extends Factory<Certification>
 */
class CertificationFactory extends Factory
{
    protected $model = Certification::class;

    public function definition(): array
    {
        $code = 'CERT-'.strtoupper(Str::random(5));

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'employee_id' => function (array $attrs) {
                return Employee::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'code' => $code,
            'name' => 'PMP Project Management Professional '.$code,
            'issuing_authority' => 'Project Management Institute (PMI)',
            'certificate_number' => 'PMI-'.strtoupper(Str::random(8)),
            'issue_date' => now()->subYears(2)->toDateString(),
            'expiry_date' => now()->addDays(20)->toDateString(),
            'status' => 'active',
            'is_mandatory' => true,
            'translations' => [
                'it' => 'Certificazione PMP Project Management',
                'en' => 'PMP Project Management Professional',
                'de' => 'PMP Projektmanagement-Zertifizierung',
                'fr' => 'Certification PMP Gestion de Projet',
            ],
            'metadata' => ['source' => 'factory'],
        ];
    }
}
