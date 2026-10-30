<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\Tenant;
use App\Models\TenantBackup;
use App\Models\User;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Exports everything that belongs to one tenant into a ZIP archive stored on the private disk:
 *
 * - `data.json`: the tenant, its members and every row of every tenant table (tables with a `tenant_id` and the child
 *   tables reachable from them through foreign keys, such as invoice items), secrets redacted;
 * - `files/...`: the files the tenant stored (attachments, signatures);
 * - `manifest.json`: row counts per table and the list of files.
 *
 * Only the 7 (configurable) most recent completed backups of a tenant are kept.
 */
class TenantBackupService
{
    /**
     * Framework and system tables that are never part of a tenant backup.
     *
     * @var list<string>
     */
    /**
     * File name prefix of the safety snapshots taken before a restore; they are kept apart from the ordinary retention.
     */
    public const SAFETY_PREFIX = 'pre-restore-safety-';

    public const EXCLUDED_TABLES = [
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'password_reset_tokens',
        'personal_access_tokens', 'tenants', 'users', 'tenant_user', 'tenant_backups',
    ];

    /**
     * Columns holding secrets: they are exported as null.
     *
     * @var list<string>
     */
    public const REDACTED_COLUMNS = ['credentials', 'webhook_secret', 'password', 'remember_token', 'token'];

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * Register a pending backup for the tenant.
     */
    public function create(Tenant $tenant, ?User $user = null): TenantBackup
    {
        $id = (string) Str::uuid();

        return TenantBackup::withoutGlobalScopes()->create([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'user_id' => $user?->id,
            'filename' => "{$id}.zip",
            'disk' => (string) config('backup.disk'),
            'status' => TenantBackup::PENDING,
        ]);
    }

