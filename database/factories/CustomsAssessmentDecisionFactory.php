<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Enums\AssessmentDocumentType;
use Modules\Customs\Enums\SignatureStatus;
use Modules\Customs\Models\CustomsAssessmentDecision;

/**
 * @extends Factory<CustomsAssessmentDecision>
 */
final class CustomsAssessmentDecisionFactory extends Factory
{
    protected $model = CustomsAssessmentDecision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $mrn = '26CH'.strtoupper(fake()->unique()->bothify('##########??##'));
        $vatBase = fake()->randomFloat(2, 1000, 25000);

        return [
            'tenant_id' => Tenant::factory(),
            'declaration_id' => null,
            'mrn' => $mrn,
            'version' => 1,
            'document_type' => AssessmentDocumentType::Vat,
            'direction' => 'import',
            'document_date' => fake()->dateTimeBetween('2026-01-01', '2026-12-31')->format('Y-m-d'),
            'trader_declaration_number' => strtoupper(fake()->bothify('CD-########')),
            'duty_amount' => '0.0000',
            'vat_amount' => number_format($vatBase * 0.081, 4, '.', ''),
            'vat_base_amount' => number_format($vatBase, 4, '.', ''),
            'vat_rate' => '8.10',
            'amount_not_charged' => false,
            'xml_path' => "customs/assessments/{$mrn}/1/taxationDecisionVAT.xml",
            'xml_sha256' => hash('sha256', $mrn.'-xml'),
            'pdf_path' => "customs/assessments/{$mrn}/1/taxationDecisionVAT.pdf",
            'pdf_sha256' => hash('sha256', $mrn.'-pdf'),
            'signature_status' => SignatureStatus::Verified,
            'gateway' => 'edec',
            'retrieved_at' => now(),
        ];
    }
}
