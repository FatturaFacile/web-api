<?php

declare(strict_types=1);

namespace Modules\DataMigration\Services;

use Modules\DataMigration\Domain\Enums\MigrationSourceSystem;
use Modules\DataMigration\Domain\Enums\MigrationTargetEntity;
use Modules\DataMigration\Models\MigrationFieldMappingTemplate;

/**
 * Analyzes uploaded headers and sample values against built-in connector presets and saved
 * MigrationFieldMappingTemplate profiles to propose column mappings and value transformations.
 */
final class SmartColumnMappingDetector
{
    public function __construct(private readonly ConnectorPresetRegistry $registry) {}

    /**
     * @param  list<string>  $headers
     * @param  array<string, list<string>>  $headersByEntity
     * @param  list<array<string, string>>  $sampleRows
     * @param  list<string>  $targetEntities
     * @return array{
     *     mappings_by_entity: array<string, array<string, array{source_column: ?string, required: bool, default_value: ?string, confidence: float}>>,
     *     value_transformations: array<string, mixed>,
     *     matched_template_id: ?string
     * }
     */
    public function detect(
        MigrationSourceSystem $sourceSystem,
        array $targetEntities,
        array $headers,
        array $headersByEntity,
        array $sampleRows,
        ?string $templateId = null,
    ): array {
        $mappingsByEntity = [];
        $matchedTemplateId = $templateId;
        $transformations = $this->registry->defaultTransformations($sourceSystem);

        foreach ($targetEntities as $entityName) {
            $entity = MigrationTargetEntity::tryFrom($entityName);
            if ($entity === null) {
                continue;
            }

            $entityHeaders = $headersByEntity[$entityName] ?? $headers;
            $template = $this->resolveTemplate($sourceSystem, $entity, $templateId);

            if ($template !== null) {
                $matchedTemplateId ??= $template->id;
                if (is_array($template->value_transformations_json) && $template->value_transformations_json !== []) {
                    $transformations = array_merge($transformations, $template->value_transformations_json);
                }
            }

            $mappingsByEntity[$entityName] = $this->detectForEntity(
                $sourceSystem,
                $entity,
                $entityHeaders,
                $sampleRows,
                $template,
            );
        }

        $transformations = $this->refineTransformationsFromSample($transformations, $sampleRows);

        return [
            'mappings_by_entity' => $mappingsByEntity,
            'value_transformations' => $transformations,
            'matched_template_id' => $matchedTemplateId,
        ];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, string>>  $sampleRows
     * @return array<string, array{source_column: ?string, required: bool, default_value: ?string, confidence: float}>
     */
    private function detectForEntity(
        MigrationSourceSystem $sourceSystem,
        MigrationTargetEntity $entity,
        array $headers,
        array $sampleRows,
        ?MigrationFieldMappingTemplate $template,
    ): array {
        $normalizedHeaders = [];
        foreach ($headers as $header) {
            $normalizedHeaders[$this->normalizeKey($header)] = $header;
        }

        $aliases = $this->registry->fieldAliases($entity, $sourceSystem);
        $templateMappings = $template?->column_mappings_json ?? [];
        $result = [];

        foreach ($entity->schemaFields() as $fieldDef) {
            $field = $fieldDef['field'];
            $required = (bool) $fieldDef['required'];
            $matchedHeader = null;
            $confidence = 0.0;
            $defaultValue = null;

            // 1. Check saved or system template match
            if (isset($templateMappings[$field]) && is_array($templateMappings[$field])) {
                $tplCol = (string) ($templateMappings[$field]['source_column'] ?? '');
                $defaultValue = isset($templateMappings[$field]['default_value']) ? (string) $templateMappings[$field]['default_value'] : null;
                $normTpl = $this->normalizeKey($tplCol);
                if ($tplCol !== '' && in_array($tplCol, $headers, true)) {
                    $matchedHeader = $tplCol;
                    $confidence = 1.0;
                } elseif ($normTpl !== '' && isset($normalizedHeaders[$normTpl])) {
                    $matchedHeader = $normalizedHeaders[$normTpl];
                    $confidence = 0.96;
                }
            }

            // 2. Check exact field name or normalized alias match
            if ($matchedHeader === null) {
                $fieldNorm = $this->normalizeKey($field);
                if (isset($normalizedHeaders[$fieldNorm])) {
                    $matchedHeader = $normalizedHeaders[$fieldNorm];
                    $confidence = 0.98;
                } else {
                    foreach ($aliases[$field] ?? [] as $alias) {
                        $normAlias = $this->normalizeKey($alias);
                        if (isset($normalizedHeaders[$normAlias])) {
                            $matchedHeader = $normalizedHeaders[$normAlias];
                            $confidence = 0.92;
                            break;
                        }
                    }
                }
            }

            // 3. Heuristic sample-value match when header name is non-standard
            if ($matchedHeader === null && $sampleRows !== []) {
                [$heuristicHeader, $heuristicConfidence] = $this->guessFromSamples($fieldDef['type'], $headers, $sampleRows);
                if ($heuristicHeader !== null) {
                    $matchedHeader = $heuristicHeader;
                    $confidence = $heuristicConfidence;
                }
            }

            $result[$field] = [
                'source_column' => $matchedHeader,
                'required' => $required,
                'default_value' => $defaultValue,
                'confidence' => $confidence,
            ];
        }

        return $result;
    }

    private function resolveTemplate(
        MigrationSourceSystem $sourceSystem,
        MigrationTargetEntity $entity,
        ?string $templateId,
    ): ?MigrationFieldMappingTemplate {
        if ($templateId !== null && $templateId !== '') {
            $explicit = MigrationFieldMappingTemplate::query()->whereKey($templateId)->first();
            if ($explicit !== null && $explicit->entity_type === $entity) {
                return $explicit;
            }
        }

        // Prefer tenant-specific template over global system preset
        $tenantTemplate = MigrationFieldMappingTemplate::query()
            ->where('source_system', $sourceSystem->value)
            ->where('entity_type', $entity->value)
            ->whereNotNull('tenant_id')
            ->latest('updated_at')
            ->first();

        if ($tenantTemplate !== null) {
            return $tenantTemplate;
        }

        return MigrationFieldMappingTemplate::query()
            ->where('source_system', $sourceSystem->value)
            ->where('entity_type', $entity->value)
            ->whereNull('tenant_id')
            ->first();
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, string>>  $sampleRows
     * @return array{0: ?string, 1: float}
     */
    private function guessFromSamples(string $type, array $headers, array $sampleRows): array
    {
        $pattern = match ($type) {
            'email' => '/^[^@\s]+@[^@\s]+\.[^@\s]+$/',
            'ch_uid' => '/^CHE[\s.\-]?\d{3}[\s.\-]?\d{3}[\s.\-]?\d{3}/i',
            'iban', 'qr_iban' => '/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/i',
            'country_iso2' => '/^[A-Z]{2}$/',
            'currency' => '/^(EUR|CHF|USD|GBP)$/i',
            default => null,
        };

        if ($pattern === null) {
            return [null, 0.0];
        }

        foreach ($headers as $header) {
            $nonEmpty = 0;
            $matches = 0;
            foreach ($sampleRows as $row) {
                $val = trim((string) ($row[$header] ?? ''));
                if ($val === '') {
                    continue;
                }
                $nonEmpty++;
                if (preg_match($pattern, str_replace(' ', '', $val)) === 1) {
                    $matches++;
                }
            }

            if ($nonEmpty > 0 && $matches === $nonEmpty) {
                return [$header, 0.78];
            }
        }

        return [null, 0.0];
    }

    /**
     * @param  array<string, mixed>  $transformations
     * @param  list<array<string, string>>  $sampleRows
     * @return array<string, mixed>
     */
    private function refineTransformationsFromSample(array $transformations, array $sampleRows): array
    {
        foreach ($sampleRows as $row) {
            foreach ($row as $value) {
                $v = trim((string) $value);
                if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $v) === 1) {
                    $transformations['date_format'] = 'd.m.Y';
                } elseif (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $v) === 1) {
                    $transformations['date_format'] = 'd/m/Y';
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1) {
                    $transformations['date_format'] = 'Y-m-d';
                }

                if (preg_match("/^\d{1,3}(?:'\d{3})+\.\d{2}$/", $v) === 1) {
                    $transformations['thousand_separator'] = "'";
                    $transformations['decimal_separator'] = '.';
                } elseif (preg_match('/^\d{1,3}(?:\.\d{3})+,\d{2}$/', $v) === 1) {
                    $transformations['thousand_separator'] = '.';
                    $transformations['decimal_separator'] = ',';
                }
            }
        }

        return $transformations;
    }

    private function normalizeKey(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($key)));
    }
}
