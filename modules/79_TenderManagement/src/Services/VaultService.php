<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Services;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\TenderManagement\Domain\Enums\VaultDocType;
use Modules\TenderManagement\Domain\Enums\VerificationStatus;
use Modules\TenderManagement\Exceptions\TenderException;
use Modules\TenderManagement\Models\QualificationVaultDocument;

/**
 * The company qualification vault: documents with their validity, renewals, the FVOE 2.0 flag and the traffic-light overview
 * segmented by Italian and Swiss documents.
 */
final class VaultService
{
    /** Documents every company is expected to hold, per regime. */
    private const array Expected = [
        'IT' => [VaultDocType::ItSoa, VaultDocType::ItDurc, VaultDocType::ItVisura, VaultDocType::ItCasellario],
        'CH' => [VaultDocType::ChBetreibungsauszug, VaultDocType::ChAvs, VaultDocType::ChSuva, VaultDocType::ChLpp, VaultDocType::ChAfcVat],
    ];

    public function __construct(private readonly VaultStatusResolver $status) {}

    /**
     * Documents not replaced by a renewal.
     *
     * @return Builder<QualificationVaultDocument>
     */
    public function activeQuery(): Builder
    {
        return QualificationVaultDocument::query()->whereNotIn('id', fn ($query) => $query->select('renewed_from_id')->from('tnd_vault_documents')->whereNotNull('renewed_from_id')->whereNull('deleted_at'));
    }

