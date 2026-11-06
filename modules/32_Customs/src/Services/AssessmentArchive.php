<?php

declare(strict_types=1);

namespace Modules\Customs\Services;

use App\Models\Tenant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Modules\Customs\DTOs\ParsedAssessment;
use Modules\Customs\DTOs\RetrievedAssessment;
use Modules\Customs\DTOs\SignatureCheck;
use Modules\Customs\Enums\SignatureStatus;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Models\CustomsAssessmentDecision;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Services\Edec\WsSecuritySignatureVerifier;

/**
 * The tamper-evident archive of the assessment decisions: files on the private disk, their hashes in an append-only
 * table, the outcome of the signature check next to them. What is in the archive is never rewritten.
 */
final class AssessmentArchive
{
    public function __construct(
        private readonly AssessmentDecisionParser $parser,
        private readonly WsSecuritySignatureVerifier $verifier,
    ) {}

    /**
     * Stores a decision. The same document arriving twice stays one row, and a corrected decision is a new version.
     *
     * @return array{decision: CustomsAssessmentDecision, is_new: bool}
     */
    public function store(Tenant $tenant, RetrievedAssessment $assessment, string $gateway): array
    {
        $parsed = $this->parser->parse($assessment->xml);
        $existing = $this->existing($tenant, $parsed);

        if ($existing !== null) {
            return ['decision' => $existing, 'is_new' => false];
        }

        $check = $assessment->signedEnvelope === null
            ? new SignatureCheck(SignatureStatus::NotSigned, ['checked_at' => now()->toIso8601String(), 'reason' => 'no_signed_message'])
            : $this->verifier->verify($assessment->signedEnvelope);

        $base = $this->basePath($tenant, $parsed);
        $xmlPath = $this->write($base.'.xml', $assessment->xml);
        $envelopePath = $assessment->signedEnvelope === null ? null : $this->write($base.'.envelope.xml', $assessment->signedEnvelope);
        $pdfPath = $assessment->pdf === null ? null : $this->write($base.'.pdf', $assessment->pdf);

        $decision = new CustomsAssessmentDecision;
        $decision->tenant_id = $tenant->id;
        $decision->fill([
            'declaration_id' => $this->declarationIdFor($tenant, $parsed),
            'mrn' => $parsed->mrn,
            'version' => $parsed->version,
            'document_type' => $parsed->documentType,
            'direction' => $parsed->documentType->direction(),
            'document_date' => $parsed->documentDate,
            'trader_declaration_number' => $parsed->traderDeclarationNumber,
            'duty_amount' => $parsed->dutyAmount,
            'vat_amount' => $parsed->vatAmount,
            'vat_base_amount' => $parsed->vatBaseAmount,
            'vat_rate' => $parsed->vatRate,
            'amount_not_charged' => $parsed->amountNotCharged,
            'xml_path' => $xmlPath,
            'xml_sha256' => hash('sha256', $assessment->xml),
            'pdf_path' => $pdfPath,
            'pdf_sha256' => $assessment->pdf === null ? null : hash('sha256', $assessment->pdf),
            'envelope_path' => $envelopePath,
            'envelope_sha256' => $assessment->signedEnvelope === null ? null : hash('sha256', $assessment->signedEnvelope),
            'signature_status' => $check->status,
            'signature_report' => $check->report,
            'gateway' => $gateway,
            'retrieved_at' => now(),
        ]);
        $decision->save();

        return ['decision' => $decision, 'is_new' => true];
    }

    /**
     * Compares the files with the hashes taken at the download.
     *
     * @return array{is_intact: bool, files: array<string, bool>}
     */
    public function verifyIntegrity(CustomsAssessmentDecision $decision): array
    {
        $files = ['xml' => $this->matches($decision->xml_path, $decision->xml_sha256)];

        if ($decision->pdf_path !== null) {
            $files['pdf'] = $this->matches($decision->pdf_path, (string) $decision->pdf_sha256);
        }

        if ($decision->envelope_path !== null) {
            $files['envelope'] = $this->matches($decision->envelope_path, (string) $decision->envelope_sha256);
        }

        return ['is_intact' => ! in_array(false, $files, true), 'files' => $files];
    }

    public function contents(string $path): ?string
    {
        return $this->disk()->get($path);
    }

    private function matches(string $path, string $hash): bool
    {
        $content = $this->disk()->get($path);

        return $content !== null && hash_equals($hash, hash('sha256', $content));
    }

    private function existing(Tenant $tenant, ParsedAssessment $parsed): ?CustomsAssessmentDecision
    {
        return CustomsAssessmentDecision::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('mrn', $parsed->mrn)
            ->where('version', $parsed->version)
            ->where('document_type', $parsed->documentType->value)
            ->first();
    }

    private function declarationIdFor(Tenant $tenant, ParsedAssessment $parsed): ?string
    {
        return CustomsDeclaration::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where(function ($query) use ($parsed): void {
                $query->where('mrn', $parsed->mrn);

                if ($parsed->traderDeclarationNumber !== null) {
                    $query->orWhere('trader_declaration_number', $parsed->traderDeclarationNumber);
                }
            })
            ->value('id');
    }

    private function basePath(Tenant $tenant, ParsedAssessment $parsed): string
    {
        $mrn = preg_replace('/[^A-Za-z0-9]/', '_', $parsed->mrn);

        return sprintf('customs/%s/assessments/%s/%s_v%d_%s', $tenant->id, substr($parsed->documentDate, 0, 4), $mrn, $parsed->version, $parsed->documentType->value);
    }

    /**
     * @throws CustomsException when the file cannot be written or already exists with other content
     */
    private function write(string $path, string $content): string
    {
        $disk = $this->disk();

        if ($disk->exists($path) && $disk->get($path) !== $content) {
            throw new CustomsException("The archive already holds different content at {$path}.");
        }

        if (! $disk->put($path, $content)) {
            throw new CustomsException('The assessment decision cannot be written to the archive.');
        }

        return $path;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('customs.archive_disk'));
    }
}
