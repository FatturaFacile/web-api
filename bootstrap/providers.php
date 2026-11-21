<?php

use App\Providers\AppServiceProvider;
use App\Providers\CookieAuthenticationServiceProvider;
use App\Providers\HttpsServiceProvider;
use Enterprise\AiGateway\Providers\AiGatewayServiceProvider;
use Enterprise\AuditLedger\Providers\AuditLedgerServiceProvider;
use Enterprise\EventBus\Providers\EventBusServiceProvider;
use Enterprise\FeatureFlags\Providers\FeatureFlagsServiceProvider;
use Enterprise\Foundation\Providers\EnterpriseFoundationServiceProvider;
use Enterprise\MultiTenant\Providers\MultiTenantServiceProvider;
use Enterprise\OpenApi\Providers\OpenApiServiceProvider;
use Modules\Academy\Providers\AcademyServiceProvider;
use Modules\AiCognitive\Providers\AiCognitiveServiceProvider;
use Modules\Analytics\Providers\AnalyticsServiceProvider;
use Modules\AnalyticsDss\Providers\AnalyticsDssServiceProvider;
use Modules\AssetManagement\Providers\AssetManagementServiceProvider;
use Modules\Assistant\Providers\AssistantServiceProvider;
use Modules\Associations\Providers\AssociationsServiceProvider;
use Modules\Budgeting\Providers\BudgetingServiceProvider;
use Modules\BusinessIntelligence\Providers\BusinessIntelligenceServiceProvider;
use Modules\Childcare\Providers\ChildcareServiceProvider;
use Modules\Collaboration\Providers\CollaborationServiceProvider;
use Modules\Communication\Providers\CommunicationServiceProvider;
use Modules\Configurator\Providers\ConfiguratorServiceProvider;
use Modules\ConstructionTrades\Providers\ConstructionTradesServiceProvider;
use Modules\Contracts\Providers\ContractsServiceProvider;
use Modules\Controlling\Providers\ControllingServiceProvider;
use Modules\Copilot\Providers\CopilotServiceProvider;
use Modules\CorporateGroup\Providers\CorporateGroupServiceProvider;
use Modules\CreditManagement\Providers\CreditManagementServiceProvider;
use Modules\CRM\Providers\CRMServiceProvider;
use Modules\Customs\Providers\CustomsServiceProvider;
use Modules\DataExport\Providers\DataExportServiceProvider;
use Modules\DataResidency\Providers\DataResidencyServiceProvider;
use Modules\DigitalPreservation\Providers\DigitalPreservationServiceProvider;
use Modules\Documents\Providers\DocumentsServiceProvider;
use Modules\DocumentTrust\Providers\DocumentTrustServiceProvider;
use Modules\EDocumentNetworks\Providers\EDocumentNetworksServiceProvider;
use Modules\Events\Providers\EventsServiceProvider;
use Modules\Facility\Providers\FacilityServiceProvider;
use Modules\FiscalRetail\Providers\FiscalRetailServiceProvider;
use Modules\Fleet\Providers\FleetServiceProvider;
use Modules\Forecasting\Providers\ForecastingServiceProvider;
use Modules\GeneralLedger\Providers\GeneralLedgerServiceProvider;
use Modules\Governance\Providers\GovernanceServiceProvider;
use Modules\HealthExpenses\Providers\HealthExpensesServiceProvider;
use Modules\Hospitality\Providers\HospitalityServiceProvider;
use Modules\Hse\Providers\HseServiceProvider;
use Modules\HumanResources\Providers\HumanResourcesServiceProvider;
use Modules\Integrations\Providers\IntegrationsServiceProvider;
use Modules\Intrastat\Providers\IntrastatServiceProvider;
use Modules\Inventory\Providers\InventoryServiceProvider;
use Modules\Invoicing\Providers\InvoicingServiceProvider;
use Modules\KpiDashboards\Providers\KpiDashboardsServiceProvider;
use Modules\LeaseAccounting\Providers\LeaseAccountingServiceProvider;
use Modules\Logistics\Providers\LogisticsServiceProvider;
use Modules\Manufacturing\Providers\ManufacturingServiceProvider;
use Modules\Marketplace\Providers\MarketplaceServiceProvider;
use Modules\OcrExtraction\Providers\OcrExtractionServiceProvider;
use Modules\Payroll\Providers\PayrollServiceProvider;
use Modules\Planning\Providers\PlanningServiceProvider;
use Modules\Platform\Identity\Providers\IdentityServiceProvider;
use Modules\Platform\Providers\PlatformServiceProvider;
use Modules\Plm\Providers\PlmServiceProvider;
use Modules\PracticeManagement\Providers\PracticeManagementServiceProvider;
use Modules\Pricing\Providers\PricingServiceProvider;
use Modules\Procurement\Providers\ProcurementServiceProvider;
use Modules\Projects\Providers\ProjectsServiceProvider;
use Modules\Quality\Providers\QualityServiceProvider;
use Modules\Quoting\Providers\QuotingServiceProvider;
use Modules\Rebates\Providers\RebatesServiceProvider;
use Modules\Returns\Providers\ReturnsServiceProvider;
use Modules\SDI\Providers\SdiServiceProvider;
use Modules\ServiceManagement\Providers\ServiceManagementServiceProvider;
use Modules\SubscriptionBilling\Providers\SubscriptionBillingServiceProvider;
use Modules\SupplierPortal\Providers\SupplierPortalServiceProvider;
use Modules\Sustainability\Providers\SustainabilityServiceProvider;
use Modules\SwissFiscal\Providers\SwissFiscalServiceProvider;
use Modules\TalentLearning\Providers\TalentLearningServiceProvider;
use Modules\Transportation\Providers\TransportationServiceProvider;
use Modules\TravelExpense\Providers\TravelExpenseServiceProvider;
use Modules\Treasury\Providers\TreasuryServiceProvider;
use Modules\Visitors\Providers\VisitorsServiceProvider;
use Modules\Wms\Providers\WmsServiceProvider;
use Modules\Workflow\Providers\WorkflowServiceProvider;
use Modules\Workshop\Providers\WorkshopServiceProvider;
use Modules\WorkspaceSync\Providers\WorkspaceSyncServiceProvider;

