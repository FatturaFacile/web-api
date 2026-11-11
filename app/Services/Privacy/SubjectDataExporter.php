<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * Collects every record of a data subject across the modules, using the personal data registry, and packs them in a
 * zip with one JSON document and one CSV per table (GDPR art. 15 and 20, nLPD art. 25 and 28).
 *
 * The export contains personal data: the caller must have verified the identity of the requester, log the access and
 * hand the file over through a safe channel. Technical secrets (passwords, tokens, search indexes) are never exported.
 */
final readonly class SubjectDataExporter
{
    /** Columns that are never exported, whatever the table. */
    public const array NeverExported = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'search_vector', 'search_text', 'email_verification_token'];

    public function __construct(private PersonalDataRegistry $registry, private SubjectResolver $resolver) {}

    /**
     * @return array{schema: string, regulations: list<string>, exported_at: string, subject_type: string, subject_id: string, tables: array<string, list<array<string, mixed>>>, summary: array<string, int>}
     */
    public function collect(string $tenantId, string $subjectType, string $subjectId): array
    {
        if (! in_array($subjectType, PersonalDataRegistry::subjectTypes(), true)) {
            throw new InvalidArgumentException("Unsupported subject_type [{$subjectType}].");
        }

        $this->resolver->resolve($tenantId, $subjectType, $subjectId);

        $tables = [];
        foreach ($this->sources($subjectType) as $table => $source) {
            $rows = $this->rows($table, $source['column'], $source['hasTenantColumn'], $tenantId, $subjectId);
            if ($rows !== []) {
                $tables[$table] = $rows;
            }
        }

        return [
            'schema' => 'urn:fatturafacile:privacy:export:1.0',
            'regulations' => ['GDPR Art. 15 & 20', 'Swiss nLPD Art. 25 & 28'],
            'exported_at' => CarbonImmutable::now()->toIso8601String(),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'tables' => $tables,
            'summary' => array_map('count', $tables),
        ];
    }

    /**
     * Builds the zip in a temporary file and returns its path. The caller deletes it after sending it.
     */
    public function zip(string $tenantId, string $subjectType, string $subjectId): string
    {
        $data = $this->collect($tenantId, $subjectType, $subjectId);
        $path = tempnam(sys_get_temp_dir(), 'dsr');
        if ($path === false) {
            throw new RuntimeException('Cannot create the export file.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot open the export archive.');
        }

        $zip->addFromString('export.json', (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
        foreach ($data['tables'] as $table => $rows) {
            $zip->addFromString("{$table}.csv", $this->csv($rows));
        }

        $zip->close();

        return $path;
    }

    /**
     * @return array<string, array{column: string, hasTenantColumn: bool}>
     */
    private function sources(string $subjectType): array
    {
        $sources = [];
        foreach ($this->registry->entriesForSubject($subjectType) as $entry) {
            $sources[$entry->table] = ['column' => $entry->subjectLinks[$subjectType], 'hasTenantColumn' => $entry->hasTenantColumn];
        }

        foreach ($this->registry->linkedRecords() as $table => $link) {
            if (isset($link['links'][$subjectType]) && ! isset($sources[$table])) {
                $sources[$table] = ['column' => $link['links'][$subjectType], 'hasTenantColumn' => $link['hasTenantColumn']];
            }
        }

        return $sources;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, string $column, bool $hasTenantColumn, string $tenantId, string $subjectId): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        $query = DB::table($table)->where($column, $subjectId);
        if ($hasTenantColumn) {
            $query->where('tenant_id', $tenantId);
        }

        $rows = [];
        foreach ($query->limit(5000)->get() as $row) {
            $rows[] = array_diff_key((array) $row, array_flip(self::NeverExported));
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Cannot build the CSV.');
        }

        $header = array_keys($rows[0]);
        fputcsv($handle, $header, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $name): string => $this->cell($row[$name] ?? null), $header), ',', '"', '\\');
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    /**
     * Cell value safe for spreadsheets: a leading formula character is neutralised (CSV injection).
     */
    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);

        return $text !== '' && ! is_numeric($text) && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }
}
