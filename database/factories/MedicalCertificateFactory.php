<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\CertificateKind;
use Modules\Associations\Models\MedicalCertificate;

/**
 * @extends Factory<MedicalCertificate>
 */
class MedicalCertificateFactory extends Factory
{
    protected $model = MedicalCertificate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => CertificateKind::NonCompetitive,
            'issued_on' => now()->subMonth()->toDateString(),
            'expires_on' => now()->addMonths(11)->toDateString(),
        ];
    }
}
