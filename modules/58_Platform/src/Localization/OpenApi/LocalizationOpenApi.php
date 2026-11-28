<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses and payloads of the localization and i18n endpoints (Mod 002, issue #331). Every endpoint answers the
 * {success, message, data, meta} envelope; errors are RFC 7807 Problem Details. Amounts travel as decimal strings
 * ("1234.50") and dates as Y-m-d: the regional formatting (1'234.50, 31.12.2026) is a presentation concern of the clients.
 */
#[OA\Tag(name: 'Localization', description: 'Multi-language UI dictionary (ETag cached), per-company translation overrides with spreadsheet import/export, locale settings, master data translations and the frozen print language of documents. Locales: it-CH, de-CH, fr-CH, it-IT, en. A locale resolves as user preference, Accept-Language, company default, en; a text as company override (exact locale, then language), system text (exact locale, then language), system English, the key itself.')]
#[OA\Response(response: 'LocalizationUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'LocalizationForbidden', description: 'Only owners and administrators can change translations; a locked statutory key cannot be changed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'LocalizationNotFound', description: 'Record not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'LocalizationUnprocessable', description: 'Validation error, in the language of the request', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'LocalizationDictionary', type: 'object', properties: [
    new OA\Property(property: 'locale', type: 'string', example: 'de-CH'),
    new OA\Property(property: 'version_hash', type: 'string', example: '3f1c9a0b5d7e4c21a8b6f09d1e2c3a4b'),
    new OA\Property(property: 'entries', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'), example: ['invoicing.title' => 'Rechnung']),
])]
#[OA\Schema(schema: 'LocalizationDictionaryResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean'),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', ref: '#/components/schemas/LocalizationDictionary'),
    new OA\Property(property: 'meta', type: 'object'),
])]
#[OA\Schema(schema: 'LocalizationProfileLocaleResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean'),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', properties: [new OA\Property(property: 'locale', type: 'string', example: 'fr-CH')], type: 'object'),
    new OA\Property(property: 'meta', type: 'object'),
])]
#[OA\Schema(schema: 'LocalizationAvailableLocale', type: 'object', properties: [
    new OA\Property(property: 'code', type: 'string', example: 'de-CH'),
    new OA\Property(property: 'language', type: 'string', example: 'de'),
    new OA\Property(property: 'region', type: 'string', nullable: true, example: 'CH'),
    new OA\Property(property: 'currency', type: 'string', example: 'CHF'),
    new OA\Property(property: 'ng_zorro_locale', type: 'string', example: 'de_DE'),
])]
#[OA\Schema(schema: 'LocalizationSettingsPayload', type: 'object', required: ['active_locales', 'default_locale'], properties: [
    new OA\Property(property: 'active_locales', type: 'array', items: new OA\Items(type: 'string'), example: ['it-CH', 'de-CH', 'en']),
    new OA\Property(property: 'default_locale', type: 'string', example: 'it-CH'),
    new OA\Property(property: 'number_format', type: 'object', nullable: true, description: 'Override of the separators of the locale', properties: [
        new OA\Property(property: 'decimal_separator', type: 'string', enum: ['.', ',']),
        new OA\Property(property: 'thousands_separator', type: 'string', enum: ["'", '.', ',', ' ', '']),
    ]),
    new OA\Property(property: 'date_format', type: 'string', nullable: true, enum: ['dd.MM.yyyy', 'dd/MM/yyyy', 'yyyy-MM-dd']),
])]
#[OA\Schema(schema: 'LocalizationSettings', type: 'object', allOf: [new OA\Schema(ref: '#/components/schemas/LocalizationSettingsPayload')], properties: [
    new OA\Property(property: 'available_locales', type: 'array', items: new OA\Items(ref: '#/components/schemas/LocalizationAvailableLocale')),
])]
#[OA\Schema(schema: 'LocalizationSettingsResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean'),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', ref: '#/components/schemas/LocalizationSettings'),
    new OA\Property(property: 'meta', type: 'object'),
])]
#[OA\Schema(schema: 'LocalizationTranslation', type: 'object', properties: [
    new OA\Property(property: 'key', type: 'string', example: 'invoicing.title'),
    new OA\Property(property: 'group', type: 'string', example: 'invoicing'),
    new OA\Property(property: 'locale', type: 'string', example: 'de-CH'),
    new OA\Property(property: 'value', type: 'string', description: 'Effective text; the key itself when no text exists'),
    new OA\Property(property: 'system_value', type: 'string', nullable: true, description: 'Text without the company override'),
    new OA\Property(property: 'is_overridden', type: 'boolean'),
    new OA\Property(property: 'is_locked', type: 'boolean', description: 'Statutory system key: immutable through the tenant APIs'),
    new OA\Property(property: 'is_deprecated', type: 'boolean'),
    new OA\Property(property: 'is_missing', type: 'boolean', description: 'No text in the locale nor in its language'),
])]
#[OA\Schema(schema: 'LocalizationTranslationResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean'),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', ref: '#/components/schemas/LocalizationTranslation'),
    new OA\Property(property: 'meta', type: 'object'),
])]
#[OA\Schema(schema: 'LocalizationTranslationPageResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean'),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LocalizationTranslation')),
    new OA\Property(property: 'meta', type: 'object', properties: [
        new OA\Property(property: 'total', type: 'integer'),
        new OA\Property(property: 'current_page', type: 'integer'),
        new OA\Property(property: 'per_page', type: 'integer'),
        new OA\Property(property: 'last_page', type: 'integer'),
        new OA\Property(property: 'missing_count', type: 'integer'),
        new OA\Property(property: 'groups', type: 'array', items: new OA\Items(type: 'string')),
    ]),
])]
#[OA\Schema(schema: 'LocalizationImportRow', type: 'object', properties: [
    new OA\Property(property: 'row', type: 'integer'),
    new OA\Property(property: 'key', type: 'string'),
    new OA\Property(property: 'locale', type: 'string'),
    new OA\Property(property: 'action', type: 'string', enum: ['create', 'update', 'unchanged', 'rejected', 'blocked']),
    new OA\Property(property: 'old_value', type: 'string', nullable: true),
    new OA\Property(property: 'new_value', type: 'string'),
    new OA\Property(property: 'errors', type: 'array', items: new OA\Items(type: 'string')),
])]
#[OA\Schema(schema: 'LocalizationImportResult', type: 'object', properties: [
    new OA\Property(property: 'dry_run', type: 'boolean'),
    new OA\Property(property: 'has_errors', type: 'boolean'),
    new OA\Property(property: 'summary', type: 'object', properties: [
        new OA\Property(property: 'created', type: 'integer'),
        new OA\Property(property: 'updated', type: 'integer'),
        new OA\Property(property: 'unchanged', type: 'integer'),
        new OA\Property(property: 'rejected', type: 'integer'),
        new OA\Property(property: 'blocked', type: 'integer'),
    ]),
    new OA\Property(property: 'header_errors', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'rows', type: 'array', items: new OA\Items(ref: '#/components/schemas/LocalizationImportRow')),
])]
#[OA\Schema(schema: 'LocalizationImportResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean'),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', ref: '#/components/schemas/LocalizationImportResult'),
    new OA\Property(property: 'meta', type: 'object'),
])]
#[OA\Schema(schema: 'LocalizationResourceLocale', type: 'object', properties: [
    new OA\Property(property: 'locale', type: 'string', example: 'de-CH'),
    new OA\Property(property: 'values', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string', nullable: true), example: ['name' => 'Beratung', 'description' => null]),
    new OA\Property(property: 'filled', type: 'integer'),
    new OA\Property(property: 'total', type: 'integer'),
    new OA\Property(property: 'percent', type: 'integer'),
])]
#[OA\Schema(schema: 'LocalizationResourceTranslations', type: 'object', properties: [
    new OA\Property(property: 'resource', type: 'string', example: 'products'),
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'fields', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'active_locales', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'locales', type: 'array', items: new OA\Items(ref: '#/components/schemas/LocalizationResourceLocale')),
    new OA\Property(property: 'completeness', type: 'object', properties: [
        new OA\Property(property: 'filled', type: 'integer'),
        new OA\Property(property: 'total', type: 'integer'),
        new OA\Property(property: 'percent', type: 'integer'),
    ]),
])]
#[OA\Schema(schema: 'LocalizationResourceTranslationsResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean'),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', ref: '#/components/schemas/LocalizationResourceTranslations'),
    new OA\Property(property: 'meta', type: 'object'),
])]
#[OA\Schema(schema: 'LocalizationDocumentLocale', type: 'object', properties: [
    new OA\Property(property: 'type', type: 'string', example: 'invoice'),
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'locale', type: 'string', nullable: true, example: 'de-CH'),
    new OA\Property(property: 'is_editable', type: 'boolean', description: 'true while the document is a draft'),
])]
#[OA\Schema(schema: 'LocalizationDocumentLocaleResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean'),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', ref: '#/components/schemas/LocalizationDocumentLocale'),
    new OA\Property(property: 'meta', type: 'object'),
])]
final class LocalizationOpenApi {}
