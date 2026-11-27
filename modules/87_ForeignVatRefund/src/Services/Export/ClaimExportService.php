<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Services\Export;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Modules\ForeignVatRefund\Domain\Enums\RegimeType;
use Modules\ForeignVatRefund\Domain\Exceptions\ForeignVatException;
use Modules\ForeignVatRefund\Models\FiscalRepresentativeMandate;
use Modules\ForeignVatRefund\Models\ForeignVatLedgerEntry;
use Modules\ForeignVatRefund\Models\VatRefundClaim;
use Modules\ForeignVatRefund\Services\ClaimService;
use Modules\ForeignVatRefund\Services\RefundRuleResolver;
use ZipArchive;

/**
 * Generates and stores the dossier files of a claim: the EU portal XML, the ESTV package (forms 1222 and 1223 plus the original
 * invoices in a ZIP) and the Modello IVA 79 package. Every generation is recorded in the claim trail.
 */
final class ClaimExportService
{
    public const string EU_XML = 'eu-xml';

    public const string ESTV_PACKAGE = 'estv-package';

    public const string IVA79_PACKAGE = 'iva79-package';

    public function __construct(
        private readonly EuPortalXmlGenerator $xml,
        private readonly ClaimDocumentRenderer $renderer,
        private readonly RefundRuleResolver $rules,
        private readonly ClaimService $claims,
    ) {}

