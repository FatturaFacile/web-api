<?php

use App\Providers\AppServiceProvider;
use Enterprise\AiGateway\Providers\AiGatewayServiceProvider;
use Enterprise\AuditLedger\Providers\AuditLedgerServiceProvider;
use Enterprise\EventBus\Providers\EventBusServiceProvider;
use Enterprise\FeatureFlags\Providers\FeatureFlagsServiceProvider;
use Enterprise\Foundation\Providers\EnterpriseFoundationServiceProvider;
use Enterprise\MultiTenant\Providers\MultiTenantServiceProvider;
use Enterprise\OpenApi\Providers\OpenApiServiceProvider;
use Modules\Analytics\Providers\AnalyticsServiceProvider;
use Modules\AssetManagement\Providers\AssetManagementServiceProvider;
use Modules\Assistant\Providers\AssistantServiceProvider;
use Modules\Associations\Providers\AssociationsServiceProvider;
use Modules\Childcare\Providers\ChildcareServiceProvider;
use Modules\Communication\Providers\CommunicationServiceProvider;
use Modules\ConstructionTrades\Providers\ConstructionTradesServiceProvider;
use Modules\Contracts\Providers\ContractsServiceProvider;
use Modules\CRM\Providers\CRMServiceProvider;
use Modules\Customs\Providers\CustomsServiceProvider;
use Modules\DataExport\Providers\DataExportServiceProvider;
use Modules\Documents\Providers\DocumentsServiceProvider;
use Modules\DocumentTrust\Providers\DocumentTrustServiceProvider;
use Modules\EDocumentNetworks\Providers\EDocumentNetworksServiceProvider;
use Modules\FiscalRetail\Providers\FiscalRetailServiceProvider;
use Modules\GeneralLedger\Providers\GeneralLedgerServiceProvider;
use Modules\HealthExpenses\Providers\HealthExpensesServiceProvider;
use Modules\Hospitality\Providers\HospitalityServiceProvider;
use Modules\HumanResources\Providers\HumanResourcesServiceProvider;
use Modules\Integrations\Providers\IntegrationsServiceProvider;
use Modules\Intrastat\Providers\IntrastatServiceProvider;
use Modules\Inventory\Providers\InventoryServiceProvider;
use Modules\Invoicing\Providers\InvoicingServiceProvider;
use Modules\Manufacturing\Providers\ManufacturingServiceProvider;
use Modules\Payroll\Providers\PayrollServiceProvider;
use Modules\PracticeManagement\Providers\PracticeManagementServiceProvider;
use Modules\Procurement\Providers\ProcurementServiceProvider;
use Modules\Projects\Providers\ProjectsServiceProvider;
use Modules\Quality\Providers\QualityServiceProvider;
use Modules\SDI\Providers\SdiServiceProvider;
use Modules\ServiceManagement\Providers\ServiceManagementServiceProvider;
use Modules\SubscriptionBilling\Providers\SubscriptionBillingServiceProvider;
use Modules\Treasury\Providers\TreasuryServiceProvider;
use Modules\Visitors\Providers\VisitorsServiceProvider;
use Modules\Workflow\Providers\WorkflowServiceProvider;
use Modules\Workshop\Providers\WorkshopServiceProvider;
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
    AssociationsServiceProvider::class,
    CustomsServiceProvider::class,
    FiscalRetailServiceProvider::class,
    HealthExpensesServiceProvider::class,
    DataExportServiceProvider::class,
    IntegrationsServiceProvider::class,
    CommunicationServiceProvider::class,
    AnalyticsServiceProvider::class,
    DocumentTrustServiceProvider::class,
    DocumentsServiceProvider::class,
    ContractsServiceProvider::class,
    AssistantServiceProvider::class,
    EDocumentNetworksServiceProvider::class,
    WorkspaceSyncServiceProvider::class,
    WorkshopServiceProvider::class,
    ConstructionTradesServiceProvider::class,
    ChildcareServiceProvider::class,
    PracticeManagementServiceProvider::class,
    HospitalityServiceProvider::class,
    VisitorsServiceProvider::class,
    IntrastatServiceProvider::class,
    QualityServiceProvider::class,
];
