<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Actions\Purchases\RecordSupplierPayment;
use App\Actions\Sales\RecordPayment;
use App\Exceptions\InvalidBackupArchiveException;
use App\Models\BankStatement;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\TenantBackup;
use App\Services\Banking\BankReconciliationService;
use App\Services\Tenancy\TenantContext;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Restores a tenant from a backup archive (`data.json`, `files/...`, `manifest.json`), replacing its current data.
 *
 * 1. The archive is inspected before anything is touched: it must open, carry a manifest of this tenant, match its
 *    SHA-256 checksum and row counts, and name only known tables and safe file paths.
 * 2. A safety snapshot of the current state (pre-restore-safety-{timestamp}.zip) is taken, so a wrong archive can be
 *    rolled back by restoring that snapshot.
 * 3. The tenant is put in maintenance (`is_maintenance`), which rejects writes from other requests.
 * 4. The files are staged first. Then, in one database transaction, the tenant's rows are deleted children first and
 *    the archive is streamed in parents first, keeping UUIDs and relations. Any failure rolls everything back.
 * 5. The staged files replace `tenants/{tenant_id}/` and the derived state (invoice paid totals and statuses, bank
 *    statement statuses) is recalculated.
 *
 * Secrets are not part of backups: the credentials of e-commerce channels survive only for channels that still exist,
 * and restored channels without credentials are deactivated. Users and memberships are never touched.
 */
class TenantRestoreService
{
    private const int INSERT_VARIABLE_LIMIT = 900;

    public function __construct(
        private readonly TenantBackupService $backups,
        private readonly TenantContext $tenantContext,
        private readonly RecordPayment $payments,
        private readonly RecordSupplierPayment $supplierPayments,
        private readonly BankReconciliationService $reconciliation,
    ) {}

