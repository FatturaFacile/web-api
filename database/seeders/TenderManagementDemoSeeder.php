<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\TenderManagement\Models\CompetitorBenchmark;
use Modules\TenderManagement\Models\QualificationVaultDocument;
use Modules\TenderManagement\Models\TenderNotice;
use Modules\TenderManagement\Services\VaultService;

/**
 * Demo tender data for the first company of the installation: a qualification vault with an SOA attestation, DURC and ISO
 * certificates (Italy) and the Swiss social-security proofs, a simap.ch tender (LAPub, lowest price) and an ANAC / MePA tender
 * (D.Lgs. 36/2023, OEPV with the bilinear formula), and a competitor award history for the contracting authorities.
 *
 * Every code, number and amount below is SAMPLE data for demonstration (CIG, CUP and the IBAN are fictitious). Labels live in
 * the frontend dictionary (tenderManagement.*). Idempotent by project number / CIG / document number; it does nothing when there is
 * no company.
 */
final class TenderManagementDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('created_at')->first();

        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->runWithTenant($tenant, function (): void {
            $this->vault();
            $this->notices();
            $this->benchmarks();
        });
    }

    private function vault(): void
    {
        $today = now()->startOfDay();
        $documents = [
            ['doc_type' => 'IT_SOA_Attestation', 'document_number' => 'SOA-DEMO-0001', 'issuing_body' => 'SOA Demo S.p.A.', 'issue_date' => $today->copy()->subYears(2)->toDateString(), 'expiry_date' => $today->copy()->addYears(3)->toDateString(), 'soa_categories_classifications' => [['category' => 'OG1', 'classification' => 'III'], ['category' => 'OS28', 'classification' => 'II']], 'fvoe20_synced' => true],
            ['doc_type' => 'IT_DURC', 'document_number' => 'DURC-DEMO-0001', 'issue_date' => $today->copy()->subDays(30)->toDateString(), 'expiry_date' => $today->copy()->addDays(90)->toDateString(), 'fvoe20_synced' => true],
            ['doc_type' => 'IT_VisuraCamerale', 'document_number' => 'VIS-DEMO-0001', 'issue_date' => $today->copy()->subDays(20)->toDateString()],
            ['doc_type' => 'IT_ISO9001', 'document_number' => 'ISO9001-DEMO', 'issuing_body' => 'Certification Body Demo', 'issue_date' => $today->copy()->subYear()->toDateString(), 'expiry_date' => $today->copy()->addYears(2)->toDateString()],
            ['doc_type' => 'IT_ISO14001', 'document_number' => 'ISO14001-DEMO', 'issue_date' => $today->copy()->subYear()->toDateString(), 'expiry_date' => $today->copy()->addYears(2)->toDateString()],
            ['doc_type' => 'IT_UNI_PdR_125_ParitaGenere', 'document_number' => 'PDR125-DEMO', 'issue_date' => $today->copy()->subMonths(6)->toDateString(), 'expiry_date' => $today->copy()->addYears(2)->toDateString()],
            ['doc_type' => 'CH_EstrattoEsecuzioniFallimenti_Betreibungsauszug', 'document_number' => 'BA-DEMO-0001', 'issuing_body' => 'Betreibungsamt Demo', 'issue_date' => $today->copy()->subDays(25)->toDateString()],
            ['doc_type' => 'CH_AttestatoAVS_AI_IPG', 'document_number' => 'AVS-DEMO-0001', 'issue_date' => $today->copy()->subMonths(2)->toDateString(), 'expiry_date' => $today->copy()->addMonths(10)->toDateString()],
            ['doc_type' => 'CH_AttestatoSUVA', 'document_number' => 'SUVA-DEMO-0001', 'issue_date' => $today->copy()->subMonths(2)->toDateString(), 'expiry_date' => $today->copy()->addMonths(10)->toDateString()],
            ['doc_type' => 'CH_AttestatoLPP', 'document_number' => 'LPP-DEMO-0001', 'issue_date' => $today->copy()->subMonths(2)->toDateString(), 'expiry_date' => $today->copy()->addMonths(10)->toDateString()],
            ['doc_type' => 'CH_AttestatoAFC_IVA', 'document_number' => 'AFC-DEMO-0001', 'issue_date' => $today->copy()->subMonths(2)->toDateString(), 'expiry_date' => $today->copy()->addMonths(10)->toDateString()],
            ['doc_type' => 'CH_ParitaSalarialeLogib', 'document_number' => 'LOGIB-DEMO-0001', 'issue_date' => $today->copy()->subMonths(5)->toDateString(), 'numeric_value' => '2.10'],
        ];

        $vault = app(VaultService::class);

        foreach ($documents as $data) {
            if (QualificationVaultDocument::query()->where('document_number', $data['document_number'])->exists()) {
                continue;
            }

            $synced = (bool) ($data['fvoe20_synced'] ?? false);
            unset($data['fvoe20_synced']);
            $document = $vault->create($data, null, null);

            if ($synced) {
                $vault->syncFvoe($document);
            }
        }
    }

    private function notices(): void
    {
        $today = now()->startOfDay();

        if (! TenderNotice::query()->where('simap_project_number', 'DEMO-CH-2026-0001')->exists()) {
            TenderNotice::create([
                'title' => 'Wartung und Betrieb der Gebäudetechnik — Bundesverwaltung (Demo)',
                'description' => 'Sample simap.ch tender: facility maintenance for a federal office building.',
                'jurisdiction' => 'CH_SIMAP_LAPub',
                'procedure_type' => 'open',
                'contracting_authority_name' => 'Bundesamt für Bauten und Logistik BBL (Demo)',
                'simap_project_number' => 'DEMO-CH-2026-0001',
                'cpv_category_codes' => ['50700000', '71314000'],
                'award_criterion' => 'LowestPrice',
                'currency' => 'CHF',
                'base_auction_amount' => '1850000.00',
                'required_certifications' => ['CH_ParitaSalarialeLogib'],
                'publication_date' => $today->copy()->subDays(5)->toDateString(),
                'clarification_deadline_at' => $today->copy()->addDays(10)->setTime(12, 0)->toDateTimeString(),
                'submission_peremptory_deadline_at' => $today->copy()->addDays(30)->setTime(17, 0)->toDateTimeString(),
                'status' => 'open',
            ]);
        }

        if (! TenderNotice::query()->where('cig_code_it', 'DEMO000001')->exists()) {
            TenderNotice::create([
                'title' => 'Manutenzione straordinaria edificio scolastico — Comune di Esempio (demo)',
                'description' => 'Sample ANAC / MePA tender under D.Lgs. 36/2023, awarded on the most economically advantageous tender.',
                'jurisdiction' => 'IT_Dlgs36_2023_ANAC',
                'procedure_type' => 'open',
                'contracting_authority_name' => 'Comune di Esempio (demo)',
                'cig_code_it' => 'DEMO000001',
                'cup_code_it' => 'DEMOCUP0000001A',
                'rup_name' => 'Ing. Mario Rossi (demo)',
                'dedicated_bank_account_it' => 'IT60X0542811101000000123456',
                'cpv_category_codes' => ['45454000', '45210000'],
                'award_criterion' => 'OEPV_MostEconomicallyAdvantageous',
                'formula_type' => 'Bilinear',
                'currency' => 'EUR',
                'base_auction_amount' => '1200000.00',
                'non_subject_safety_costs_amount' => '36000.00',
                'estimated_labor_cost_amount' => '320000.00',
                'technical_max_score' => '70',
                'economic_max_score' => '30',
                'required_soa_categories' => [['category' => 'OG1', 'classification' => 'III']],
                'required_min_turnover' => '1800000.00',
                'turnover_reference_years' => 3,
                'required_similar_projects_count' => 2,
                'required_similar_projects_amount' => '600000.00',
                'required_certifications' => ['IT_ISO9001'],
                'publication_date' => $today->copy()->subDays(7)->toDateString(),
                'clarification_deadline_at' => $today->copy()->addDays(8)->setTime(12, 0)->toDateTimeString(),
                'submission_peremptory_deadline_at' => $today->copy()->addDays(25)->setTime(12, 0)->toDateTimeString(),
                'status' => 'open',
            ]);
        }
    }

    private function benchmarks(): void
    {
        if (CompetitorBenchmark::query()->where('source', 'demo')->exists()) {
            return;
        }

        $rows = [
            ['Edilizia Alfa S.r.l.', 'Comune di Esempio (demo)', '45454000', 'IT_Dlgs36_2023_ANAC', '18.4200', '58.20', '27.60', '85.80', true],
            ['Costruzioni Beta S.p.A.', 'Comune di Esempio (demo)', '45454000', 'IT_Dlgs36_2023_ANAC', '15.1000', '61.50', '23.40', '84.90', false],
            ['Gamma Impianti S.r.l.', 'Comune di Esempio (demo)', '45454000', 'IT_Dlgs36_2023_ANAC', '22.7500', '49.00', '30.00', '79.00', false],
            ['Servizi Tecnici Delta', 'Bundesamt für Bauten und Logistik BBL (Demo)', '50700000', 'CH_SIMAP_LAPub', '9.5000', null, null, null, true],
            ['Immobilien-Service Epsilon AG', 'Bundesamt für Bauten und Logistik BBL (Demo)', '50700000', 'CH_SIMAP_LAPub', '7.2000', null, null, null, false],
        ];

        foreach ($rows as $index => [$competitor, $authority, $cpv, $jurisdiction, $discount, $technical, $economic, $total, $winner]) {
            CompetitorBenchmark::create([
                'competitor_name' => $competitor,
                'contracting_authority_name' => $authority,
                'cpv_code' => $cpv,
                'jurisdiction' => $jurisdiction,
                'award_date' => now()->subMonths(6 + $index)->toDateString(),
                'base_auction_amount' => '1000000.00',
                'awarded_amount' => $winner ? '830000.00' : null,
                'offered_discount_percentage' => $discount,
                'technical_score' => $technical,
                'economic_score' => $economic,
                'total_score' => $total,
                'is_winner' => $winner,
                'source' => 'demo',
            ]);
        }
    }
}
