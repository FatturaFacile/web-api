<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\MissionReport;

/**
 * @extends Factory<MissionReport>
 */
final class MissionReportFactory extends Factory
{
    protected $model = MissionReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'year' => 2026,
            'answers' => [
                '1' => 'Associazione sportiva dilettantistica iscritta al RUNTS.',
                '2' => 'Promozione dello sport dilettantistico tra i giovani del territorio.',
                '5' => 'Attività di interesse generale svolte in via esclusiva.',
            ],
        ];
    }
}
