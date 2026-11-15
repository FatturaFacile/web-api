<?php

declare(strict_types=1);

namespace Modules\DataExport\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of the export history and the export schedules.
 */
#[OA\Tag(name: 'Data Export', description: 'CSV and accountant exports, with history, signed downloads and schedules')]
#[OA\Schema(
    schema: 'ExportJobResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'dataset', type: 'string', enum: ['invoices', 'purchases', 'payments', 'contacts', 'accountant_archive']),
        new OA\Property(property: 'status', type: 'string', enum: ['queued', 'running', 'completed', 'failed']),
        new OA\Property(property: 'period_from', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'period_to', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'file_name', type: 'string', nullable: true),
        new OA\Property(property: 'size_bytes', type: 'integer', nullable: true),
        new OA\Property(property: 'row_count', type: 'integer', nullable: true),
        new OA\Property(property: 'error', type: 'string', nullable: true),
        new OA\Property(property: 'schedule_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'is_downloadable', type: 'boolean'),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ExportScheduleResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'dataset', type: 'string', enum: ['invoices', 'purchases', 'payments', 'contacts', 'accountant_archive']),
        new OA\Property(property: 'frequency', type: 'string', enum: ['daily', 'weekly', 'monthly']),
        new OA\Property(property: 'period', type: 'string', enum: ['all', 'previous_month', 'year_to_date']),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'next_run_on', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'last_run_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'StoreExportJobRequest',
    required: ['dataset'],
    type: 'object',
    properties: [
        new OA\Property(property: 'dataset', type: 'string', enum: ['invoices', 'purchases', 'payments', 'contacts', 'accountant_archive']),
        new OA\Property(property: 'from', type: 'string', format: 'date', nullable: true, description: 'Required for the accountant archive'),
        new OA\Property(property: 'to', type: 'string', format: 'date', nullable: true, description: 'Required for the accountant archive, at most one year after `from`'),
    ]
)]
#[OA\Schema(
    schema: 'StoreExportScheduleRequest',
    required: ['dataset', 'frequency', 'period'],
    type: 'object',
    properties: [
        new OA\Property(property: 'dataset', type: 'string', enum: ['invoices', 'purchases', 'payments', 'contacts', 'accountant_archive']),
        new OA\Property(property: 'frequency', type: 'string', enum: ['daily', 'weekly', 'monthly']),
        new OA\Property(property: 'period', type: 'string', enum: ['all', 'previous_month', 'year_to_date']),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'SpreadsheetImportPreview',
    type: 'object',
    properties: [
        new OA\Property(property: 'headers', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'sample', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'row_count', type: 'integer'),
        new OA\Property(property: 'fields', type: 'array', items: new OA\Items(type: 'string'), description: 'The fields of the dataset that can be mapped'),
        new OA\Property(property: 'suggested_mapping', type: 'object', description: 'Field to header, from the names of the headers'),
    ]
)]
#[OA\Schema(
    schema: 'SpreadsheetImportResult',
    type: 'object',
    properties: [
        new OA\Property(property: 'dry_run', type: 'boolean'),
        new OA\Property(property: 'total', type: 'integer'),
        new OA\Property(property: 'created', type: 'integer'),
        new OA\Property(property: 'skipped', type: 'integer', description: 'Duplicates'),
        new OA\Property(property: 'failed', type: 'integer'),
        new OA\Property(property: 'duplicates', type: 'array', items: new OA\Items(type: 'integer'), description: 'Lines skipped as duplicates'),
        new OA\Property(property: 'errors', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'line', type: 'integer'),
            new OA\Property(property: 'messages', type: 'array', items: new OA\Items(type: 'string')),
        ], type: 'object')),
    ]
)]
final class DataExportSchemas {}
