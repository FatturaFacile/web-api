<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\GeneralLedger\Models\EnasarcoParameter;

/**
 * @extends Factory<EnasarcoParameter>
 */
final class EnasarcoParameterFactory extends Factory
{
    protected $model = EnasarcoParameter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'year' => 2026,
            'agent_type' => 'multi_mandate',
            'rate_percent' => '17.00',
            'agent_share_percent' => '8.50',
            'annual_ceiling' => '27000.00',
            'source' => 'Regolamento Enasarco - aliquote e massimali 2026',
        ];
    }

    /**
     * A reference parameter shared by every tenant.
     */
    public function generic(): self
    {
        return $this->state(['tenant_id' => null]);
    }
}
