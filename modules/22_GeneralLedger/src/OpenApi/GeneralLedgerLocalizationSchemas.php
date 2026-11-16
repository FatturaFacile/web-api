<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Schemas of the localization endpoints: the VAT return data of France (CA3), Spain (303) and Germany (UStVA).
 */
#[OA\Schema(
    schema: 'CountryVatReturnBox',
    type: 'object',
    properties: [
        new OA\Property(property: 'code', description: 'Box number of the form; `NN_tax` is the tax of the base box NN', type: 'string', example: '08_tax'),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'amount', description: 'Decimal string with 2 decimals', type: 'string', example: '200.00'),
    ]
)]
#[OA\Schema(
    schema: 'CountryVatReturn',
    type: 'object',
    properties: [
        new OA\Property(property: 'form', type: 'string', enum: ['CA3', '303', 'UStVA']),
        new OA\Property(property: 'country', type: 'string', enum: ['FR', 'ES', 'DE']),
        new OA\Property(property: 'from', type: 'string', format: 'date'),
        new OA\Property(property: 'to', type: 'string', format: 'date'),
        new OA\Property(property: 'boxes', type: 'array', items: new OA\Items(ref: '#/components/schemas/CountryVatReturnBox')),
        new OA\Property(property: 'vat_due', type: 'string', example: '0.00'),
        new OA\Property(property: 'vat_credit', type: 'string', example: '0.00'),
        new OA\Property(property: 'warnings', type: 'array', items: new OA\Items(type: 'string')),
    ]
)]
#[OA\Schema(
    schema: 'UsTaxExemptionCertificate',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'jurisdiction_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'state', type: 'string', example: 'CA'),
        new OA\Property(property: 'certificate_number', type: 'string'),
        new OA\Property(property: 'reason', type: 'string', enum: ['resale', 'government', 'nonprofit', 'manufacturing', 'agricultural', 'other']),
        new OA\Property(property: 'issued_on', type: 'string', format: 'date'),
        new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
final class GeneralLedgerLocalizationSchemas {}
