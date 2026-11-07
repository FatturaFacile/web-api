<?php

declare(strict_types=1);

namespace Modules\Customs\Services;

use Modules\Customs\Enums\DeclarationType;
use Modules\Customs\Models\CustomsCountry;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Models\CustomsDeclarationItem;
use Modules\Customs\Models\CustomsDocumentType;
use Modules\Customs\Models\CustomsMasterRecord;
use Modules\Customs\Models\CustomsPackagingType;
use Modules\Customs\Models\CustomsSettings;
use Modules\Customs\Models\CustomsStatisticalKey;
use Modules\Customs\Models\CustomsTariffNumber;
use Modules\Customs\Services\Edec\EdecDeclarationXmlBuilder;
use Modules\Customs\Services\Edec\EdecSchemaValidator;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Checks a declaration before it leaves the application. Field presence and shape come from the e-dec schema, the codes
 * from the code tables valid on the day. A code table still empty gives a warning, not an error: BAZG decides then.
 * The BAZG plausibility rules are not replicated.
 */
final class CustomsDeclarationValidator
{
    public function __construct(
        private readonly EdecDeclarationXmlBuilder $builder,
        private readonly EdecSchemaValidator $schema,
    ) {}

    /**
     * @return array{errors: list<array{field: string, message: string}>, warnings: list<string>}
     */
    public function check(CustomsDeclaration $declaration, ?CustomsSettings $settings): array
    {
        $errors = [];
        $warnings = [];

        $this->checkHeader($declaration, $errors);
        $this->checkItems($declaration, $errors, $warnings);

        if ($declaration->declaration_type !== DeclarationType::Transit) {
            if ($settings === null || (string) $settings->trader_identification_number === '') {
                $errors[] = ['field' => 'settings', 'message' => 'The customs settings of the company lack the declarant.'];
            } else {
                foreach ($this->schema->errors($this->builder->build($declaration, $settings), EdecSchemaValidator::DECLARATION) as $message) {
                    $errors[] = ['field' => 'schema', 'message' => $message];
                }
            }
        }

        return ['errors' => $errors, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * @param  list<array{field: string, message: string}>  $errors
     */
    private function checkHeader(CustomsDeclaration $declaration, array &$errors): void
    {
        $required = match ($declaration->declaration_type) {
            DeclarationType::Import => ['consignee_contact_id', 'dispatch_country'],
            DeclarationType::Export => ['consignee_contact_id', 'destination_country'],
            DeclarationType::Transit => ['customs_office_number', 'dispatch_country', 'destination_country'],
        };

        foreach ($required as $field) {
            if ($declaration->getAttribute($field) === null) {
                $errors[] = ['field' => $field, 'message' => "{$field} is required for a {$declaration->declaration_type->value} declaration."];
            }
        }

        foreach (['dispatch_country', 'destination_country', 'transportation_country'] as $field) {
            $code = $declaration->getAttribute($field);

            if ($code !== null && $this->tableHasRows(CustomsCountry::class) && ! $this->isValid(CustomsCountry::class, $code, $declaration)) {
                $errors[] = ['field' => $field, 'message' => "Country {$code} is not in the customs country table."];
            }
        }
    }

    /**
     * @param  list<array{field: string, message: string}>  $errors
     * @param  list<string>  $warnings
     */
    private function checkItems(CustomsDeclaration $declaration, array &$errors, array &$warnings): void
    {
        if ($declaration->items->isEmpty()) {
            $errors[] = ['field' => 'items', 'message' => 'A declaration needs at least one line.'];
        }

        foreach ($declaration->items as $item) {
            $line = "items.{$item->line_number}";

            if (! Decimal::isPositive((string) $item->gross_mass)) {
                $errors[] = ['field' => "{$line}.gross_mass", 'message' => 'The gross mass must be positive.'];
            }

            if (Decimal::compare((string) $item->net_mass, (string) $item->gross_mass) > 0) {
                $errors[] = ['field' => "{$line}.net_mass", 'message' => 'The net mass cannot exceed the gross mass.'];
            }

            if ($declaration->declaration_type === DeclarationType::Import && $item->origin_country === null) {
                $errors[] = ['field' => "{$line}.origin_country", 'message' => 'The country of origin is required on an import.'];
            }

            $this->checkItemCodes($declaration, $item, $line, $errors, $warnings);
        }
    }

    /**
     * @param  list<array{field: string, message: string}>  $errors
     * @param  list<string>  $warnings
     */
    private function checkItemCodes(CustomsDeclaration $declaration, CustomsDeclarationItem $item, string $line, array &$errors, array &$warnings): void
    {
        $checks = [
            'commodity_code' => [CustomsTariffNumber::class, $item->commodity_code],
            'packaging_type' => [CustomsPackagingType::class, $item->packaging_type],
            'origin_country' => [CustomsCountry::class, $item->origin_country],
        ];

        foreach ($checks as $field => [$model, $code]) {
            if ($code === null) {
                continue;
            }

            if (! $this->tableHasRows($model)) {
                $warnings[] = 'The table '.class_basename($model).' is empty: its codes were not checked.';
            } elseif (! $this->isValid($model, (string) $code, $declaration)) {
                $errors[] = ['field' => "{$line}.{$field}", 'message' => "Code {$code} is not valid on the declaration date."];
            }
        }

        if ($item->statistical_code !== null && $this->tableHasRows(CustomsStatisticalKey::class)) {
            $known = CustomsStatisticalKey::query()->where('tariff_code', $item->commodity_code)->where('code', (string) $item->statistical_code)->exists();

            if (! $known) {
                $errors[] = ['field' => "{$line}.statistical_code", 'message' => "Statistical key {$item->statistical_code} does not belong to tariff number {$item->commodity_code}."];
            }
        }

        foreach ($item->produced_documents ?? [] as $produced) {
            $code = (string) ($produced['document_type'] ?? '');

            if ($code !== '' && $this->tableHasRows(CustomsDocumentType::class) && ! $this->isValid(CustomsDocumentType::class, $code, $declaration)) {
                $errors[] = ['field' => "{$line}.produced_documents", 'message' => "Document type {$code} is not valid on the declaration date."];
            }
        }
    }

    /**
     * @param  class-string<CustomsMasterRecord>  $model
     */
    private function tableHasRows(string $model): bool
    {
        return $model::query()->exists();
    }

    /**
     * @param  class-string<CustomsMasterRecord>  $model
     */
    private function isValid(string $model, string $code, CustomsDeclaration $declaration): bool
    {
        return $model::query()->where('code', $code)->validOn(($declaration->declared_on ?? now())->toDateString())->exists();
    }
}
