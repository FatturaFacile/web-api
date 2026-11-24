<?php

declare(strict_types=1);

namespace Modules\Intrastat\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the INTRA lists endpoints.
 */
#[OA\Tag(name: 'Intrastat', description: 'INTRA lists (elenchi riepilogativi Intrastat): INTRA-1 bis/ter/quater/quinquies for sales of goods and services rendered, INTRA-2 bis/ter/quater/quinquies for purchases of goods and services received; drafting from invoices, validation, telematic file')]
#[OA\Response(response: 'IntrastatUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'IntrastatForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'IntrastatNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'IntrastatUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'IntrastatDeclarationRequest', description: 'Frontespizio of a list. flow, year, periodicity and period are required on creation; flow cannot change.', type: 'object', properties: [
    new OA\Property(property: 'flow', type: 'string', enum: ['sales', 'purchases']),
    new OA\Property(property: 'year', type: 'integer', minimum: 2022, example: 2027),
    new OA\Property(property: 'periodicity', description: 'M monthly, T quarterly (purchases: M only)', type: 'string', enum: ['M', 'T']),
    new OA\Property(property: 'period', description: 'Month 1-12 or quarter 1-4', type: 'integer'),
    new OA\Property(property: 'reference_number', description: 'Progressive number of the presenter, unique across years; next of the company when omitted', type: 'integer', nullable: true),
    new OA\Property(property: 'content_code', description: '0 whole period; 8 or 9 quarterly list after a change of periodicity', type: 'integer', enum: [0, 8, 9]),
    new OA\Property(property: 'special_case_code', description: '7 first list, 8 cessation or change of VAT number, 9 both, 0 none', type: 'integer', enum: [0, 7, 8, 9]),
    new OA\Property(property: 'obligated_vat_number', description: '11 digits; the VAT number of the company when omitted', type: 'string', nullable: true),
    new OA\Property(property: 'delegate_vat_number', type: 'string', nullable: true),
    new OA\Property(property: 'includes_statistical_data', description: 'Monthly INTRA-1 bis with the statistical columns', type: 'boolean'),
    new OA\Property(property: 'has_annual_flows_above_20_million', description: 'Party of art. 6 c. 4 DM 22/02/2010: natura B, statistical value, delivery terms and transport mode required', type: 'boolean'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'IntrastatGenerateRequest', type: 'object', required: ['flow', 'year', 'periodicity', 'period'], allOf: [new OA\Schema(ref: '#/components/schemas/IntrastatDeclarationRequest')], properties: [
    new OA\Property(property: 'unclassified_as', description: 'How to treat invoice lines that cannot be classified; reported as unclassified when omitted', type: 'string', enum: ['goods', 'services'], nullable: true),
    new OA\Property(property: 'defaults', description: 'Values for columns the invoices do not hold', type: 'object', properties: [
        new OA\Property(property: 'transaction_nature_a', type: 'string', nullable: true),
        new OA\Property(property: 'transaction_nature_b', type: 'string', nullable: true),
        new OA\Property(property: 'delivery_terms', type: 'string', enum: ['E', 'F', 'C', 'D'], nullable: true),
        new OA\Property(property: 'transport_mode', type: 'string', enum: ['1', '2', '3', '4', '5', '7', '8', '9'], nullable: true),
        new OA\Property(property: 'province_code', description: 'Province of origin (sales) or destination (purchases)', type: 'string', nullable: true),
        new OA\Property(property: 'payment_country_code', type: 'string', nullable: true),
        new OA\Property(property: 'service_code', description: 'CPA code, 6 digits', type: 'string', nullable: true),
    ]),
])]
#[OA\Schema(schema: 'IntrastatLineRequest', description: 'A detail row. section is required on creation and never changes.', type: 'object', properties: [
    new OA\Property(property: 'section', type: 'integer', enum: [1, 2, 3, 4]),
    new OA\Property(property: 'partner_country_code', type: 'string', example: 'DE', nullable: true),
    new OA\Property(property: 'partner_vat_number', description: 'Without the country code', type: 'string', example: '123456789', nullable: true),
    new OA\Property(property: 'amount_eur', type: 'integer', nullable: true),
    new OA\Property(property: 'amount_currency', type: 'integer', nullable: true),
    new OA\Property(property: 'transaction_nature_a', type: 'string', nullable: true),
    new OA\Property(property: 'transaction_nature_b', type: 'string', nullable: true),
    new OA\Property(property: 'cn8_code', type: 'string', example: '84212100', nullable: true),
    new OA\Property(property: 'net_mass_kg', type: 'integer', nullable: true),
    new OA\Property(property: 'supplementary_units', type: 'integer', nullable: true),
    new OA\Property(property: 'statistical_value_eur', type: 'integer', nullable: true),
    new OA\Property(property: 'delivery_terms', type: 'string', nullable: true),
    new OA\Property(property: 'transport_mode', type: 'string', nullable: true),
    new OA\Property(property: 'goods_country_code', description: 'Country of destination (sales) or provenance (purchases)', type: 'string', nullable: true),
    new OA\Property(property: 'origin_country_code', type: 'string', nullable: true),
    new OA\Property(property: 'province_code', description: 'Province of origin (sales) or destination (purchases)', type: 'string', nullable: true),
    new OA\Property(property: 'invoice_number', type: 'string', maxLength: 15, nullable: true),
    new OA\Property(property: 'invoice_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'service_code', type: 'string', nullable: true),
    new OA\Property(property: 'supply_mode', type: 'string', enum: ['I', 'R'], nullable: true),
    new OA\Property(property: 'payment_method', type: 'string', enum: ['B', 'A', 'X'], nullable: true),
    new OA\Property(property: 'payment_country_code', type: 'string', nullable: true),
    new OA\Property(property: 'corrected_month', type: 'integer', nullable: true),
    new OA\Property(property: 'corrected_quarter', type: 'integer', nullable: true),
    new OA\Property(property: 'corrected_year', type: 'integer', nullable: true),
    new OA\Property(property: 'sign', type: 'string', enum: ['+', '-'], nullable: true),
    new OA\Property(property: 'corrected_customs_section', type: 'string', nullable: true),
    new OA\Property(property: 'corrected_protocol_year', type: 'integer', nullable: true),
    new OA\Property(property: 'corrected_protocol_number', type: 'string', nullable: true),
    new OA\Property(property: 'corrected_line_number', type: 'integer', nullable: true),
    new OA\Property(property: 'is_cancellation', type: 'boolean'),
])]
#[OA\Schema(schema: 'IntrastatSubmitRequest', type: 'object', required: ['protocol_number'], properties: [
    new OA\Property(property: 'protocol_number', type: 'string', maxLength: 30),
    new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'IntrastatExportRequest', type: 'object', required: ['declaration_ids', 'channel', 'customs_section_code'], properties: [
    new OA\Property(property: 'declaration_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
    new OA\Property(property: 'channel', type: 'string', enum: ['entratel', 'customs']),
    new OA\Property(property: 'user_code', description: 'Required for the customs channel: 4 characters of the authorisation', type: 'string', nullable: true),
    new OA\Property(property: 'customs_section_code', description: 'Customs section where the lists are filed', type: 'string'),
    new OA\Property(property: 'sender_code', description: 'Tax code or VAT number of the sender; the presenter of the first list when omitted', type: 'string', nullable: true),
    new OA\Property(property: 'site_progressive', description: 'Required for the customs channel; always 1 through Entratel', type: 'integer', nullable: true),
    new OA\Property(property: 'transmission_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'sequence', description: 'Progressive of the send within the day, 2 characters; 01 when omitted', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'IntrastatProductProfileRequest', type: 'object', required: ['supply_kind'], properties: [
    new OA\Property(property: 'supply_kind', type: 'string', enum: ['goods', 'services']),
    new OA\Property(property: 'cn8_code', type: 'string', nullable: true),
    new OA\Property(property: 'net_mass_kg', description: 'Per unit sold', type: 'number', nullable: true),
    new OA\Property(property: 'supplementary_units_per_unit', type: 'number', nullable: true),
    new OA\Property(property: 'service_code', type: 'string', nullable: true),
    new OA\Property(property: 'origin_country_code', type: 'string', nullable: true),
    new OA\Property(property: 'origin_province_code', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'IntrastatDeclarationLine', type: 'object', allOf: [new OA\Schema(ref: '#/components/schemas/IntrastatLineRequest')], properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'line_number', type: 'integer'),
    new OA\Property(property: 'source_documents', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['invoice', 'passive_invoice']),
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'number', type: 'string', nullable: true),
        new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
    ])),
])]
#[OA\Schema(schema: 'IntrastatDeclaration', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'flow', type: 'string', enum: ['sales', 'purchases']),
    new OA\Property(property: 'model', type: 'string', enum: ['INTRA-1', 'INTRA-2']),
    new OA\Property(property: 'year', type: 'integer'),
    new OA\Property(property: 'periodicity', type: 'string', enum: ['M', 'T']),
    new OA\Property(property: 'period', type: 'integer'),
    new OA\Property(property: 'reference_number', type: 'integer'),
    new OA\Property(property: 'content_code', type: 'integer'),
    new OA\Property(property: 'special_case_code', type: 'integer'),
    new OA\Property(property: 'obligated_vat_number', type: 'string', nullable: true),
    new OA\Property(property: 'delegate_vat_number', type: 'string', nullable: true),
    new OA\Property(property: 'includes_statistical_data', type: 'boolean'),
    new OA\Property(property: 'has_annual_flows_above_20_million', type: 'boolean'),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'validated', 'submitted']),
    new OA\Property(property: 'validated_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'protocol_number', type: 'string', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'last_export_file_name', type: 'string', nullable: true),
    new OA\Property(property: 'last_exported_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'generation_report', description: 'Present on generated lists: period, documents read, skipped and unclassified documents, warnings', type: 'object', nullable: true),
    new OA\Property(property: 'sections', description: 'Count and total in euro of sections 1 to 4, in order', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'section', type: 'integer'),
        new OA\Property(property: 'count', type: 'integer'),
        new OA\Property(property: 'total_eur', description: 'Signed for section 2', type: 'integer'),
    ])),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/IntrastatDeclarationLine')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'IntrastatProductProfile', type: 'object', allOf: [new OA\Schema(ref: '#/components/schemas/IntrastatProductProfileRequest')], properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'product_name', type: 'string', nullable: true),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'IntrastatValidationError', type: 'object', properties: [
    new OA\Property(property: 'declaration_id', description: 'Only in the answer of the export', type: 'string', format: 'uuid'),
    new OA\Property(property: 'line_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'section', type: 'integer', nullable: true),
    new OA\Property(property: 'line_number', type: 'integer', nullable: true),
    new OA\Property(property: 'field', type: 'string'),
    new OA\Property(property: 'message', type: 'string'),
])]
#[OA\Schema(schema: 'IntrastatValidationProblem', description: 'RFC 7807 problem with the errors of the list', type: 'object', properties: [
    new OA\Property(property: 'type', type: 'string'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'status', type: 'integer', example: 422),
    new OA\Property(property: 'detail', type: 'string'),
    new OA\Property(property: 'instance', type: 'string'),
    new OA\Property(property: 'invalid_params', type: 'array', items: new OA\Items(ref: '#/components/schemas/IntrastatValidationError')),
])]
#[OA\Schema(schema: 'IntrastatValidationResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', ref: '#/components/schemas/IntrastatDeclaration'),
])]
#[OA\Schema(schema: 'IntrastatPeriodicityResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'quarter', type: 'integer'),
        new OA\Property(property: 'quarters_measured', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'sales_goods', type: 'object'),
        new OA\Property(property: 'sales_services', type: 'object'),
        new OA\Property(property: 'purchases_goods', type: 'object'),
        new OA\Property(property: 'purchases_services', type: 'object'),
        new OA\Property(property: 'annual_flows_above_20_million', type: 'object'),
        new OA\Property(property: 'unclassified_totals', type: 'object'),
        new OA\Property(property: 'notes', type: 'array', items: new OA\Items(type: 'string')),
    ]),
])]
#[OA\Schema(schema: 'IntrastatCodesResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', type: 'object'),
])]
#[OA\Schema(schema: 'IntrastatDeclarationResponse', type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/IntrastatDeclaration')])]
#[OA\Schema(schema: 'IntrastatDeclarationCollection', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/IntrastatDeclaration'))])]
#[OA\Schema(schema: 'IntrastatDeclarationLineResponse', type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/IntrastatDeclarationLine')])]
#[OA\Schema(schema: 'IntrastatProductProfileResponse', type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/IntrastatProductProfile')])]
#[OA\Schema(schema: 'IntrastatProductProfileCollection', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/IntrastatProductProfile'))])]
final class IntrastatOpenApi {}
