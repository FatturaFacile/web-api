<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\TalentLearning\Models\LmsCertificate;
use Modules\TalentLearning\Models\LmsCourse;
use Modules\TalentLearning\Models\LmsEnrolment;

/**
 * @extends Factory<LmsCertificate>
 */
final class LmsCertificateFactory extends Factory
{
    protected $model = LmsCertificate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'enrolment_id' => LmsEnrolment::factory(),
            'course_id' => LmsCourse::factory(),
            'employee_id' => (string) Str::uuid7(),
            'certificate_number' => 'CERT-2027-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'issued_on' => '2027-01-10',
        ];
    }
}