    /**
     * @return Collection<int, QualificationVaultDocument>
     */
    public function activeDocuments(): Collection
    {
        return $this->activeQuery()->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?UploadedFile $file, ?string $userId): QualificationVaultDocument
    {
        $document = QualificationVaultDocument::create(array_merge($this->attributes($data), $this->fileAttributes($file), ['created_by' => $userId]));
        $this->status->sync($document, CarbonImmutable::today());

        return $document->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(QualificationVaultDocument $document, array $data, ?UploadedFile $file, ?string $userId): QualificationVaultDocument
    {
        $attributes = $this->attributes($data);

        if ($file !== null) {
            $this->deleteFile($document->file_path);
            $attributes = array_merge($attributes, $this->fileAttributes($file));
        }

        $document->fill(array_merge($attributes, ['updated_by' => $userId]))->save();
        $this->status->sync($document, CarbonImmutable::today());

        return $document->refresh();
    }

    /**
     * Records a renewal: the new document replaces the old one in the vault (and in every Go/No-Go evaluation) while the old row stays as history.
     *
     * @param  array<string, mixed>  $data
     */
    public function renew(QualificationVaultDocument $document, array $data, ?UploadedFile $file, ?string $userId): QualificationVaultDocument
    {
        return DB::transaction(function () use ($document, $data, $file, $userId): QualificationVaultDocument {
            $inherited = [
                'doc_type' => $document->doc_type->value,
                'title' => $document->title,
                'document_number' => $document->document_number,
                'issuing_body' => $document->issuing_body,
                'soa_categories_classifications' => $document->soa_categories_classifications,
            ];
            $renewed = QualificationVaultDocument::create(array_merge(
                $this->attributes(array_merge($inherited, $data)),
                $this->fileAttributes($file),
                ['renewed_from_id' => $document->id, 'fvoe20_synced' => false, 'created_by' => $userId],
            ));
            $this->status->sync($renewed, CarbonImmutable::today());

            return $renewed->refresh();
        });
    }

    /**
     * Marks the SOA attestation or the DURC as aligned with the ANAC FVOE 2.0 position (the operator verified it on the ANAC
     * portal or through the PASSOE flow); the flag is what the Go/No-Go matrix and the submission gate read.
     */
    public function syncFvoe(QualificationVaultDocument $document): QualificationVaultDocument
    {
        if (! in_array($document->doc_type, [VaultDocType::ItSoa, VaultDocType::ItDurc], true)) {
            throw new TenderException('document_type_jurisdiction_mismatch');
        }

        $document->forceFill(['fvoe20_synced' => true, 'fvoe20_synced_at' => CarbonImmutable::now()])->save();

        return $document->refresh();
    }

    public function delete(QualificationVaultDocument $document): void
    {
        $this->deleteFile($document->file_path);
        $document->delete();
    }

    /**
     * The traffic-light overview of the vault, segmented by regime.
     *
     * @return array<string, mixed>
     */
    public function overview(CarbonImmutable $today): array
    {
        $documents = $this->activeDocuments();
        $overview = [];

        foreach (['IT', 'CH'] as $regime) {
            $own = $documents->filter(fn (QualificationVaultDocument $document): bool => $regime === 'IT' ? $document->doc_type->isItalian() : $document->doc_type->isSwiss())->values();
            $summary = array_fill_keys(VerificationStatus::values(), 0);

            foreach ($own as $document) {
                $summary[$this->status->statusAt($document, $today)->value]++;
            }

            $held = $own->filter(fn (QualificationVaultDocument $document): bool => $this->status->isUsable($document, $today))->map(fn (QualificationVaultDocument $document): string => $document->doc_type->value)->all();
            $overview[strtolower($regime)] = [
                'documents' => $own,
                'summary' => $summary,
                'missing_types' => array_values(array_map(static fn (VaultDocType $type): string => $type->value, array_filter(self::Expected[$regime], static fn (VaultDocType $type): bool => ! in_array($type->value, $held, true)))),
            ];
        }

        $fvoe = $documents->filter(fn (QualificationVaultDocument $document): bool => in_array($document->doc_type, [VaultDocType::ItSoa, VaultDocType::ItDurc], true));
        $overview['fvoe'] = ['synced' => $fvoe->where('fvoe20_synced', true)->count(), 'total' => $fvoe->count()];

        return $overview;
    }

    /**
     * Recomputes the stored traffic-light status of every document; returns how many changed.
     */
    public function refreshStatuses(CarbonImmutable $today): int
    {
        $changed = 0;

        QualificationVaultDocument::query()->each(function (QualificationVaultDocument $document) use ($today, &$changed): void {
            $changed += $this->status->sync($document, $today) ? 1 : 0;
        });

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $type = VaultDocType::tryFrom((string) ($data['doc_type'] ?? '')) ?? throw new TenderException('document_type_jurisdiction_mismatch');

        $attributes = array_intersect_key($data, array_flip([
            'title', 'document_number', 'issuing_body', 'soa_categories_classifications', 'issue_date', 'expiry_date',
            'intermediate_triennial_check_date', 'numeric_value', 'notes', 'verification_status',
        ]));

        if (isset($attributes['soa_categories_classifications']) && is_string($attributes['soa_categories_classifications'])) {
            $attributes['soa_categories_classifications'] = json_decode($attributes['soa_categories_classifications'], true) ?: null;
        }

        if ($type === VaultDocType::ItSoa && ! isset($attributes['intermediate_triennial_check_date']) && isset($attributes['issue_date'])) {
            $attributes['intermediate_triennial_check_date'] = CarbonImmutable::parse((string) $attributes['issue_date'])->addYears((int) config('tender_management.soa.intermediate_check_years', 3))->toDateString();
        }

        return array_merge($attributes, ['doc_type' => $type->value]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fileAttributes(?UploadedFile $file): array
    {
        if ($file === null) {
            return [];
        }

        if ($file->getSize() > (int) config('tender_management.uploads.max_kilobytes', 20480) * 1024) {
            throw new TenderException('file_too_large');
        }

        $contents = (string) file_get_contents($file->getRealPath());
        $tenant = (string) (app(TenantContext::class)->getTenantId() ?? 'global');
        $path = "tender-management/{$tenant}/vault/".bin2hex(random_bytes(8)).'.'.(strtolower((string) $file->getClientOriginalExtension()) ?: 'bin');
        Storage::disk((string) config('tender_management.uploads.disk', 'local'))->put($path, $contents);

        return ['file_path' => $path, 'file_name' => (string) $file->getClientOriginalName(), 'file_sha256' => hash('sha256', $contents)];
    }

    private function deleteFile(?string $path): void
    {
        if ($path !== null) {
            Storage::disk((string) config('tender_management.uploads.disk', 'local'))->delete($path);
        }
    }
}