    /**
     * Take a safety snapshot of the tenant right before a restore (pre-restore-safety-{timestamp}.zip), so a wrong
     * archive can be rolled back. The returned backup is completed, or
ailed when the snapshot could not be built.
     */
    public function snapshot(Tenant $tenant, ?User $user = null): TenantBackup
    {
        $base = self::SAFETY_PREFIX.now()->format('Ymd-His');
        $filename = "{$base}.zip";
        $suffix = 2;

        while (TenantBackup::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('filename', $filename)->exists()) {
            $filename = "{$base}-{$suffix}.zip";
            $suffix++;
        }

        $backup = TenantBackup::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user?->id,
            'filename' => $filename,
            'disk' => (string) config('backup.disk'),
            'status' => TenantBackup::PENDING,
        ]);

        return $this->generate($backup)->refresh();
    }

    /**
     * Build the archive of a pending backup. A failure is recorded on the backup and logged, never thrown.
     */
    public function generate(TenantBackup $backup): TenantBackup
    {
        $backup->update(['status' => TenantBackup::PROCESSING, 'error_message' => null]);
        $temporary = [];

        try {
            $tenant = Tenant::withTrashed()->findOrFail($backup->tenant_id);
            $archive = $this->buildArchive($tenant, $temporary);

            $stream = fopen($archive, 'rb');
            Storage::disk($backup->disk)->put($backup->storagePath(), $stream);
            is_resource($stream) && fclose($stream);

            $backup->update([
                'status' => TenantBackup::COMPLETED,
                'file_size_bytes' => (int) filesize($archive),
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Backup {backup} of tenant {tenant} failed: {message}', ['backup' => $backup->id, 'tenant' => $backup->tenant_id, 'message' => $e->getMessage()]);
            Storage::disk($backup->disk)->delete($backup->storagePath());
            $backup->update(['status' => TenantBackup::FAILED, 'error_message' => Str::limit($e->getMessage(), 1000, '')]);

            return $backup;
        } finally {
            foreach ($temporary as $file) {
                @unlink($file);
            }
        }

        $this->applyRetention($backup->tenant_id);

        return $backup;
    }

    /**
     * Keep only the most recent completed backups of the tenant; older archives are removed from storage and database.
     * The safety snapshots taken before restores do not count against that limit: they have their own, smaller one.
     *
     * @return int number of backups removed
     */
    public function applyRetention(string $tenantId): int
    {
        $completed = fn () => TenantBackup::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantBackup::COMPLETED)
            ->orderByDesc('completed_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $obsolete = $completed()->where('filename', 'not like', self::SAFETY_PREFIX.'%')->get()->slice(max(1, (int) config('backup.retention')))
            ->merge($completed()->where('filename', 'like', self::SAFETY_PREFIX.'%')->get()->slice(max(1, (int) config('backup.safety_retention', 3))));

        foreach ($obsolete as $backup) {
            $this->delete($backup);
        }

        return $obsolete->count();
    }

    /**
     * Remove the archive from storage and the record from the database.
     */
    public function delete(TenantBackup $backup): void
    {
        Storage::disk($backup->disk)->delete($backup->storagePath());
        $backup->delete();
    }

    /**
     * @param  array<int, string>  $temporary  temporary files to remove once the archive has been stored
     * @return string path of the finished ZIP
     */
    private function buildArchive(Tenant $tenant, array &$temporary): string
    {
        $dataFile = $this->temporaryFile($temporary);
        $counts = $this->writeData($tenant, $dataFile);
        $checksum = hash_file('sha256', $dataFile);
        $files = $this->tenantFiles($tenant);

        $zipPath = $this->temporaryFile($temporary);
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The backup archive could not be created.');
        }

        $zip->addFile($dataFile, 'data.json');
        $disk = Storage::disk((string) config('backup.files_disk'));
        $entries = [];

        foreach ($files as $path) {
            $copy = $this->temporaryFile($temporary);
            $source = $disk->readStream($path);

            if (! is_resource($source)) {
                continue;
            }

            file_put_contents($copy, $source);
            fclose($source);

            $entry = 'files/'.Str::after($path, "tenants/{$tenant->id}/");
            $zip->addFile($copy, $entry);
            $entries[] = $entry;
        }

        $zip->addFromString('manifest.json', (string) json_encode([
            'format_version' => 2,
            'tenant_id' => $tenant->id,
            'generated_at' => now()->toIso8601String(),
            'tables' => $counts,
            'files' => $entries,
            'data_sha256' => $checksum,
        ], self::JSON_FLAGS | JSON_PRETTY_PRINT));

        if (! $zip->close()) {
            throw new RuntimeException('The backup archive could not be written.');
        }

        return $zipPath;
    }

    /**
     * Stream the relational data of the tenant into a JSON file.
     *
     * @return array<string, int> rows exported per table
     */
    private function writeData(Tenant $tenant, string $file): array
    {
        $handle = fopen($file, 'wb');

        fwrite($handle, '{"format_version":2,"tenant_id":'.json_encode($tenant->id).',"generated_at":'.json_encode(now()->toIso8601String()));
        fwrite($handle, ',"tenant":'.json_encode($tenant->only(['id', 'name', 'legal_name', 'fiscal_code', 'vat_number', 'uid_number', 'country', 'default_currency', 'iban', 'qr_iban', 'sdi_code', 'pec_email', 'settings']), self::JSON_FLAGS));
        fwrite($handle, ',"members":'.json_encode($this->members($tenant), self::JSON_FLAGS));
        fwrite($handle, ',"tables":{'."\n");

        $counts = [];
        $first = true;

        foreach ($this->tableScopes($tenant->id) as $table => $scope) {
            fwrite($handle, ($first ? '' : ',').json_encode($table).":[\n");
            $first = false;
            $count = 0;

            foreach ($scope(DB::table($table))->cursor() as $row) {
                fwrite($handle, ($count++ > 0 ? ',' : '').json_encode($this->redact((array) $row), self::JSON_FLAGS)."\n");
            }

            fwrite($handle, "]\n");
            $counts[$table] = $count;
        }

        fwrite($handle, "}}\n");
        fclose($handle);

        return $counts;
    }

    /**
     * @var array{tables: list<string>, directTables: list<string>, foreignKeyMap: array<string, list<array{column: string, foreign_table: string, foreign_column: string}>>, dependencyOrder: list<string>}|null
     */
    private static ?array $cachedTableMetadata = null;

    /**
     * @return array{tables: list<string>, directTables: list<string>, foreignKeyMap: array<string, list<array{column: string, foreign_table: string, foreign_column: string}>>, dependencyOrder: list<string>}
     */
    private function getTableMetadata(): array
    {
        if (self::$cachedTableMetadata !== null) {
            return self::$cachedTableMetadata;
        }

        $tables = array_values(array_filter(
            Schema::getTableListing(schemaQualified: false),
            fn (string $table): bool => ! in_array($table, self::EXCLUDED_TABLES, true) && ! str_starts_with($table, 'telescope_'),
        ));

        $directTables = [];
        $foreignKeyMap = [];

        foreach ($tables as $table) {
            if (Schema::hasColumn($table, 'tenant_id')) {
                $directTables[] = $table;
            }

            $foreignKeyMap[$table] = [];
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if (count($foreignKey['columns']) === 1 && in_array($foreignKey['foreign_table'], $tables, true)) {
                    $foreignKeyMap[$table][] = [
                        'column' => $foreignKey['columns'][0],
                        'foreign_table' => $foreignKey['foreign_table'],
                        'foreign_column' => $foreignKey['foreign_columns'][0],
                    ];
                }
            }
        }

        $dependencyOrder = $this->dependencyOrder($tables, $foreignKeyMap);

        return self::$cachedTableMetadata = [
            'tables' => $tables,
            'directTables' => $directTables,
            'foreignKeyMap' => $foreignKeyMap,
            'dependencyOrder' => $dependencyOrder,
        ];
    }

    /**
     * The tables to export, each with the closure that restricts a query on it to the rows of the tenant.
     *
     * @return array<string, Closure(Builder): Builder>
     */
    public function tableScopes(string $tenantId): array
    {
        $meta = $this->getTableMetadata();
        $scopes = [];

        foreach ($meta['directTables'] as $table) {
            if ($table === 'invoice_items') {
                $scopes[$table] = fn (Builder $query): Builder => $query->where(function (Builder $sub) use ($tenantId): void {
                    $sub->where('invoice_items.tenant_id', $tenantId)
                        ->orWhereIn('invoice_items.invoice_id', DB::table('invoices')->where('tenant_id', $tenantId)->select('id'));
                });
            } else {
                $scopes[$table] = fn (Builder $query): Builder => $query->where("{$table}.tenant_id", $tenantId);
            }
        }

        // Child tables without a tenant column follow their parents through foreign keys.
        do {
            $resolved = false;

            foreach ($meta['tables'] as $table) {
                if (isset($scopes[$table])) {
                    continue;
                }

                foreach ($meta['foreignKeyMap'][$table] ?? [] as $foreignKey) {
                    $parent = $foreignKey['foreign_table'];

                    if (isset($scopes[$parent])) {
                        $column = $foreignKey['column'];
                        $parentColumn = $foreignKey['foreign_column'];
                        $parentScope = $scopes[$parent];
                        $scopes[$table] = fn (Builder $query): Builder => $query->whereIn("{$table}.{$column}", $parentScope(DB::table($parent))->select("{$parent}.{$parentColumn}"));
                        $resolved = true;

                        break;
                    }
                }
            }
        } while ($resolved);

        $orderedScopes = [];
        foreach ($meta['dependencyOrder'] as $table) {
            if (isset($scopes[$table])) {
                $orderedScopes[$table] = $scopes[$table];
            }
        }

        return $orderedScopes;
    }

    /**
     * The tables sorted so that every table comes after the tables it references (parents before children), which is
     * the order to insert rows; deleting uses the reverse. Ties are broken alphabetically.
     *
     * @param  array<int, string>  $tables
     * @param  array<string, list<array{column: string, foreign_table: string, foreign_column: string}>>|null  $foreignKeyMap
     * @return array<int, string>
     */
    public function dependencyOrder(array $tables, ?array $foreignKeyMap = null): array
    {
        sort($tables);
        $dependencies = [];

        foreach ($tables as $table) {
            $dependencies[$table] = [];

            if ($foreignKeyMap !== null && isset($foreignKeyMap[$table])) {
                foreach ($foreignKeyMap[$table] as $fk) {
                    if ($fk['foreign_table'] !== $table && in_array($fk['foreign_table'], $tables, true)) {
                        $dependencies[$table][$fk['foreign_table']] = true;
                    }
                }
            } else {
                foreach (Schema::getForeignKeys($table) as $foreignKey) {
                    if ($foreignKey['foreign_table'] !== $table && in_array($foreignKey['foreign_table'], $tables, true)) {
                        $dependencies[$table][$foreignKey['foreign_table']] = true;
                    }
                }
            }
        }

        $ordered = [];

        while ($dependencies !== []) {
            $ready = array_keys(array_filter($dependencies, fn (array $needs): bool => array_diff_key($needs, array_flip($ordered)) === []));

            // A cycle cannot be sorted: the rest follows in alphabetical order.
            $ready = $ready === [] ? [array_key_first($dependencies)] : $ready;

            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($dependencies[$table]);
            }
        }

        return $ordered;
    }

    /**
     * The people who can access the tenant, without any credential.
     *
     * @return array<int, array<string, mixed>>
     */
    private function members(Tenant $tenant): array
    {
        return $tenant->users()->get()->map(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->pivot->role,
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function redact(array $row): array
    {
        foreach (self::REDACTED_COLUMNS as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = null;
            }
        }

        return $row;
    }

    /**
     * Every file the tenant stored (`tenants/{tenant_id}/...`).
     *
     * @return array<int, string>
     */
    private function tenantFiles(Tenant $tenant): array
    {
        return Storage::disk((string) config('backup.files_disk'))->allFiles("tenants/{$tenant->id}");
    }

    /**
     * @param  array<int, string>  $temporary
     */
    private function temporaryFile(array &$temporary): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tenant-backup-');

        if ($path === false) {
            throw new RuntimeException('A temporary file could not be created.');
        }

        return $temporary[] = $path;
    }
}
