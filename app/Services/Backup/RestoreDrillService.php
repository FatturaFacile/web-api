<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\Tenant;
use App\Models\TenantBackup;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Restore drill: proves the latest completed backup of a tenant is restorable without touching live data. The archive is
 * fetched from the backup disk, decrypted into a scratch file in the system temp directory and run through the same
 * inspection a real restore does (open, signature, checksums of data and files, every foreign key resolvable). The
 * scratch files are always removed.
 */
class RestoreDrillService
{
    public function __construct(
        private readonly BackupArchiveEncryptor $encryptor,
        private readonly TenantRestoreService $restore,
    ) {}

    /**
     * @return array{ok: bool, backup_id: ?string, tables: int, files: int, rows: int, seconds: float, error: ?string}
     */
    public function run(Tenant $tenant): array
    {
        $started = microtime(true);
        $result = ['ok' => false, 'backup_id' => null, 'tables' => 0, 'files' => 0, 'rows' => 0, 'seconds' => 0.0, 'error' => null];
        $scratch = [];

        try {
            $backup = TenantBackup::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('status', TenantBackup::COMPLETED)
                ->where('filename', 'not like', TenantBackupService::SAFETY_PREFIX.'%')
                ->latest('completed_at')
                ->first();

            if ($backup === null) {
                throw new RuntimeException('No completed backup to restore.');
            }

            $result['backup_id'] = $backup->id;
            $source = Storage::disk($backup->disk)->readStream($backup->storagePath());

            if (! is_resource($source)) {
                throw new RuntimeException('The archive is missing from the backup disk.');
            }

            $scratch[] = $stored = (string) tempnam(sys_get_temp_dir(), 'restore-drill-');
            file_put_contents($stored, $source);
            fclose($source);

            $scratch[] = $plain = (string) tempnam(sys_get_temp_dir(), 'restore-drill-plain-');
            $this->encryptor->decrypt($stored, $plain);

            $zip = new ZipArchive;

            if ($zip->open($plain, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('The archive cannot be opened.');
            }

            try {
                $manifest = $this->restore->inspect($zip, $tenant);
            } finally {
                $zip->close();
            }

            $result['tables'] = count($manifest['tables']);
            $result['rows'] = (int) array_sum($manifest['tables']);
            $result['files'] = count($manifest['files']);
            $result['ok'] = true;
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        } finally {
            foreach ($scratch as $file) {
                @unlink($file);
            }
        }

        $result['seconds'] = round(microtime(true) - $started, 2);

        return $result;
    }
}
