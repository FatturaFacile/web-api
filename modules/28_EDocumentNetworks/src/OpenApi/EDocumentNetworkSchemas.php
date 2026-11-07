<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of the document networks.
 */
#[OA\Tag(name: 'Document Networks', description: 'eBill, Peppol and NSO gateways. They refuse to run until a provider contract exists.')]
#[OA\Schema(
    schema: 'EDocumentNetworkStatus',
    type: 'object',
    properties: [
        new OA\Property(property: 'network', type: 'string', enum: ['ebill', 'peppol', 'nso']),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'driver', type: 'string'),
        new OA\Property(property: 'is_available', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'EDocumentTransmissionResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'network', type: 'string'),
        new OA\Property(property: 'document_reference', type: 'string'),
        new OA\Property(property: 'recipient_identifier', type: 'string'),
        new OA\Property(property: 'payload_sha256', type: 'string'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'external_id', type: 'string', nullable: true),
        new OA\Property(property: 'driver', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'SendEDocumentRequest',
    required: ['document_reference', 'recipient_identifier', 'payload_base64'],
    type: 'object',
    properties: [
        new OA\Property(property: 'document_reference', type: 'string', maxLength: 190),
        new OA\Property(property: 'recipient_identifier', type: 'string', maxLength: 190, description: 'Address of the recipient on the network'),
        new OA\Property(property: 'payload_base64', type: 'string', description: 'The document, already in the format of the network'),
    ]
)]
final class EDocumentNetworkSchemas {}
