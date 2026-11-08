<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmployeeDocument;

/**
 * @extends Factory<EmployeeDocument>
 */
final class EmployeeDocumentFactory extends Factory
{
    protected $model = EmployeeDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'employee_id' => Employee::factory(),
            'document_type' => 'contract',
            'title' => 'Contratto di lavoro',
            'file_path' => 'tenants/test/hr/'.fake()->uuid().'.pdf',
            'original_name' => 'contratto.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'is_confidential' => true,
            'contains_health_data' => false,
            'visible_to_employee' => false,
            'status' => 'valid',
        ];
    }
}
