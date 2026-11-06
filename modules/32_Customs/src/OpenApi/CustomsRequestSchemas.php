<?php

declare(strict_types=1);

namespace Modules\Customs\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request payloads of the customs endpoints, derived from their form requests.
 */
#[OA\Schema(schema: 'CustomsMasterDataRequest', type: 'object', required: ['code'], properties: [
    new OA\Property(property: 'code', type: 'string', maxLength: 12),
    new OA\Property(property: 'tariff_code', description: 'Only for statistical keys', type: 'string', maxLength: 12, nullable: true),
    new OA\Property(property: 'name_de', type: 'string', nullable: true),
    new OA\Property(property: 'name_fr', type: 'string', nullable: true),
    new OA\Property(property: 'name_it', type: 'string', nullable: true),
    new OA\Property(property: 'name_en', type: 'string', nullable: true),
    new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'CustomsSettingsRequest', type: 'object', properties: [
    new OA\Property(property: 'channel', type: 'string', enum: ['local', 'edec']),
    new OA\Property(property: 'auto_retrieve', description: 'Download the decisions every night', type: 'boolean'),
    new OA\Property(property: 'trader_identification_number', type: 'string', maxLength: 17, nullable: true),
    new OA\Property(property: 'declarant_number', type: 'integer', nullable: true),
    new OA\Property(property: 'declarant_name', type: 'string', maxLength: 35, nullable: true),
    new OA\Property(property: 'declarant_street', type: 'string', maxLength: 35, nullable: true),
    new OA\Property(property: 'declarant_postal_code', type: 'string', maxLength: 9, nullable: true),
    new OA\Property(property: 'declarant_city', type: 'string', maxLength: 35, nullable: true),
    new OA\Property(property: 'declarant_country', type: 'string', maxLength: 2, nullable: true),
    new OA\Property(property: 'customs_account', type: 'string', maxLength: 8, nullable: true),
    new OA\Property(property: 'vat_account', type: 'string', maxLength: 8, nullable: true),
    new OA\Property(property: 'vat_number', type: 'string', maxLength: 12, nullable: true),
    new OA\Property(property: 'receipt_role', description: 'Role in the receipt request: declarant 0, account holder 1, consignor 2', type: 'integer', nullable: true),
    new OA\Property(property: 'duty_expense_account_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'customs_payable_account_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'import_vat_account_id', description: 'Must be an input VAT account of the VAT settings', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'CustomsDeclarationItemRequest', type: 'object', required: ['description', 'commodity_code'], properties: [
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'trader_item_id', type: 'string', maxLength: 16, nullable: true),
    new OA\Property(property: 'description', type: 'string', maxLength: 280),
    new OA\Property(property: 'commodity_code', type: 'string', maxLength: 10),
    new OA\Property(property: 'statistical_code', type: 'integer', nullable: true),
    new OA\Property(property: 'gross_mass', type: 'number', nullable: true),
    new OA\Property(property: 'net_mass', type: 'number', nullable: true),
    new OA\Property(property: 'additional_unit', type: 'number', nullable: true),
    new OA\Property(property: 'customs_clearance_type', type: 'integer', nullable: true),
    new OA\Property(property: 'commercial_good', type: 'integer', enum: [1, 2], nullable: true),
    new OA\Property(property: 'statistical_value', type: 'number', nullable: true),
    new OA\Property(property: 'vat_value', type: 'number', nullable: true),
    new OA\Property(property: 'vat_code', type: 'integer', nullable: true),
    new OA\Property(property: 'duty_rate', type: 'number', nullable: true),
    new OA\Property(property: 'origin_country', type: 'string', maxLength: 2, nullable: true),
    new OA\Property(property: 'preference', type: 'boolean', nullable: true),
    new OA\Property(property: 'packaging_type', type: 'string', maxLength: 3, nullable: true),
    new OA\Property(property: 'package_quantity', type: 'integer', nullable: true),
    new OA\Property(property: 'packaging_reference', type: 'string', maxLength: 42, nullable: true),
    new OA\Property(property: 'produced_documents', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'document_type', type: 'string', maxLength: 4),
        new OA\Property(property: 'reference', type: 'string', maxLength: 35, nullable: true),
        new OA\Property(property: 'issue_date', type: 'string', format: 'date', nullable: true),
    ]), nullable: true),
])]
#[OA\Schema(schema: 'CustomsDeclarationRequest', type: 'object', required: ['declaration_type'], properties: [
    new OA\Property(property: 'declaration_type', description: 'Only on creation', type: 'string', enum: ['import', 'export', 'transit']),
    new OA\Property(property: 'trader_declaration_number', description: 'Numbered automatically when omitted', type: 'string', maxLength: 22),
    new OA\Property(property: 'trader_reference', type: 'string', maxLength: 35, nullable: true),
    new OA\Property(property: 'dossier_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'delivery_note_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'consignor_contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'consignee_contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'importer_contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'clearance_location', description: 'BAZG domain clearanceLocation', type: 'integer', nullable: true),
    new OA\Property(property: 'declaration_time', description: 'BAZG domain declarationTime', type: 'integer', nullable: true),
    new OA\Property(property: 'declaration_kind', description: 'BAZG domain declarationType', type: 'integer', nullable: true),
    new OA\Property(property: 'language', type: 'string', enum: ['de', 'fr', 'it', 'en']),
    new OA\Property(property: 'customs_office_number', type: 'string', maxLength: 8, nullable: true),
    new OA\Property(property: 'dispatch_country', type: 'string', maxLength: 2, nullable: true),
    new OA\Property(property: 'destination_country', type: 'string', maxLength: 2, nullable: true),
    new OA\Property(property: 'transport_mode', description: 'BAZG domain transportMode', type: 'integer', nullable: true),
    new OA\Property(property: 'transportation_type', description: 'BAZG domain transportationType', type: 'integer', nullable: true),
    new OA\Property(property: 'transportation_country', type: 'string', maxLength: 2, nullable: true),
    new OA\Property(property: 'transportation_number', type: 'string', maxLength: 27, nullable: true),
    new OA\Property(property: 'transport_in_container', type: 'boolean'),
    new OA\Property(property: 'container_numbers', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
    new OA\Property(property: 'incoterms', type: 'string', maxLength: 3, nullable: true),
    new OA\Property(property: 'invoice_currency_type', description: 'BAZG domain invoiceCurrencyType', type: 'integer', nullable: true),
    new OA\Property(property: 'declared_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomsDeclarationItemRequest')),
])]
#[OA\Schema(schema: 'CustomsFromDeliveryNoteRequest', type: 'object', required: ['delivery_note_id', 'declaration_type'], properties: [
    new OA\Property(property: 'delivery_note_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'declaration_type', type: 'string', enum: ['import', 'export', 'transit']),
])]
#[OA\Schema(schema: 'CustomsAssessmentUploadRequest', type: 'object', required: ['xml'], properties: [
    new OA\Property(property: 'xml', description: 'The edecReceipt XML or the signed SOAP message', type: 'string', format: 'binary'),
    new OA\Property(property: 'pdf', type: 'string', format: 'binary'),
])]
#[OA\Schema(schema: 'CustomsRetrieveRequest', type: 'object', required: ['date_from', 'date_to'], properties: [
    new OA\Property(property: 'date_from', type: 'string', format: 'date'),
    new OA\Property(property: 'date_to', type: 'string', format: 'date'),
])]
#[OA\Schema(schema: 'CustomsTariffGridRequest', type: 'object', required: ['name', 'service_type', 'currency'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 120),
    new OA\Property(property: 'service_type', type: 'string', maxLength: 40),
    new OA\Property(property: 'currency', type: 'string', maxLength: 3),
    new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'CustomsTariffRateRequest', type: 'object', required: ['basis', 'rate'], properties: [
    new OA\Property(property: 'destination_country', description: 'Any destination when empty', type: 'string', maxLength: 2, nullable: true),
    new OA\Property(property: 'min_weight_kg', type: 'number'),
    new OA\Property(property: 'max_weight_kg', type: 'number', nullable: true),
    new OA\Property(property: 'min_volume_m3', type: 'number'),
    new OA\Property(property: 'max_volume_m3', type: 'number', nullable: true),
    new OA\Property(property: 'basis', type: 'string', enum: ['flat', 'per_kg', 'per_m3']),
    new OA\Property(property: 'rate', type: 'number'),
    new OA\Property(property: 'minimum_charge', type: 'number'),
])]
#[OA\Schema(schema: 'CustomsServiceDiscountRequest', type: 'object', required: ['contact_id', 'service_type', 'discount_percent'], properties: [
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'service_type', type: 'string', maxLength: 40),
    new OA\Property(property: 'discount_percent', type: 'number', maximum: 100, minimum: 0),
])]
#[OA\Schema(schema: 'CustomsDossierRequest', type: 'object', required: ['customer_contact_id', 'service_type', 'direction', 'currency'], properties: [
    new OA\Property(property: 'customer_contact_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'service_type', type: 'string', maxLength: 40),
    new OA\Property(property: 'direction', type: 'string', enum: ['import', 'export', 'transit']),
    new OA\Property(property: 'currency', type: 'string', maxLength: 3),
    new OA\Property(property: 'status', description: 'Only on update', type: 'string'),
    new OA\Property(property: 'origin_country', type: 'string', maxLength: 2, nullable: true),
    new OA\Property(property: 'destination_country', type: 'string', maxLength: 2, nullable: true),
    new OA\Property(property: 'incoterms', type: 'string', maxLength: 3, nullable: true),
    new OA\Property(property: 'gross_weight_kg', type: 'number'),
    new OA\Property(property: 'volume_m3', type: 'number'),
    new OA\Property(property: 'packages', type: 'integer'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'CustomsChargeRequest', type: 'object', required: ['kind', 'category', 'description', 'amount'], properties: [
    new OA\Property(property: 'kind', type: 'string', enum: ['cost', 'revenue']),
    new OA\Property(property: 'category', type: 'string', enum: ['freight', 'handling', 'duty', 'import_vat', 'insurance', 'other']),
    new OA\Property(property: 'is_direct', description: 'A cost of this shipment, or a share of general costs', type: 'boolean'),
    new OA\Property(property: 'description', type: 'string', maxLength: 255),
    new OA\Property(property: 'amount', type: 'number'),
    new OA\Property(property: 'tax_rate', description: 'Required on revenue before invoicing', type: 'number', nullable: true),
    new OA\Property(property: 'supplier_contact_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'CustomsDossierDocumentRequest', type: 'object', required: ['file', 'document_kind'], properties: [
    new OA\Property(property: 'file', type: 'string', format: 'binary'),
    new OA\Property(property: 'document_kind', type: 'string', enum: ['invoice', 'delivery_note', 'loading_list', 'email', 'other']),
])]
#[OA\Schema(schema: 'CustomsDeliveryRequest', type: 'object', required: ['packages'], properties: [
    new OA\Property(property: 'delivery_note_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'packages', type: 'integer', minimum: 1),
    new OA\Property(property: 'gross_weight_kg', type: 'number'),
    new OA\Property(property: 'delivered_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'recipient', type: 'string', maxLength: 120, nullable: true),
])]
#[OA\Schema(schema: 'CustomsInvoiceRequest', type: 'object', required: ['date'], properties: [new OA\Property(property: 'date', type: 'string', format: 'date')])]
#[OA\Schema(schema: 'CustomsGridQuoteRequest', type: 'object', properties: [new OA\Property(property: 'tariff_grid_id', description: 'The valid grid of the service when omitted', type: 'string', format: 'uuid', nullable: true)])]
#[OA\Schema(schema: 'CustomsSpotQuoteRequest', type: 'object', required: ['lines'], properties: [
    new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object', required: ['description', 'quantity', 'unit_price'], properties: [
        new OA\Property(property: 'description', type: 'string', maxLength: 255),
        new OA\Property(property: 'quantity', type: 'number'),
        new OA\Property(property: 'unit_price', type: 'number'),
    ])),
])]
#[OA\Schema(schema: 'CustomsQuoteStatusRequest', type: 'object', required: ['status'], properties: [new OA\Property(property: 'status', type: 'string', enum: ['sent', 'accepted', 'rejected'])])]
#[OA\Schema(schema: 'CustomsConsolidationRequest', type: 'object', required: ['reference', 'freight_cost', 'currency'], properties: [
    new OA\Property(property: 'reference', type: 'string', maxLength: 40),
    new OA\Property(property: 'carrier_name', type: 'string', maxLength: 120, nullable: true),
    new OA\Property(property: 'departure_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'freight_cost', type: 'number'),
    new OA\Property(property: 'currency', type: 'string', maxLength: 3),
])]
#[OA\Schema(schema: 'CustomsAcceptShipmentRequest', type: 'object', required: ['currency'], properties: [new OA\Property(property: 'currency', description: 'Currency of the dossier', type: 'string', maxLength: 3)])]
#[OA\Schema(schema: 'CustomsPortalShipmentRequest', type: 'object', required: ['service_type', 'direction', 'origin_country', 'destination_country', 'gross_weight_kg', 'packages'], properties: [
    new OA\Property(property: 'service_type', type: 'string', maxLength: 40),
    new OA\Property(property: 'direction', type: 'string', enum: ['import', 'export', 'transit']),
    new OA\Property(property: 'origin_country', type: 'string', maxLength: 2),
    new OA\Property(property: 'destination_country', type: 'string', maxLength: 2),
    new OA\Property(property: 'gross_weight_kg', type: 'number'),
    new OA\Property(property: 'volume_m3', type: 'number'),
    new OA\Property(property: 'packages', type: 'integer'),
    new OA\Property(property: 'pickup_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
final class CustomsRequestSchemas {}
