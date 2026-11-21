<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\Enums\AuditEventType;
use App\Models\Concerns\HasEncryptedFields;
use App\Services\Audit\AuditTrailRecorder;
use App\Services\Security\FieldEncryptionContext;
use App\Services\Security\FieldEncryptionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Re-encrypts every registered encrypted field under the current DEK version (and encrypts legacy plain values) in
 * chunks, without downtime: readers accept every version, so rows can be at different versions while it runs.
 */
final class RotateTenantEncryptionKeysCommand extends Command
{
    protected $signature = 'security:rotate-field-encryption
        {--tenant= : Only this tenant id}
        {--chunk=500 : Rows per transaction}
        {--dry-run : Count the rows that would change without writing}';

    protected $description = 'Rotate field-level encryption keys: re-encrypt protected fields under the current DEK version';

    public function handle(FieldEncryptionService $encryption, AuditTrailRecorder $audit): int
    {
        $tenantFilter = $this->option('tenant');
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $version = $encryption->currentVersion();

        /** @var array<string, int> $changedByTenant */
        $changedByTenant = [];

        /** @var array<string, class-string<Model>> $models */
        $models = config('field_encryption.models', []);

        foreach ($models as $class) {
            /** @var Model $model */
            $model = new $class;

            if (! in_array(HasEncryptedFields::class, class_uses_recursive($model), true) || ! method_exists($model, 'encryptedFieldMap')) {
                continue;
            }

            /** @var array<string, string|null> $fields */
            $fields = $model->encryptedFieldMap();
            $table = $model->getTable();
            $key = $model->getKeyName();

            DB::table($table)
                ->when(is_string($tenantFilter) && $tenantFilter !== '', fn ($query) => $query->where('tenant_id', $tenantFilter))
                ->orderBy($key)
                ->chunkById($chunk, function ($rows) use ($fields, $table, $key, $encryption, $version, $dryRun, &$changedByTenant): void {
                    DB::transaction(function () use ($rows, $fields, $table, $key, $encryption, $version, $dryRun, &$changedByTenant): void {
                        foreach ($rows as $row) {
                            $tenantId = is_scalar($row->tenant_id ?? null) ? (string) $row->tenant_id : 'global';
                            $updates = [];

                            foreach ($fields as $field => $indexColumn) {
                                $raw = $row->{$field} ?? null;

                                if (! is_string($raw) || $raw === '') {
                                    continue;
                                }

                                $context = new FieldEncryptionContext($tenantId, $table, (string) $row->{$key}, $field);
                                $plain = FieldEncryptionService::isCiphertext($raw) ? null : $raw;

                                if ($plain === null && $encryption->versionOf($raw) === $version) {
                                    continue;
                                }

                                $updates[$field] = $plain === null ? $encryption->rotate($raw, $context, $version) : $encryption->encrypt($plain, $context, $version);

                                if ($indexColumn !== null) {
                                    $updates[$indexColumn] = $encryption->blindIndex($plain ?? $encryption->decrypt($raw, $context), $tenantId, $field);
                                }
                            }

                            if ($updates === []) {
                                continue;
                            }

                            $changedByTenant[$tenantId] = ($changedByTenant[$tenantId] ?? 0) + 1;

                            if (! $dryRun) {
                                DB::table($table)->where($key, $row->{$key})->update($updates);
                            }
                        }
                    });
                }, $key);
        }

        foreach ($changedByTenant as $tenantId => $count) {
            $this->line(sprintf('%s tenant %s: %d row(s) %s', $dryRun ? '[dry-run]' : 'rotated', $tenantId, $count, $dryRun ? 'to rotate' : 'to DEK v'.$version));

            if (! $dryRun) {
                $audit->record(
                    event: AuditEventType::KeyRotated,
                    entityType: 'FieldEncryption',
                    entityId: null,
                    tenantId: $tenantId === 'global' ? null : $tenantId,
                    context: ['dek_version' => $version, 'rows' => $count],
                );
            }
        }

        if ($changedByTenant === []) {
            $this->info('Nothing to rotate.');
        }

        return self::SUCCESS;
    }
}
