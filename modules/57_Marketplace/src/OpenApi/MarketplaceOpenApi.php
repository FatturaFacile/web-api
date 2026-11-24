<?php

declare(strict_types=1);

namespace Modules\Marketplace\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the marketplace endpoints.
 */
#[OA\Tag(name: 'Marketplace', description: 'Connector marketplace: catalog of connectors (e-commerce, shipping, payment, accounting export, automation) with version, configuration schema and required scopes; installations with encrypted credentials and a lifecycle (install, configure, enable, disable, upgrade, uninstall); health checks, per-connector rate limits and the sync run log. Credentials are never returned or logged.')]
#[OA\Response(response: 'MarketplaceUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'MarketplaceForbidden', description: 'Only owners and administrators of the company can use the marketplace', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'MarketplaceNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'MarketplaceUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'MarketplaceConfigField', type: 'object', properties: [
    new OA\Property(property: 'key', type: 'string'),
    new OA\Property(property: 'label', type: 'string'),
    new OA\Property(property: 'type', description: 'A secret field is a credential: stored encrypted, never returned', type: 'string', enum: ['string', 'url', 'secret', 'number', 'boolean', 'select']),
    new OA\Property(property: 'required', type: 'boolean'),
    new OA\Property(property: 'options', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'default', nullable: true),
    new OA\Property(property: 'help', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'MarketplaceConnector', type: 'object', properties: [
    new OA\Property(property: 'key', type: 'string', example: 'shopify'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'category', type: 'string', enum: ['ecommerce', 'shipping', 'payment', 'accounting_export', 'automation']),
    new OA\Property(property: 'version', type: 'string', example: '1.0.0'),
    new OA\Property(property: 'vendor', type: 'string', nullable: true),
    new OA\Property(property: 'documentation_url', type: 'string', nullable: true),
    new OA\Property(property: 'config_schema', type: 'array', items: new OA\Items(ref: '#/components/schemas/MarketplaceConfigField')),
    new OA\Property(property: 'required_scopes', type: 'array', items: new OA\Items(type: 'string', example: 'invoicing.write')),
    new OA\Property(property: 'resources', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'rate_limit_per_minute', type: 'integer'),
    new OA\Property(property: 'max_rate_limit_per_minute', type: 'integer'),
    new OA\Property(property: 'installations', description: 'Installations of this connector in the company', type: 'integer'),
])]
#[OA\Schema(schema: 'MarketplaceInstallation', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'connector_key', type: 'string'),
    new OA\Property(property: 'connector_name', type: 'string', nullable: true),
    new OA\Property(property: 'category', type: 'string', nullable: true),
    new OA\Property(property: 'connector_version', type: 'string'),
    new OA\Property(property: 'latest_version', type: 'string', nullable: true),
    new OA\Property(property: 'update_available', type: 'boolean'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['pending_configuration', 'disabled', 'enabled']),
    new OA\Property(property: 'settings', type: 'object'),
    new OA\Property(property: 'credentials_set', description: 'Keys of the credentials that hold a value; the values are never returned', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'granted_scopes', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'rate_limit_per_minute', type: 'integer', nullable: true),
    new OA\Property(property: 'effective_rate_limit_per_minute', type: 'integer', nullable: true),
    new OA\Property(property: 'configured_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'enabled_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'disabled_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'health', type: 'object', properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['unknown', 'healthy', 'degraded', 'unhealthy']),
        new OA\Property(property: 'checked_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'message', type: 'string', nullable: true),
    ]),
    new OA\Property(property: 'last_sync_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'MarketplaceInstallRequest', type: 'object', required: ['connector_key', 'name', 'granted_scopes'], properties: [
    new OA\Property(property: 'connector_key', type: 'string', example: 'shopify'),
    new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'Main shop'),
    new OA\Property(property: 'granted_scopes', description: 'Must include every required scope of the connector', type: 'array', items: new OA\Items(type: 'string', example: 'invoicing.write')),
])]
#[OA\Schema(schema: 'MarketplaceConfigureRequest', type: 'object', required: ['config'], properties: [
    new OA\Property(property: 'config', description: 'Value of each field of the connector schema, settings and secrets together', type: 'object', example: ['shop_domain' => 'mystore.myshopify.com', 'api_version' => '2025-01']),
    new OA\Property(property: 'rate_limit_per_minute', description: 'Lowers the limit of the connector for this installation; null restores the default', type: 'integer', nullable: true),
])]
#[OA\Schema(schema: 'MarketplaceUpgradeRequest', type: 'object', properties: [
    new OA\Property(property: 'granted_scopes', description: 'Scopes the new version requires and the company grants', type: 'array', items: new OA\Items(type: 'string')),
])]
#[OA\Schema(schema: 'MarketplaceSyncRequest', type: 'object', required: ['resource'], properties: [
    new OA\Property(property: 'resource', description: 'One of the resources of the connector', type: 'string', example: 'orders'),
])]
#[OA\Schema(schema: 'MarketplaceSyncRun', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'installation_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'installation_name', type: 'string', nullable: true),
    new OA\Property(property: 'resource', type: 'string'),
    new OA\Property(property: 'trigger', type: 'string', enum: ['manual', 'schedule']),
    new OA\Property(property: 'status', type: 'string', enum: ['running', 'succeeded', 'failed', 'rate_limited']),
    new OA\Property(property: 'started_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'finished_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'records_processed', type: 'integer'),
    new OA\Property(property: 'records_failed', type: 'integer'),
    new OA\Property(property: 'message', description: 'Never contains a credential', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'MarketplaceHealthCheck', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'installation_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'status', type: 'string', enum: ['healthy', 'degraded', 'unhealthy']),
    new OA\Property(property: 'latency_ms', type: 'integer', nullable: true),
    new OA\Property(property: 'message', type: 'string', nullable: true),
    new OA\Property(property: 'checked_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'MarketplaceOverview', type: 'object', properties: [
    new OA\Property(property: 'installations', type: 'integer'),
    new OA\Property(property: 'by_status', type: 'object', properties: [
        new OA\Property(property: 'pending_configuration', type: 'integer'),
        new OA\Property(property: 'disabled', type: 'integer'),
        new OA\Property(property: 'enabled', type: 'integer'),
    ]),
    new OA\Property(property: 'unhealthy', type: 'array', items: new OA\Items(ref: '#/components/schemas/MarketplaceInstallation')),
    new OA\Property(property: 'updates_available', type: 'array', items: new OA\Items(ref: '#/components/schemas/MarketplaceInstallation')),
    new OA\Property(property: 'recent_problem_runs', type: 'array', items: new OA\Items(ref: '#/components/schemas/MarketplaceSyncRun')),
])]
final class MarketplaceOpenApi {}
