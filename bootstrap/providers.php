<?php

use App\Providers\AppServiceProvider;
use Enterprise\AiGateway\Providers\AiGatewayServiceProvider;
use Enterprise\AuditLedger\Providers\AuditLedgerServiceProvider;
use Enterprise\EventBus\Providers\EventBusServiceProvider;
use Enterprise\FeatureFlags\Providers\FeatureFlagsServiceProvider;
use Enterprise\Foundation\Providers\EnterpriseFoundationServiceProvider;
use Enterprise\MultiTenant\Providers\MultiTenantServiceProvider;
use Enterprise\OpenApi\Providers\OpenApiServiceProvider;
use Modules\AssetManagement\Providers\AssetManagementServiceProvider;
use Modules\Assistant\Providers\AssistantServiceProvider;
use Modules\Contracts\Providers\ContractsServiceProvider;
use Modules\CRM\Providers\CRMServiceProvider;
use Modules\Documents\Providers\DocumentsServiceProvider;
use Modules\EDocumentNetworks\Providers\EDocumentNetworksServiceProvider;
use Modules\GeneralLedger\Providers\GeneralLedgerServiceProvider;
use Modules\HumanResources\Providers\HumanResourcesServiceProvider;
use Modules\Inventory\Providers\InventoryServiceProvider;
use Modules\Invoicing\Providers\InvoicingServiceProvider;
use Modules\Manufacturing\Providers\ManufacturingServiceProvider;
use Modules\Payroll\Providers\PayrollServiceProvider;
use Modules\Procurement\Providers\ProcurementServiceProvider;
use Modules\Projects\Providers\ProjectsServiceProvider;
use Modules\SDI\Providers\SdiServiceProvider;
use Modules\ServiceManagement\Providers\ServiceManagementServiceProvider;
use Modules\SubscriptionBilling\Providers\SubscriptionBillingServiceProvider;
use Modules\Treasury\Providers\TreasuryServiceProvider;
use Modules\Workflow\Providers\WorkflowServiceProvider;
use Modules\WorkspaceSync\Providers\WorkspaceSyncServiceProvider;

return [
    AppServiceProvider::class,
    EnterpriseFoundationServiceProvider::class,
    MultiTenantServiceProvider::class,
    AuditLedgerServiceProvider::class,
    AiGatewayServiceProvider::class,
    EventBusServiceProvider::class,
    FeatureFlagsServiceProvider::class,
    OpenApiServiceProvider::class,
    WorkflowServiceProvider::class,
    ProcurementServiceProvider::class,
    InventoryServiceProvider::class,
    ProjectsServiceProvider::class,
    GeneralLedgerServiceProvider::class,
    TreasuryServiceProvider::class,
    InvoicingServiceProvider::class,
    CRMServiceProvider::class,
    AssetManagementServiceProvider::class,
    HumanResourcesServiceProvider::class,
    PayrollServiceProvider::class,
    ServiceManagementServiceProvider::class,
    SubscriptionBillingServiceProvider::class,
    ManufacturingServiceProvider::class,
    SdiServiceProvider::class,
    DocumentsServiceProvider::class,
    ContractsServiceProvider::class,
    AssistantServiceProvider::class,
    EDocumentNetworksServiceProvider::class,
    WorkspaceSyncServiceProvider::class,
];
