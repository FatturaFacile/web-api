<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\TenderManagement\Models\TenderParticipation;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

/**
 * Builders of the tender management tests (Mod 169): a company with an owner and a customer contact for the contracting
 * authority, notices, dossiers, vault documents and the signed files of the envelopes.
 */
function tndSetUp(object $test, string $country = 'IT'): void
{
    $test->tenant = Tenant::create(['name' => 'Gare Srl', 'legal_name' => 'Gare Srl', 'country' => $country, 'default_currency' => $country === 'CH' ? 'CHF' : 'EUR', 'vat_number' => 'IT01234567890']);
    $test->owner = treasuryActor($test, 'owner');
    $test->contact = Contact::create([
        'id' => (string) Str::uuid7(), 'tenant_id' => $test->tenant->id, 'type' => 'customer', 'country' => 'IT',
        'company_name' => 'Comune di Esempio', 'email' => 'appalti@comune.example.it', 'vat_number' => 'IT11223344556',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tndNoticePayload(object $test, array $overrides = []): array
{
    return array_merge([
        'title' => 'Manutenzione edificio scolastico',
        'jurisdiction' => 'IT_Dlgs36_2023_ANAC',
        'procedure_type' => 'open',
        'contracting_authority_name' => 'Comune di Esempio',
        'contracting_authority_contact_id' => $test->contact->id,
        'cig_code_it' => 'ABC1234567',
        'cup_code_it' => 'ABCDEFGHIJ12345',
        'rup_name' => 'Mario Rossi',
        'dedicated_bank_account_it' => 'IT60X0542811101000000123456',
        'cpv_category_codes' => ['45454000'],
        'award_criterion' => 'OEPV_MostEconomicallyAdvantageous',
        'formula_type' => 'Linear',
        'currency' => 'EUR',
        'base_auction_amount' => '1000000.00',
        'non_subject_safety_costs_amount' => '20000.00',
        'estimated_labor_cost_amount' => '300000.00',
        'technical_max_score' => 70,
        'economic_max_score' => 30,
        'clarification_deadline_at' => now()->addDays(10)->toDateTimeString(),
        'submission_peremptory_deadline_at' => now()->addDays(30)->toDateTimeString(),
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tndCreateNotice(object $test, array $overrides = []): string
{
    return (string) $test->postJson('/api/v1/tender-management/notices', tndNoticePayload($test, $overrides))->assertCreated()->json('data.id');
}

function tndCreateParticipation(object $test, ?string $noticeId = null): string
{
    return (string) $test->postJson('/api/v1/tender-management/participations', ['tender_id' => $noticeId ?? tndCreateNotice($test)])->assertCreated()->json('data.id');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tndAddVaultDocument(object $test, string $type, array $overrides = []): TestResponse
{
    return $test->postJson('/api/v1/tender-management/vault/documents', array_merge([
        'doc_type' => $type,
        'document_number' => $type.'-'.Str::random(4),
        'issue_date' => now()->subMonth()->toDateString(),
        'expiry_date' => now()->addYears(2)->toDateString(),
    ], $overrides));
}

/**
 * A DER CAdES envelope (.p7m): a SEQUENCE carrying the signedData OID.
 */
function tndP7m(string $name = 'offer.pdf.p7m'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "\x30\x82\x01\x00\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x07\x02".str_repeat("\x00", 64));
}

function tndPlainFile(string $name = 'document.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
}

function tndForceStatus(string $participationId, string $status): void
{
    TenderParticipation::query()->whereKey($participationId)->update(['status' => $status]);
}