return [
    AppServiceProvider::class,
    HttpsServiceProvider::class,
    CookieAuthenticationServiceProvider::class,
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
    CopilotServiceProvider::class,
    AcademyServiceProvider::class,
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
    GovernanceServiceProvider::class,
    TravelExpenseServiceProvider::class,
    ConfiguratorServiceProvider::class,
    SupplierPortalServiceProvider::class,
    FacilityServiceProvider::class,
    LeaseAccountingServiceProvider::class,
    SustainabilityServiceProvider::class,
    FleetServiceProvider::class,
    CreditManagementServiceProvider::class,
    TalentLearningServiceProvider::class,
    PlanningServiceProvider::class,
    BudgetingServiceProvider::class,
    ControllingServiceProvider::class,
    BusinessIntelligenceServiceProvider::class,
    MarketplaceServiceProvider::class,
    RebatesServiceProvider::class,
    PricingServiceProvider::class,
    EventsServiceProvider::class,
    PlmServiceProvider::class,
    PlatformServiceProvider::class,
    IdentityServiceProvider::class,
    CollaborationServiceProvider::class,
    ReturnsServiceProvider::class,
    WmsServiceProvider::class,
    TransportationServiceProvider::class,
    LogisticsServiceProvider::class,
    CorporateGroupServiceProvider::class,
    DigitalPreservationServiceProvider::class,
    ForecastingServiceProvider::class,
    OcrExtractionServiceProvider::class,
    AnalyticsDssServiceProvider::class,
    KpiDashboardsServiceProvider::class,
    QuotingServiceProvider::class,
    SwissFiscalServiceProvider::class,
    AiCognitiveServiceProvider::class,
    HseServiceProvider::class,
    DataResidencyServiceProvider::class,
];
