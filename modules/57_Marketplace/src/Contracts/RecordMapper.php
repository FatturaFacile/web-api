<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use Modules\Marketplace\Exceptions\RecordMappingException;
use Modules\Marketplace\Support\MappedRecord;

/**
 * Maps the records a connector pulled into the normalised records of the application. A mapper is pure: it never calls the
 * external system and never touches the database.
 */
interface RecordMapper
{
    /**
     * Whether the mapper handles the resource of the connector.
     */
    public function supports(string $connectorKey, string $resource): bool;

    /**
     * @param  array<string, mixed>  $record
     *
     * @throws RecordMappingException when the record is unusable
     */
    public function map(string $connectorKey, string $resource, array $record): MappedRecord;

    /**
     * The value that, passed back to the connector, resumes the pull after this record, or null when the record has none.
     * The importer keeps the greatest value (natural order) of the pulled records.
     *
     * @param  array<string, mixed>  $record
     */
    public function cursorOf(string $connectorKey, string $resource, array $record): ?string;
}
