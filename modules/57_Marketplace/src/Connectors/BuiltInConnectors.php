<?php

declare(strict_types=1);

namespace Modules\Marketplace\Connectors;

use App\Services\Security\OutboundUrlGuard;
use Modules\Marketplace\Contracts\Connector;
use Modules\Marketplace\Support\ConfigField;
use Modules\Marketplace\Support\ConnectorDefinition;

/**
 * The ready definitions of the catalog: e-commerce, shipping, payment and accounting-export connectors for common
 * systems, each a {@see ProfileConnector} over the REST API of the system, plus the webhook bridge (registered by the
 * service provider). The profiles describe the public API of each vendor (address, authentication, health and list
 * endpoints); they have not been exercised against live vendor accounts, which is why an adapter can replace any of them
 * by a class implementing {@see Connector} under the same key.
 */
final class BuiltInConnectors
{
    /**
     * @return list<Connector>
     */
    public static function all(OutboundUrlGuard $outbound): array
    {
        $connectors = [];

        foreach (self::catalog() as [$definition, $profile]) {
            $connectors[] = new ProfileConnector($definition, $profile, $outbound);
        }

        return $connectors;
    }

    /**
     * @return list<array{0: ConnectorDefinition, 1: HttpProfile}>
     */
    private static function catalog(): array
    {
        $ecommerceScopes = ['invoicing.write', 'inventory.write', 'crm.write'];
        $paymentScopes = ['payment_links.write', 'treasury.write', 'invoicing.read'];
        $shippingScopes = ['inventory.write', 'invoicing.read'];
        $accountingScopes = ['invoicing.read', 'ledger.write'];

        return [
            [
                new ConnectorDefinition(
                    key: 'shopify',
                    name: 'Shopify',
                    description: 'Orders, products and customers of a Shopify store.',
                    category: ConnectorDefinition::Ecommerce,
                    version: '1.0.0',
                    fields: [
                        new ConfigField('shop_domain', 'Shop domain', help: 'For example mystore.myshopify.com'),
                        new ConfigField('api_version', 'API version', 'select', options: ['2024-10', '2025-01', '2025-04'], default: '2025-01'),
                        new ConfigField('access_token', 'Admin API access token', 'secret'),
                    ],
                    requiredScopes: $ecommerceScopes,
                    resources: ['orders', 'products', 'customers'],
                    rateLimitPerMinute: 120,
                    maxRateLimitPerMinute: 240,
                    vendor: 'Shopify',
                    documentationUrl: 'https://shopify.dev/docs/api/admin-rest',
                ),
                new HttpProfile(
                    baseUrl: 'https://{shop_domain}/admin/api/{api_version}',
                    auth: ['type' => 'header', 'name' => 'X-Shopify-Access-Token', 'credential' => 'access_token'],
                    healthPath: '/shop.json',
                    resourcePaths: [
                        'orders' => ['path' => '/orders.json?limit=50&status=any', 'collection' => 'orders'],
                        'products' => ['path' => '/products.json?limit=50', 'collection' => 'products'],
                        'customers' => ['path' => '/customers.json?limit=50', 'collection' => 'customers'],
                    ],
                    cursorParameters: ['orders' => 'since_id', 'products' => 'since_id', 'customers' => 'since_id'],
                ),
            ],
            [
                new ConnectorDefinition(
                    key: 'woocommerce',
                    name: 'WooCommerce',
                    description: 'Orders, products and customers of a WooCommerce store.',
                    category: ConnectorDefinition::Ecommerce,
                    version: '1.0.0',
                    fields: [
                        new ConfigField('store_url', 'Store URL', 'url', help: 'HTTPS address of the shop'),
                        new ConfigField('consumer_key', 'Consumer key', 'secret'),
                        new ConfigField('consumer_secret', 'Consumer secret', 'secret'),
                    ],
                    requiredScopes: $ecommerceScopes,
                    resources: ['orders', 'products', 'customers'],
                    rateLimitPerMinute: 60,
                    maxRateLimitPerMinute: 120,
                    vendor: 'Automattic',
                    documentationUrl: 'https://woocommerce.github.io/woocommerce-rest-api-docs/',
                ),
                new HttpProfile(
                    baseUrl: '{store_url}/wp-json/wc/v3',
                    auth: ['type' => 'basic', 'user' => 'consumer_key', 'password' => 'consumer_secret'],
                    healthPath: '/',
                    resourcePaths: [
                        'orders' => ['path' => '/orders?per_page=50&orderby=date&order=asc', 'collection' => null],
                        'products' => ['path' => '/products?per_page=50', 'collection' => null],
                        'customers' => ['path' => '/customers?per_page=50', 'collection' => null],
                    ],
                    cursorParameters: ['orders' => 'after'],
                ),
            ],
            [
                new ConnectorDefinition(
                    key: 'shipstation',
                    name: 'ShipStation',
                    description: 'Orders and shipments of a ShipStation account.',
                    category: ConnectorDefinition::Shipping,
                    version: '1.0.0',
                    fields: [
                        new ConfigField('api_key', 'API key', 'secret'),
                        new ConfigField('api_secret', 'API secret', 'secret'),
                    ],
                    requiredScopes: $shippingScopes,
                    resources: ['orders', 'shipments'],
                    rateLimitPerMinute: 40,
                    maxRateLimitPerMinute: 40,
                    vendor: 'ShipStation',
                    documentationUrl: 'https://www.shipstation.com/docs/api/',
                ),
                new HttpProfile(
                    baseUrl: 'https://ssapi.shipstation.com',
                    auth: ['type' => 'basic', 'user' => 'api_key', 'password' => 'api_secret'],
                    healthPath: '/stores',
                    resourcePaths: [
                        'orders' => ['path' => '/orders?pageSize=50', 'collection' => 'orders'],
                        'shipments' => ['path' => '/shipments?pageSize=50', 'collection' => 'shipments'],
                    ],
                ),
            ],
            [
                new ConnectorDefinition(
                    key: 'easypost',
                    name: 'EasyPost',
                    description: 'Shipments and tracking of an EasyPost account, with many carriers behind one API.',
                    category: ConnectorDefinition::Shipping,
                    version: '1.0.0',
                    fields: [
                        new ConfigField('api_key', 'API key', 'secret'),
                    ],
                    requiredScopes: $shippingScopes,
                    resources: ['shipments', 'trackers'],
                    rateLimitPerMinute: 60,
                    maxRateLimitPerMinute: 120,
                    vendor: 'EasyPost',
                    documentationUrl: 'https://docs.easypost.com/',
                ),
                new HttpProfile(
                    baseUrl: 'https://api.easypost.com/v2',
                    auth: ['type' => 'basic', 'user' => 'api_key', 'password' => 'api_key'],
                    healthPath: '/shipments?page_size=1',
                    resourcePaths: [
                        'shipments' => ['path' => '/shipments?page_size=50', 'collection' => 'shipments'],
                        'trackers' => ['path' => '/trackers?page_size=50', 'collection' => 'trackers'],
                    ],
                ),
            ],
            [
                new ConnectorDefinition(
                    key: 'stripe',
                    name: 'Stripe',
                    description: 'Charges, payouts and customers of a Stripe account.',
                    category: ConnectorDefinition::Payment,
                    version: '1.0.0',
                    fields: [
                        new ConfigField('secret_key', 'Secret key', 'secret', help: 'A restricted key with read access is enough'),
                    ],
                    requiredScopes: $paymentScopes,
                    resources: ['charges', 'payouts', 'customers'],
                    rateLimitPerMinute: 100,
                    maxRateLimitPerMinute: 200,
                    vendor: 'Stripe',
                    documentationUrl: 'https://docs.stripe.com/api',
                ),
                new HttpProfile(
                    baseUrl: 'https://api.stripe.com/v1',
                    auth: ['type' => 'bearer', 'credential' => 'secret_key'],
                    healthPath: '/balance',
                    resourcePaths: [
                        'charges' => ['path' => '/charges?limit=50', 'collection' => 'data'],
                        'payouts' => ['path' => '/payouts?limit=50', 'collection' => 'data'],
                        'customers' => ['path' => '/customers?limit=50', 'collection' => 'data'],
                    ],
                    cursorParameters: ['charges' => 'created[gt]', 'payouts' => 'created[gt]', 'customers' => 'created[gt]'],
                ),
            ],
            [
                new ConnectorDefinition(
                    key: 'mollie',
                    name: 'Mollie',
                    description: 'Payments and settlements of a Mollie account.',
                    category: ConnectorDefinition::Payment,
                    version: '1.0.0',
                    fields: [
                        new ConfigField('api_key', 'API key', 'secret'),
                    ],
                    requiredScopes: $paymentScopes,
                    resources: ['payments', 'settlements'],
                    rateLimitPerMinute: 60,
                    maxRateLimitPerMinute: 120,
                    vendor: 'Mollie',
                    documentationUrl: 'https://docs.mollie.com/reference/overview',
                ),
                new HttpProfile(
                    baseUrl: 'https://api.mollie.com/v2',
                    auth: ['type' => 'bearer', 'credential' => 'api_key'],
                    healthPath: '/methods',
                    resourcePaths: [
                        'payments' => ['path' => '/payments?limit=50', 'collection' => '_embedded.payments'],
                        'settlements' => ['path' => '/settlements?limit=50', 'collection' => '_embedded.settlements'],
                    ],
                ),
            ],
            [
                new ConnectorDefinition(
                    key: 'fatture-in-cloud',
                    name: 'Fatture in Cloud',
                    description: 'Accounting export towards Fatture in Cloud: issued documents of a company.',
                    category: ConnectorDefinition::AccountingExport,
                    version: '1.0.0',
                    fields: [
                        new ConfigField('company_id', 'Company ID'),
                        new ConfigField('access_token', 'Access token', 'secret'),
                    ],
                    requiredScopes: $accountingScopes,
                    resources: ['issued_documents', 'suppliers'],
                    rateLimitPerMinute: 60,
                    maxRateLimitPerMinute: 120,
                    vendor: 'TeamSystem',
                    documentationUrl: 'https://developers.fattureincloud.it/',
                ),
                new HttpProfile(
                    baseUrl: 'https://api-v2.fattureincloud.it',
                    auth: ['type' => 'bearer', 'credential' => 'access_token'],
                    healthPath: '/user/info',
                    resourcePaths: [
                        'issued_documents' => ['path' => '/c/{company_id}/issued_documents?type=invoice&per_page=50', 'collection' => 'data'],
                        'suppliers' => ['path' => '/c/{company_id}/entities/suppliers?per_page=50', 'collection' => 'data'],
                    ],
                ),
            ],
            [
                new ConnectorDefinition(
                    key: 'bexio',
                    name: 'Bexio',
                    description: 'Accounting export towards Bexio: accounts and contacts.',
                    category: ConnectorDefinition::AccountingExport,
                    version: '1.0.0',
                    fields: [
                        new ConfigField('access_token', 'Access token', 'secret'),
                    ],
                    requiredScopes: $accountingScopes,
                    resources: ['accounts', 'contacts'],
                    rateLimitPerMinute: 60,
                    maxRateLimitPerMinute: 120,
                    vendor: 'Bexio',
                    documentationUrl: 'https://docs.bexio.com/',
                ),
                new HttpProfile(
                    baseUrl: 'https://api.bexio.com',
                    auth: ['type' => 'bearer', 'credential' => 'access_token'],
                    healthPath: '/2.0/country',
                    resourcePaths: [
                        'accounts' => ['path' => '/2.0/accounts', 'collection' => null],
                        'contacts' => ['path' => '/2.0/contact?limit=50', 'collection' => null],
                    ],
                ),
            ],
        ];
    }
}