    /**
     * Generates the export and returns where it is stored.
     *
     * @return array{path: string, filename: string, mime: string}
     */
    public function generate(VatRefundClaim $claim, string $type, ?string $userId = null): array
    {
        $result = match ($type) {
            self::EU_XML => $this->euXml($claim),
            self::ESTV_PACKAGE => $this->estvPackage($claim),
            self::IVA79_PACKAGE => $this->iva79Package($claim),
            default => throw ForeignVatException::because('export_unknown', ['type' => $type]),
        };

        $this->claims->log($claim, 'export_generated', null, null, null, ['type' => $type, 'file' => $result['filename']], $userId);

        return $result;
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('foreign_vat_refund.disk', 'local'));
    }

    /**
     * @return array{path: string, filename: string, mime: string}
     */
    private function euXml(VatRefundClaim $claim): array
    {
        $this->assertRegime($claim, [RegimeType::EuDirective200809]);
        $content = $this->xml->generate($claim);
        $filename = $claim->claim_number.'-eu-portal.xml';
        $path = $this->directory($claim).'/'.$filename;
        $this->disk()->put($path, $content);
        $claim->update(['generated_xml_path' => $path]);

        return ['path' => $path, 'filename' => $filename, 'mime' => 'application/xml'];
    }

    /**
     * @return array{path: string, filename: string, mime: string}
     */
    private function estvPackage(VatRefundClaim $claim): array
    {
        $this->assertRegime($claim, [RegimeType::ChEstvReciprocity]);
        $tenant = $this->tenant($claim);
        $mandate = $this->validMandate($claim);
        $items = $claim->items()->get();
        $procedure = $this->rules->procedure((string) $claim->tenant_id, $claim->home_country_code, $claim->target_country_code);
        $allowed = array_map('strval', $procedure->vat_rates ?? []);
        $form1222 = $this->renderer->estvForm1222($claim, $tenant, $mandate, $items);
        $form1223 = $this->renderer->estvForm1223($claim, $items, $allowed);

        $files = ['form-1222.pdf' => $form1222, 'form-1223.pdf' => $form1223];

        return $this->bundle($claim, 'estv', $files, $items, $mandate);
    }

    /**
     * @return array{path: string, filename: string, mime: string}
     */
    private function iva79Package(VatRefundClaim $claim): array
    {
        $this->assertRegime($claim, [RegimeType::ItArt38Ter]);

        if (! $this->rules->hasReciprocity((string) $claim->tenant_id, $claim->home_country_code, $claim->target_country_code)) {
            throw ForeignVatException::because('no_reciprocity', ['country' => $claim->target_country_code]);
        }

        $items = $claim->items()->get();
        $pdf = $this->renderer->modelloIva79($claim, $this->tenant($claim), $items);

        return $this->bundle($claim, 'iva79', ['modello-iva-79.pdf' => $pdf], $items, null);
    }

    /**
     * @param  array<string, string>  $files  name => binary content
     * @param  Collection<int, ForeignVatLedgerEntry>  $items
     * @return array{path: string, filename: string, mime: string}
     */
    private function bundle(VatRefundClaim $claim, string $kind, array $files, $items, ?FiscalRepresentativeMandate $mandate): array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'fvr');
        $zip = new ZipArchive;

        if ($temporary === false || $zip->open($temporary, ZipArchive::OVERWRITE) !== true) {
            throw ForeignVatException::because('bundle_failed', [], 500);
        }

        $manifest = ['claim_number' => $claim->claim_number, 'kind' => $kind, 'generated_at' => CarbonImmutable::now()->toIso8601String(), 'documents' => [], 'missing_attachments' => []];

        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $index = 0;

        foreach ($items as $item) {
            $index++;

            if ($item->attachment_path === null || ! $this->disk()->exists($item->attachment_path)) {
                $manifest['missing_attachments'][] = $item->document_number;

                continue;
            }

            $entry = sprintf('attachments/%03d_%s', $index, basename($item->attachment_path));
            $zip->addFromString($entry, (string) $this->disk()->get($item->attachment_path));
            $manifest['documents'][] = ['file' => $entry, 'document_number' => $item->document_number, 'document_date' => $item->document_date->format('Y-m-d')];
        }

        $documents = $mandate === null ? [] : [
            [$mandate->power_of_attorney_document_path, 'mandate/power-of-attorney'],
            [$mandate->certificate_of_taxable_status_path, 'mandate/certificate-of-taxable-status'],
        ];

        foreach ($documents as [$path, $name]) {
            if ($path !== null && $path !== '' && $this->disk()->exists($path)) {
                $zip->addFromString($name.'.'.pathinfo($path, PATHINFO_EXTENSION), (string) $this->disk()->get($path));
            }
        }

        $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        $filename = $claim->claim_number.'-'.$kind.'.zip';
        $path = $this->directory($claim).'/'.$filename;
        $this->disk()->put($path, (string) file_get_contents($temporary));
        @unlink($temporary);
        $claim->update(['zip_bundle_path' => $path, 'generated_pdf_dossier_path' => $this->storePdf($claim, $kind, $files)]);

        return ['path' => $path, 'filename' => $filename, 'mime' => 'application/zip'];
    }

    /**
     * @param  array<string, string>  $files
     */
    private function storePdf(VatRefundClaim $claim, string $kind, array $files): ?string
    {
        $name = array_key_first($files);

        if ($name === null) {
            return null;
        }

        $path = $this->directory($claim).'/'.$claim->claim_number.'-'.$kind.'-'.$name;
        $this->disk()->put($path, $files[$name]);

        return $path;
    }

    private function validMandate(VatRefundClaim $claim): FiscalRepresentativeMandate
    {
        $today = Carbon::today();
        $mandate = ($claim->fiscal_representative_mandate_id !== null ? FiscalRepresentativeMandate::withoutGlobalScopes()->where('tenant_id', $claim->tenant_id)->whereKey($claim->fiscal_representative_mandate_id)->first() : null)
            ?? FiscalRepresentativeMandate::withoutGlobalScopes()->where('tenant_id', $claim->tenant_id)->where('country_code', $claim->target_country_code)->whereNull('deleted_at')->get()
                ->first(fn (FiscalRepresentativeMandate $candidate): bool => $candidate->coversDate($today));

        if ($mandate === null || ! $mandate->coversDate($today)) {
            throw ForeignVatException::because('fiscal_representative_missing', ['country' => $claim->target_country_code]);
        }

        if ($mandate->certificate_of_taxable_status_path === null || $mandate->certificate_of_taxable_status_path === '') {
            throw ForeignVatException::because('certificate_missing');
        }

        if (! $mandate->hasValidCertificate($today)) {
            throw ForeignVatException::because('certificate_expired', ['expiry' => $mandate->certificate_expiry_date?->format('Y-m-d')]);
        }

        return $mandate;
    }

    private function tenant(VatRefundClaim $claim): Tenant
    {
        return Tenant::query()->whereKey($claim->tenant_id)->firstOrFail();
    }

    /**
     * @param  list<RegimeType>  $allowed
     */
    private function assertRegime(VatRefundClaim $claim, array $allowed): void
    {
        if (! in_array($claim->regime_type, $allowed, true)) {
            throw ForeignVatException::because('export_not_supported_for_regime', ['regime' => $claim->regime_type->value]);
        }
    }

    private function directory(VatRefundClaim $claim): string
    {
        return 'foreign-vat-refund/'.$claim->tenant_id.'/claims/'.$claim->id;
    }
}
