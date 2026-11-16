<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Payroll\SalaryDeclaration;

/**
 * @extends Factory<SalaryDeclaration>
 */
class SalaryDeclarationFactory extends Factory
{
    protected $model = SalaryDeclaration::class;

    public function definition(): array
    {
        $year = (int) now()->format('Y');
        $number = sprintf('ELM-%04d-%s', $year, strtoupper(Str::random(4)));

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'declaration_number' => $number,
            'period_year' => $year,
            'status' => 'generated',
            'payroll_run_ids' => [],
            'employee_count' => 0,
            'gross_rappen' => 0,
            'ahv_base_rappen' => 0,
            'withholding_tax_rappen' => 0,
            'net_rappen' => 0,
            'xml_disk' => 'private',
            'xml_path' => "payroll/ch/declarations/{$number}.xml",
            'xml_sha256' => hash('sha256', $number),
            'schema_version' => '5.0',
            'xsd_validated' => false,
            'generated_at' => now(),
        ];
    }
}
