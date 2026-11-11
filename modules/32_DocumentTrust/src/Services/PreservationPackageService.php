<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\DocumentTrust\Contracts\TrustedTimestampProvider;
use Modules\DocumentTrust\Enums\PreservationDocumentClass;
use Modules\DocumentTrust\Exceptions\DocumentTrustException;
use Modules\DocumentTrust\Exceptions\PreservationImmutableException;
use Modules\DocumentTrust\Models\PreservationItem;
use Modules\DocumentTrust\Models\PreservationPackage;
use Modules\DocumentTrust\Models\PreservationVerification;
use Modules\DocumentTrust\Support\PreservationSource;
use ZipArchive;

/**
 * Builds, closes, verifies and exports preservation packages.
 *
 * IMPORTANT: this prepares the technical basis of long-term preservation (copies of the files, SHA-256 hashes, a
 * manifest, a package hash, a time-stamp hook, immutability, integrity checks, an audit trail). It is NOT a certified
 * conservatore (AgID accreditation) and does not by itself give legal preservation. The manifest and the index follow
 * the simple structure documented in `manifestFor()` and `indexCsv()`; they are NOT UNI SINCRO (UNI 11386), whose
 * text was not checked while writing this.
 */
final readonly class PreservationPackageService
{
    public const string ManifestFormat = 'fattura-facile-preservation-manifest';

    public const int ManifestVersion = 1;

    private const int JsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * Inject the collaborators.
     */
    public function __construct(
        private PreservationSourceResolver $resolver,
        private PreservationRetention $retention,
        private TrustedTimestampProvider $timestamps,
        private PreservationAccessLogger $logger,
    ) {}

    /**
     * Open a new, empty package of one document class.
     *
     * @param  array{document_class: string, name: string, period_from?: ?string, period_to?: ?string}  $data
     */
    public function create(array $data, User $user): PreservationPackage
    {
        $package = PreservationPackage::create([
            'document_class' => $data['document_class'],
            'name' => $data['name'],
            'period_from' => $data['period_from'] ?? null,
            'period_to' => $data['period_to'] ?? null,
            'status' => PreservationPackage::Open,
            'created_by' => $user->id,
        ]);

        $this->logger->record('create', $package, ['document_class' => $package->document_class]);

        return $package;
    }

    /**
     * Copy a document into an open package and hash it.
     *
     * @param  array{document_date?: ?string, document_number?: ?string, counterpart_name?: ?string, counterpart_vat_number?: ?string}  $overrides
     *
     * @throws DocumentTrustException when the document cannot be preserved, is already in the package or is outside its period
     */
    public function addItem(PreservationPackage $package, string $sourceId, array $overrides = []): PreservationItem
    {
        $class = PreservationDocumentClass::from($package->document_class);
        $source = $this->resolver->resolve($class, $sourceId, $overrides);
        $this->assertWithinPeriod($package, $source);

        $disk = $this->disk();
        $item = null;
        $path = null;

        try {
            $item = DB::transaction(function () use ($package, $class, $source, $disk, &$path): PreservationItem {
                $locked = PreservationPackage::lockForUpdate()->findOrFail($package->id);

                if ($locked->isClosed()) {
                    throw new PreservationImmutableException('A closed preservation package accepts no more files.');
                }

                if (PreservationItem::where('package_id', $locked->id)->where('source_type', $source->sourceType)->where('source_id', $source->sourceId)->exists()) {
                    throw new DocumentTrustException('The document is already in this package.');
                }

                $sequence = (int) PreservationItem::where('package_id', $locked->id)->max('sequence') + 1;
                $itemId = (string) Str::uuid();
                $path = "tenants/{$locked->tenant_id}/preservation/{$locked->id}/{$itemId}.{$this->extension($source->fileName)}";
                Storage::disk($disk)->put($path, $source->content);

                $item = new PreservationItem([
                    'tenant_id' => $locked->tenant_id,
                    'package_id' => $locked->id,
                    'sequence' => $sequence,
                    'document_class' => $class->value,
                    'source_type' => $source->sourceType,
                    'source_id' => $source->sourceId,
                    'file_name' => $source->fileName,
                    'mime_type' => $source->mimeType,
                    'size' => strlen($source->content),
                    'sha256' => hash('sha256', $source->content),
                    'storage_disk' => $disk,
                    'storage_path' => $path,
                    'document_type' => $source->documentType,
                    'document_date' => $source->documentDate,
                    'document_number' => $source->documentNumber,
                    'counterpart_name' => $source->counterpartName,
                    'counterpart_vat_number' => $source->counterpartVatNumber,
                    'own_vat_number' => $source->ownVatNumber,
                    'metadata' => $source->extra,
                ]);
                $item->id = $itemId;
                $item->save();

                $locked->items_count = (int) PreservationItem::where('package_id', $locked->id)->count();
                $locked->total_bytes = (int) PreservationItem::where('package_id', $locked->id)->sum('size');
                $locked->save();

                return $item;
            });
        } catch (\Throwable $exception) {
            if ($path !== null) {
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }

        $this->logger->record('add_item', $package, ['item_id' => $item->id, 'sha256' => $item->sha256]);

        return $item;
    }

    /**
     * Remove a file from an open package (and from the disk).
     *
     * @throws PreservationImmutableException when the package is closed
     */
    public function removeItem(PreservationPackage $package, PreservationItem $item): void
    {
        DB::transaction(function () use ($package, $item): void {
            $locked = PreservationPackage::lockForUpdate()->findOrFail($package->id);

            if ($locked->isClosed()) {
                throw new PreservationImmutableException('The files of a closed preservation package cannot be removed.');
            }

            $item->delete();
            $locked->items_count = (int) PreservationItem::where('package_id', $locked->id)->count();
            $locked->total_bytes = (int) PreservationItem::where('package_id', $locked->id)->sum('size');
            $locked->save();
        });

        Storage::disk($item->storage_disk)->delete($item->storage_path);
        $this->logger->record('remove_item', $package, ['item_id' => $item->id]);
    }

    /**
     * Close the package: fix the retention, write the manifest, hash it and time-stamp the hash. Nothing can change
     * afterwards. If the time-stamp provider fails the package stays open.
     *
     * @throws DocumentTrustException when the package is empty or the provider fails
     */
    public function close(PreservationPackage $package, User $user): PreservationPackage
    {
        $closed = DB::transaction(function () use ($package, $user): PreservationPackage {
            $locked = PreservationPackage::lockForUpdate()->findOrFail($package->id);

            if ($locked->isClosed()) {
                throw new PreservationImmutableException('The package is already closed.');
            }

            $items = PreservationItem::where('package_id', $locked->id)->orderBy('sequence')->get();

            if ($items->isEmpty()) {
                throw new DocumentTrustException('An empty package cannot be closed.');
            }

            $retention = $this->retention->effective(PreservationDocumentClass::from($locked->document_class));
            $closedAt = Carbon::now();
            $locked->retention_years = $retention['years'];
            $locked->retention_source = $retention['source'];
            $locked->retention_until = $closedAt->copy()->addYears($retention['years']);
            $locked->items_count = $items->count();
            $locked->total_bytes = (int) $items->sum('size');
            $locked->closed_at = $closedAt;
            $locked->closed_by = $user->id;

            $manifest = $this->manifestFor($locked, $items->all());
            $locked->manifest_json = $manifest;
            $locked->package_hash = hash('sha256', $manifest);

            $token = $this->timestamps->timestamp($locked->package_hash);
            $locked->timestamp_provider = $token->provider;
            $locked->timestamp_token = $token->token;
            $locked->timestamp_qualified = $token->qualified;
            $locked->timestamped_at = Carbon::instance($token->issuedAt);
            $locked->status = PreservationPackage::Closed;
            $locked->save();

            return $locked;
        });

        $this->logger->record('close', $closed, ['package_hash' => $closed->package_hash, 'items' => $closed->items_count, 'timestamp_provider' => $closed->timestamp_provider, 'timestamp_qualified' => $closed->timestamp_qualified]);

        return $closed;
    }

    /**
     * Recompute the hashes of a closed package and record the result.
     *
     * Checks the manifest against the package hash, then every manifest entry against its item record and against the
     * stored file. Failure reasons: manifest_hash_mismatch, item_missing, item_record_altered, file_missing,
     * hash_mismatch, item_not_in_manifest.
     *
     * @param  'manual'|'scheduled'|'export'  $trigger
     */
    public function verify(PreservationPackage $package, string $trigger = 'manual', ?string $userId = null): PreservationVerification
    {
        if (! $package->isClosed()) {
            throw new DocumentTrustException('Only a closed package can be verified.');
        }

        $failures = [];
        $manifestText = (string) $package->manifest_json;
        $manifestOk = hash_equals((string) $package->package_hash, hash('sha256', $manifestText));

        if (! $manifestOk) {
            $failures[] = ['item_id' => null, 'reason' => 'manifest_hash_mismatch'];
        }

        $manifest = json_decode($manifestText, true);
        $entries = is_array($manifest) && is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        $items = PreservationItem::withoutGlobalScopes()->where('package_id', $package->id)->get()->keyBy('id');
        $listed = [];

        foreach ($entries as $entry) {
            $itemId = (string) ($entry['item_id'] ?? '');
            $listed[$itemId] = true;
            $item = $items->get($itemId);

            if ($item === null) {
                $failures[] = ['item_id' => $itemId, 'reason' => 'item_missing'];

                continue;
            }

            if (! hash_equals((string) $entry['sha256'], (string) $item->sha256)) {
                $failures[] = ['item_id' => $itemId, 'reason' => 'item_record_altered'];

                continue;
            }

            $actual = $this->fileHash($item);

            if ($actual === null) {
                $failures[] = ['item_id' => $itemId, 'reason' => 'file_missing'];
            } elseif (! hash_equals((string) $entry['sha256'], $actual)) {
                $failures[] = ['item_id' => $itemId, 'reason' => 'hash_mismatch'];
            }
        }

        foreach ($items->keys() as $itemId) {
            if (! isset($listed[$itemId])) {
                $failures[] = ['item_id' => (string) $itemId, 'reason' => 'item_not_in_manifest'];
            }
        }

        $verification = PreservationVerification::create([
            'tenant_id' => $package->tenant_id,
            'package_id' => $package->id,
            'result' => $failures === [] ? 'passed' : 'failed',
            'trigger' => $trigger,
            'manifest_ok' => $manifestOk,
            'checked_items' => count($entries),
            'failed_items' => count($failures),
            'failures' => $failures === [] ? null : $failures,
            'verified_by' => $userId,
            'verified_at' => now(),
        ]);

        $this->logger->record('verify', $package, ['trigger' => $trigger, 'result' => $verification->result, 'failed' => count($failures)], $package->tenant_id, $userId);

        return $verification;
    }

    /**
     * Zip of a closed package: `manifest.json` (exactly the text that was hashed), `index.csv`, `README.txt` and the
     * files under `files/`. The integrity of the package is checked first; a package that fails the check is not exported.
     *
     * @return string path of the temporary zip, to be deleted after it is sent
     *
     * @throws DocumentTrustException when the package is open or fails the integrity check
     */
    public function export(PreservationPackage $package): string
    {
        if (! $package->isClosed()) {
            throw new DocumentTrustException('Only a closed package can be exported.');
        }

        $verification = $this->verify($package, 'export', auth()->id());

        if ($verification->result !== 'passed') {
            throw new DocumentTrustException('The package failed its integrity check and cannot be exported.');
        }

        $items = PreservationItem::where('package_id', $package->id)->orderBy('sequence')->get();
        $path = tempnam(sys_get_temp_dir(), 'pkg');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new DocumentTrustException('The export file could not be created.');
        }

        $zip->addFromString('manifest.json', (string) $package->manifest_json);
        $zip->addFromString('index.csv', $this->indexCsv($items->all()));
        $zip->addFromString('README.txt', $this->readme($package));

        foreach ($items as $item) {
            $zip->addFromString($this->zipPath($item), (string) Storage::disk($item->storage_disk)->get($item->storage_path));
        }

        $zip->close();

        return $path;
    }

    /**
     * The manifest text. JSON, UTF-8, a fixed key order and no pretty printing, so the text that is hashed is the text
     * that is stored and exported. Structure: `format`, `format_version`, `note`, package data (id, name,
     * document_class, period, closed_at, retention) and `files`, one entry per file with item_id, sequence, path,
     * file_name, mime_type, size, sha256, document_type, document_date, document_number, counterpart_name,
     * counterpart_vat_number, own_vat_number and the source (type and id). Own structure, not UNI SINCRO.
     *
     * @param  list<PreservationItem>  $items
     */
    private function manifestFor(PreservationPackage $package, array $items): string
    {
        return json_encode([
            'format' => self::ManifestFormat,
            'format_version' => self::ManifestVersion,
            'note' => 'Technical basis of preservation. Not issued by a certified conservatore (AgID accreditation). Not UNI SINCRO.',
            'package_id' => (string) $package->id,
            'tenant_id' => (string) $package->tenant_id,
            'name' => $package->name,
            'document_class' => $package->document_class,
            'period_from' => $package->period_from?->toDateString(),
            'period_to' => $package->period_to?->toDateString(),
            'closed_at' => $package->closed_at?->toIso8601String(),
            'retention_years' => $package->retention_years,
            'retention_source' => $package->retention_source,
            'retention_until' => $package->retention_until?->toDateString(),
            'hash_algorithm' => 'sha256',
            'files_count' => count($items),
            'files' => array_map(fn (PreservationItem $item): array => [
                'item_id' => (string) $item->id,
                'sequence' => $item->sequence,
                'path' => $this->zipPath($item),
                'file_name' => $item->file_name,
                'mime_type' => $item->mime_type,
                'size' => $item->size,
                'sha256' => $item->sha256,
                'document_type' => $item->document_type,
                'document_date' => $item->document_date?->toDateString(),
                'document_number' => $item->document_number,
                'counterpart_name' => $item->counterpart_name,
                'counterpart_vat_number' => $item->counterpart_vat_number,
                'own_vat_number' => $item->own_vat_number,
                'source_type' => $item->source_type,
                'source_id' => (string) $item->source_id,
            ], $items),
        ], self::JsonFlags);
    }

    /**
     * Index of the files: CSV, UTF-8, semicolon separated, header row, one line per file.
     *
     * @param  list<PreservationItem>  $items
     */
    private function indexCsv(array $items): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['sequence', 'path', 'sha256', 'size', 'document_type', 'document_date', 'document_number', 'counterpart_name', 'counterpart_vat_number', 'own_vat_number'], ';', '"', '\\');

        foreach ($items as $item) {
            fputcsv($handle, [$item->sequence, $this->zipPath($item), $item->sha256, $item->size, $item->document_type, $item->document_date?->toDateString(), $item->document_number, $item->counterpart_name, $item->counterpart_vat_number, $item->own_vat_number], ';', '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function readme(PreservationPackage $package): string
    {
        return implode("\n", [
            "Preservation package {$package->id} ({$package->name})",
            "Class: {$package->document_class}. Closed: {$package->closed_at?->toIso8601String()}. Package hash (SHA-256 of manifest.json): {$package->package_hash}",
            "Time stamp: provider {$package->timestamp_provider}, qualified: ".($package->timestamp_qualified ? 'yes' : 'no').'.',
            'manifest.json lists every file with its SHA-256; index.csv is the same list in tabular form.',
            'This archive is the technical basis of preservation. It was NOT produced by a certified conservatore (AgID accreditation).',
            'The manifest and index use a simple documented structure of this application, not UNI SINCRO.',
            '',
        ]);
    }

    private function zipPath(PreservationItem $item): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $item->file_name) ?: 'file';

        return sprintf('files/%06d-%s', $item->sequence, $name);
    }

    private function fileHash(PreservationItem $item): ?string
    {
        $disk = Storage::disk($item->storage_disk);

        if (! $disk->exists($item->storage_path)) {
            return null;
        }

        $stream = $disk->readStream($item->storage_path);

        if (! is_resource($stream)) {
            return null;
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    private function assertWithinPeriod(PreservationPackage $package, PreservationSource $source): void
    {
        if ($source->documentDate === null || ($package->period_from === null && $package->period_to === null)) {
            return;
        }

        $date = Carbon::parse($source->documentDate)->startOfDay();

        if (($package->period_from !== null && $date->lt($package->period_from->startOfDay())) || ($package->period_to !== null && $date->gt($package->period_to->startOfDay()))) {
            throw new DocumentTrustException('The document date is outside the period of the package.');
        }
    }

    private function extension(string $fileName): string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : 'bin';
    }

    private function disk(): string
    {
        return (string) config('filesystems.private_disk', config('filesystems.default', 'local'));
    }
}
