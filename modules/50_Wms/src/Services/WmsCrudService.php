<?php

declare(strict_types=1);

namespace Modules\Wms\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Create, update and delete of a plain record of the Warehouse management (WMS) module. Services with business rules extend it and
 * override the hooks.
 */
abstract class WmsCrudService
{
    /**
     * @return class-string<Model>
     */
    abstract protected function modelClass(): string;

    /**
     * Last chance to validate or complete the payload before it is stored.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function prepare(string $tenantId, array $data, ?Model $existing): array
    {
        return $data;
    }

    /**
     * Throws a validation exception when the record cannot be deleted.
     */
    protected function assertDeletable(Model $record): void {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): Model
    {
        return DB::transaction(function () use ($tenantId, $data): Model {
            $class = $this->modelClass();

            return $class::create([...$this->prepare($tenantId, $data, null), 'tenant_id' => $tenantId])->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $record->update($this->prepare((string) $record->getAttribute('tenant_id'), $data, $record));

            return $record->refresh();
        });
    }

    public function delete(Model $record): void
    {
        DB::transaction(function () use ($record): void {
            $this->assertDeletable($record);
            $record->delete();
        });
    }
}