    /**
     * Restore a completed backup of the tenant.
     *
     * @return array{tables: int, rows: int, skipped_rows: int, files: int, recalculated: int, safety_backup_id: string}
     *
     * @throws InvalidBackupArchiveException
     */
    public function restoreBackup(TenantBackup $backup, Tenant $tenant): array
    {
        $disk = Storage::disk($backup->disk);
        $source = $disk->readStream($backup->storagePath());

        if (! $backup->isCompleted() || ! is_resource($source)) {
            throw InvalidBackupArchiveException::because(InvalidBackupArchiveException::MISSING_ARCHIVE);
        }

        $temporary = (string) tempnam(sys_get_temp_dir(), 'tenant-restore-');

        try {
            file_put_contents($temporary, $source);
            fclose($source);

            return $this->restoreArchive($temporary, $tenant);
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * Restore the tenant from a ZIP file on the local filesystem.
     *
     * @return array{tables: int, rows: int, skipped_rows: int, files: int, recalculated: int, safety_backup_id: string}
     *
     * @throws InvalidBackupArchiveException
     */
    public function restoreArchive(string $path, Tenant $tenant): array
    {
        $zip = new ZipArchive;

        if (! is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw InvalidBackupArchiveException::because(InvalidBackupArchiveException::UNREADABLE_ARCHIVE);
        }

        $staging = null;

        try {
            $manifest = $this->inspect($zip, $tenant);
            $scopes = $this->backups->tableScopes($tenant->id);

            // Safety net: a snapshot of the current state is taken before anything is deleted.
            $snapshot = $this->backups->snapshot($tenant, Auth::user());

            if ($snapshot->status !== TenantBackup::COMPLETED) {
                throw InvalidBackupArchiveException::because(InvalidBackupArchiveException::SNAPSHOT_FAILED);
            }

            Tenant::query()->whereKey($tenant->id)->update(['is_maintenance' => true]);

            $staging = 'restore-staging/'.Str::uuid();
            $staged = $this->stageFiles($zip, $manifest['files'], $staging);

            $summary = DB::transaction(fn (): array => $this->restoreData($zip, $tenant, $scopes));

            $this->publishFiles($staged, $staging, $tenant);
            $summary['files'] = count($staged);
            $summary['safety_backup_id'] = $snapshot->id;
            $summary['recalculated'] = $this->recalculate($tenant);

            return $summary;
        } catch (Throwable $e) {
            if ($staging !== null) {
                Storage::disk((string) config('backup.files_disk'))->deleteDirectory($staging);
            }

            throw $e;
        } finally {
            Tenant::query()->whereKey($tenant->id)->update(['is_maintenance' => false]);
            $zip->close();
        }
    }

    /**
     * Validate the archive without changing anything.
     *
     * @return array{tables: array<string, int>, files: array<int, string>}
     *
     * @throws InvalidBackupArchiveException
     */
    public function inspect(ZipArchive $zip, Tenant $tenant): array
    {
        if ($zip->locateName('manifest.json') === false) {
            throw InvalidBackupArchiveException::because(InvalidBackupArchiveException::MISSING_MANIFEST);
        }

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);

        if (! is_array($manifest)
            || ! is_string($manifest['tenant_id'] ?? null)
            || ! is_array($manifest['tables'] ?? null)
            || ! is_array($manifest['files'] ?? null)
            || ! is_string($manifest['data_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $manifest['data_sha256']) !== 1) {
            throw InvalidBackupArchiveException::because(InvalidBackupArchiveException::INVALID_MANIFEST);
        }

        if ($manifest['tenant_id'] !== $tenant->id) {
            throw InvalidBackupArchiveException::because(InvalidBackupArchiveException::TENANT_MISMATCH);
        }

        if ($zip->locateName('data.json') === false) {
            throw InvalidBackupArchiveException::because(InvalidBackupArchiveException::MISSING_DATA);
        }

        $this->assertChecksum($zip, $manifest['data_sha256']);

        foreach ($manifest['files'] as $entry) {
            if (! is_string($entry) || ! str_starts_with($entry, 'files/') || str_contains($entry, '..') || str_contains($entry, '\\') || str_contains($entry, "\0") || str_contains($entry, '//')) {
                throw InvalidBackupArchiveException::because(InvalidBackupArchiveException::UNSAFE_PATH, ['path' => is_string($entry) ? $entry : '?']);
            }

            if ($zip->locateName($entry) === false) {
                throw InvalidBackupArchiveException::because(InvalidBackupArchiveException::MISSING_FILE, ['path' => $entry]);
            }
        }

        $this->assertData($zip, $tenant, $manifest['tables']);

        return ['tables' => $manifest['tables'], 'files' => $manifest['files']];
    }

    private function assertChecksum(ZipArchive $zip, string $expected): void
    {
        $stream = $zip->getStream('data.json');
        $context = hash_init('sha256');

        while (is_resource($stream) && ! feof($stream)) {
            hash_update($context, (string) fread($stream, 65536));
        }

        is_resource($stream) && fclose($stream);

        if (! hash_equals($expected, hash_final($context))) {
            throw InvalidBackupArchiveException::because(InvalidBackupArchiveException::CHECKSUM_MISMATCH);
        }
    }

    /**
     * Read the whole data file once: it must belong to the tenant, name known tables, contain only rows of the tenant
     * and match the row counts of the manifest.
     *
     * @param  array<string, mixed>  $expectedCounts
     */
    private function assertData(ZipArchive $zip, Tenant $tenant, array $expectedCounts): void
    {
        $known = array_keys($this->backups->tableScopes($tenant->id));
        $counts = [];

        foreach ($this->readData($zip, $tenant) as [$table, $row]) {
            if (! in_array($table, $known, true)) {
                throw InvalidBackupArchiveException::because(InvalidBackupArchiveException::UNKNOWN_TABLE, ['table' => $table]);
            }

            $counts[$table] ??= 0;

            if ($row === null) {
                continue;
            }

            $counts[$table]++;

            if (array_key_exists('tenant_id', $row) && $row['tenant_id'] !== $tenant->id) {
                throw InvalidBackupArchiveException::because(InvalidBackupArchiveException::TENANT_MISMATCH);
            }
        }

        ksort($counts);
        $expected = array_map('intval', $expectedCounts);
        ksort($expected);

        if ($counts !== $expected) {
            throw InvalidBackupArchiveException::because(InvalidBackupArchiveException::COUNT_MISMATCH);
        }
    }

    /**
     * Stream `data.json` (one row per line): yields `[table, null]` when a table starts and `[table, row]` for each row.
     *
     * @return Generator<int, array{0: string, 1: array<string, mixed>|null}>
     */
    private function readData(ZipArchive $zip, Tenant $tenant): Generator
    {
        $stream = $zip->getStream('data.json');

        if (! is_resource($stream)) {
            throw InvalidBackupArchiveException::because(InvalidBackupArchiveException::MISSING_DATA);
        }

        try {
            $head = json_decode(rtrim((string) fgets($stream)).'}}', true);

            if (! is_array($head) || ($head['tenant_id'] ?? null) !== $tenant->id) {
                throw InvalidBackupArchiveException::because(is_array($head) && isset($head['tenant_id']) ? InvalidBackupArchiveException::TENANT_MISMATCH : InvalidBackupArchiveException::INVALID_DATA);
            }

            $current = null;
            $closed = false;

            while (($line = fgets($stream)) !== false) {
                $line = rtrim($line, "\r\n");

                if ($line === '') {
                    continue;
                }

                if ($line === '}}') {
                    $closed = true;

                    break;
                }

                if ($line === ']') {
                    $current = null;

                    continue;
                }

                if (preg_match('/^,?("(?:[^"\\\\]|\\\\.)+"):\[$/', $line, $match) === 1) {
                    $current = json_decode($match[1]);
                    yield [$current, null];

                    continue;
                }

                $row = $current === null ? null : json_decode($line[0] === ',' ? substr($line, 1) : $line, true);

                if (! is_array($row)) {
                    throw InvalidBackupArchiveException::because(InvalidBackupArchiveException::INVALID_DATA);
                }

                yield [$current, $row];
            }

            if (! $closed) {
                throw InvalidBackupArchiveException::because(InvalidBackupArchiveException::INVALID_DATA);
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Copy the archived files to a staging area, so a failure cannot leave the tenant folder half written.
     *
     * @param  array<int, string>  $entries
     * @return array<int, string> staged relative paths (below `files/`)
     */
    private function stageFiles(ZipArchive $zip, array $entries, string $staging): array
    {
        $disk = Storage::disk((string) config('backup.files_disk'));
        $staged = [];

        foreach ($entries as $entry) {
            $stream = $zip->getStream($entry);

            if (! is_resource($stream)) {
                throw InvalidBackupArchiveException::because(InvalidBackupArchiveException::MISSING_FILE, ['path' => $entry]);
            }

            $relative = Str::after($entry, 'files/');
            $disk->writeStream("{$staging}/{$relative}", $stream);
            fclose($stream);
            $staged[] = $relative;
        }

        return $staged;
    }

    /**
     * Replace the files of the tenant with the staged ones.
     *
     * @param  array<int, string>  $staged
     */
    private function publishFiles(array $staged, string $staging, Tenant $tenant): void
    {
        $disk = Storage::disk((string) config('backup.files_disk'));

        $disk->deleteDirectory("tenants/{$tenant->id}");

        foreach ($staged as $relative) {
            $disk->move("{$staging}/{$relative}", "tenants/{$tenant->id}/{$relative}");
        }

        $disk->deleteDirectory($staging);
    }

    /**
     * Delete the data of the tenant and load the archive, all inside the caller's transaction.
     *
     * @param  array<string, \Closure(Builder): Builder>  $scopes
     * @return array{tables: int, rows: int, skipped_rows: int}
     */
    private function restoreData(ZipArchive $zip, Tenant $tenant, array $scopes): array
    {
        Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
        $this->deferForeignKeyChecks();

        $secrets = $this->snapshotSecrets($scopes);

        foreach (array_reverse(array_keys($scopes)) as $table) {
            $scopes[$table](DB::table($table))->delete();
        }

        $rows = 0;
        $skipped = 0;
        $tables = 0;
        $table = null;
        $schema = [];
        $pending = [];
        $buffer = [];

        $flush = function () use (&$pending, &$table, &$schema, &$buffer, &$rows): void {
            if ($table === null) {
                return;
            }

            $pending = $schema['self'] === null ? $pending : $this->parentsFirst($pending, $schema['self']);
            $perInsert = max(1, intdiv(self::INSERT_VARIABLE_LIMIT, max(1, count($schema['columns']))));

            foreach (array_chunk($pending, $perInsert) as $chunk) {
                DB::table($table)->insert($chunk);
            }

            $rows += count($pending);
            $pending = [];
            $buffer = [];
        };

        foreach ($this->readData($zip, $tenant) as [$name, $row]) {
            if ($name !== $table) {
                $flush();
                $table = $name;
                $schema = $this->tableSchema($name, $scopes);
                $tables++;
            }

            if ($row === null) {
                continue;
            }

            $prepared = $this->prepareRow($name, $row, $schema, $tenant, $secrets[$name] ?? []);

            if ($prepared === null) {
                $skipped++;

                continue;
            }

            $pending[] = $prepared;

            if ($schema['self'] === null && count($pending) >= 200) {
                $flush();
            }
        }

        $flush();

        return ['tables' => $tables, 'rows' => $rows, 'skipped_rows' => $skipped];
    }

    /**
     * Let SQLite and PostgreSQL check foreign keys at commit time instead of row by row (MySQL relies on the
     * parents-first order of the archive).
     */
    private function deferForeignKeyChecks(): void
    {
        match (DB::connection()->getDriverName()) {
            'sqlite' => DB::statement('PRAGMA defer_foreign_keys = ON'),
            'pgsql' => DB::statement('SET CONSTRAINTS ALL DEFERRED'),
            default => null,
        };
    }

    /**
     * The current values of the redacted columns, by table and row id.
     *
     * @param  array<string, \Closure>  $scopes
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function snapshotSecrets(array $scopes): array
    {
        $secrets = [];

        foreach (array_keys($scopes) as $table) {
            $columns = array_values(array_intersect(TenantBackupService::REDACTED_COLUMNS, Schema::getColumnListing($table)));

            if ($columns === [] || ! Schema::hasColumn($table, 'id')) {
                continue;
            }

            foreach ($scopes[$table](DB::table($table))->get(array_merge(['id'], $columns)) as $row) {
                $secrets[$table][$row->id] = (array) $row;
            }
        }

        return $secrets;
    }

    /**
     * Columns, nullability, the self-referencing column and the foreign keys that point outside the restored tables.
     *
     * @param  array<string, \Closure>  $scopes
     * @return array{columns: array<int, string>, nullable: array<string, bool>, self: ?string, external: array<string, array{table: string, column: string}>}
     */
    private function tableSchema(string $table, array $scopes): array
    {
        $columns = Schema::getColumns($table);
        $schema = ['columns' => array_column($columns, 'name'), 'nullable' => array_column($columns, 'nullable', 'name'), 'self' => null, 'external' => []];

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (count($foreignKey['columns']) !== 1) {
                continue;
            }

            if ($foreignKey['foreign_table'] === $table) {
                $schema['self'] = $foreignKey['columns'][0];
            } elseif (! isset($scopes[$foreignKey['foreign_table']]) && $foreignKey['foreign_table'] !== 'tenants') {
                $schema['external'][$foreignKey['columns'][0]] = ['table' => $foreignKey['foreign_table'], 'column' => $foreignKey['foreign_columns'][0]];
            }
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{columns: array<int, string>, nullable: array<string, bool>, self: ?string, external: array<string, array{table: string, column: string}>}  $schema
     * @param  array<string, array<string, mixed>>  $secrets
     * @return array<string, mixed>|null null when the row cannot be restored (it references a record that no longer exists)
     */
    private function prepareRow(string $table, array $row, array $schema, Tenant $tenant, array $secrets): ?array
    {
        $row = array_intersect_key($row, array_flip($schema['columns']));

        if (array_key_exists('tenant_id', $schema['nullable'])) {
            $row['tenant_id'] = $tenant->id;
        }

        foreach ($schema['external'] as $column => $target) {
            $value = $row[$column] ?? null;

            if ($value !== null && ! DB::table($target['table'])->where($target['column'], $value)->exists()) {
                if (! $schema['nullable'][$column]) {
                    return null;
                }

                $row[$column] = null;
            }
        }

        foreach (TenantBackupService::REDACTED_COLUMNS as $column) {
            if (! array_key_exists($column, $schema['nullable'])) {
                continue;
            }

            $kept = $secrets[$row['id'] ?? ''] ?? null;

            if ($kept !== null && array_key_exists($column, $kept)) {
                $row[$column] = $kept[$column];
            } elseif (! $schema['nullable'][$column]) {
                $row[$column] = $column === 'credentials' ? Crypt::encryptString('[]') : '';
            }
        }

        // A channel restored without its credentials cannot synchronise: it stays off until they are entered again.
        if ($table === 'ecommerce_channels' && ! isset($secrets[$row['id'] ?? ''])) {
            $row['is_active'] = false;
        }

        return $row;
    }

    /**
     * Rows ordered so that a row follows the row it points to through the self-referencing column.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function parentsFirst(array $rows, string $column): array
    {
        $ids = array_flip(array_column($rows, 'id'));
        $placed = [];
        $ordered = [];

        while ($rows !== []) {
            $before = count($rows);

            foreach ($rows as $index => $row) {
                $parent = $row[$column] ?? null;

                if ($parent === null || isset($placed[$parent]) || ! isset($ids[$parent])) {
                    $ordered[] = $row;
                    $placed[$row['id']] = true;
                    unset($rows[$index]);
                }
            }

            if (count($rows) === $before) {
                return array_merge($ordered, array_values($rows));
            }
        }

        return $ordered;
    }

    /**
     * Re-derive what is calculated from other records: the paid total and status of invoices that have payments, and
     * the status of every bank statement.
     */
    private function recalculate(Tenant $tenant): int
    {
        return (int) $this->tenantContext->runWithTenant($tenant, function () use ($tenant): int {
            $count = 0;
            $paid = DB::table('payments')->where('tenant_id', $tenant->id)->groupBy('invoice_id')->selectRaw('invoice_id, SUM(amount) as total')->pluck('total', 'invoice_id');

            foreach (Invoice::query()->whereIn('id', $paid->keys()->all())->get() as $invoice) {
                if (abs((float) $invoice->total_paid - (float) $paid[$invoice->id]) > 0.001) {
                    $this->payments->recalculate($invoice);
                    $count++;
                }
            }

            $supplierPaid = DB::table('supplier_payments')->where('tenant_id', $tenant->id)->where('type', '!=', 'bank_fee')->groupBy('passive_invoice_id')->selectRaw('passive_invoice_id, SUM(amount) as total')->pluck('total', 'passive_invoice_id');

            foreach (PassiveInvoice::query()->whereIn('id', $supplierPaid->keys()->all())->get() as $invoice) {
                if (abs((float) $invoice->total_paid - (float) $supplierPaid[$invoice->id]) > 0.001) {
                    $this->supplierPayments->recalculate($invoice);
                    $count++;
                }
            }

            foreach (BankStatement::query()->get() as $statement) {
                $this->reconciliation->refreshStatus($statement);
            }

            return $count;
        });
    }
}
