<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Marketplace\Contracts\RecordMapper;
use Modules\Marketplace\Exceptions\RecordMappingException;
use Modules\Marketplace\Models\MarketplaceInstallation;
use Modules\Marketplace\Models\MarketplaceStagedRecord;
use Modules\Marketplace\Models\MarketplaceSyncFailure;
use Modules\Marketplace\Models\MarketplaceSyncRun;
use Modules\Marketplace\Support\ConnectorContext;
use Modules\Marketplace\Support\ImportOutcome;
use Modules\Marketplace\Support\MappedRecord;
use Throwable;

/**
 * Maps the pulled records of a resource and stores them in the staging table, one record at a time. A record that cannot
 * be mapped or stored is logged as a failure of the run and the next record is processed. The idempotency key of a record
 * (installation, resource, external id) makes a re-sync update the staged record only when its content changed.
 *
 * It never calls the external system.
 */
final class RecordImporter
{
    /**
     * @param  list<array<string, mixed>>  $records
     */
    public function import(MarketplaceInstallation $installation, MarketplaceSyncRun $run, string $resource, RecordMapper $mapper, array $records, ConnectorContext $context): ImportOutcome
    {
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $failed = 0;
        $cursor = null;

        foreach ($records as $record) {
            $candidate = $mapper->cursorOf($installation->connector_key, $resource, $record);
            $cursor = $candidate !== null && ($cursor === null || strnatcmp($candidate, $cursor) > 0) ? $candidate : $cursor;
            $externalId = null;

            try {
                $mapped = $mapper->map($installation->connector_key, $resource, $record);
                $externalId = $mapped->externalId;

                match ($this->stage($installation, $run, $resource, $mapped)) {
                    'created' => $created++,
                    'updated' => $updated++,
                    default => $unchanged++,
                };
            } catch (Throwable $exception) {
                $failed++;
                $this->recordFailure($installation, $run, $resource, $externalId ?? $this->guessId($record), $exception, $context);
            }
        }

        return new ImportOutcome(count($records), $failed, $created, $updated, $unchanged, $cursor);
    }

    /**
     * @return 'created'|'updated'|'unchanged'
     */
    private function stage(MarketplaceInstallation $installation, MarketplaceSyncRun $run, string $resource, MappedRecord $mapped): string
    {
        $key = hash('sha256', $installation->id.'|'.$resource.'|'.$mapped->externalId);
        $hash = hash('sha256', (string) json_encode($mapped->payload));
        $existing = $this->find($installation, $key);

        if ($existing === null) {
            try {
                MarketplaceStagedRecord::create([
                    'tenant_id' => $installation->tenant_id,
                    'installation_id' => $installation->id,
                    'sync_run_id' => $run->id,
                    'connector_key' => $installation->connector_key,
                    'resource' => $resource,
                    'entity_type' => $mapped->entityType,
                    'external_id' => $mapped->externalId,
                    'idempotency_key' => $key,
                    'content_hash' => $hash,
                    'payload' => $mapped->payload,
                    'status' => MarketplaceStagedRecord::Pending,
                ]);

                return 'created';
            } catch (UniqueConstraintViolationException) {
                $existing = $this->find($installation, $key);

                if ($existing === null) {
                    throw new RecordMappingException(__('marketplace_errors.sync.failed'));
                }
            }
        }

        if ($existing->content_hash === $hash) {
            return 'unchanged';
        }

        $existing->update([
            'sync_run_id' => $run->id,
            'entity_type' => $mapped->entityType,
            'content_hash' => $hash,
            'payload' => $mapped->payload,
            'status' => MarketplaceStagedRecord::Pending,
            'processed_at' => null,
        ]);

        return 'updated';
    }

    private function find(MarketplaceInstallation $installation, string $key): ?MarketplaceStagedRecord
    {
        return MarketplaceStagedRecord::query()->where('installation_id', $installation->id)->where('idempotency_key', $key)->first();
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function guessId(array $record): ?string
    {
        $id = $record['id'] ?? null;

        return is_int($id) || is_string($id) ? mb_substr((string) $id, 0, 191) : null;
    }

    private function recordFailure(MarketplaceInstallation $installation, MarketplaceSyncRun $run, string $resource, ?string $externalId, Throwable $exception, ConnectorContext $context): void
    {
        if (! $exception instanceof RecordMappingException) {
            report($exception);
        }

        $message = $exception instanceof RecordMappingException ? $exception->getMessage() : __('marketplace_errors.sync.failed');

        MarketplaceSyncFailure::create([
            'tenant_id' => $installation->tenant_id,
            'installation_id' => $installation->id,
            'sync_run_id' => $run->id,
            'resource' => $resource,
            'external_id' => $externalId,
            'message' => mb_substr($context->redact($message), 0, 500),
        ]);
    }
}
