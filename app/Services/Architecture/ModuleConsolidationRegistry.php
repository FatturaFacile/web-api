<?php

declare(strict_types=1);

namespace App\Services\Architecture;

use App\Http\Controllers\Api\V1\AccountingController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AutomotiveController;
use App\Http\Controllers\Api\V1\ConstructionController;
use App\Http\Controllers\Api\V1\CrmController;
use App\Http\Controllers\Api\V1\CustomsTaricCodeController;
use App\Http\Controllers\Api\V1\Ecommerce\EcommerceChannelController;
use App\Http\Controllers\Api\V1\FieldServiceController;
use App\Http\Controllers\Api\V1\FleetRentalController;
use App\Http\Controllers\Api\V1\HrController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\PosSaleController;
use App\Http\Controllers\Api\V1\PosSessionController;
use App\Http\Controllers\Api\V1\PosTerminalController;
use App\Http\Controllers\Api\V1\ProfessionalsController;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Api\V1\SalesOrderController;
use App\Http\Controllers\Api\V1\TimeAttendanceController;
use App\Http\Controllers\Api\V1\TreasuryController;
use App\Http\Controllers\Api\V1\WorkOrderController;
use App\Services\Accounting\LegacyLedgerBridge;
use App\Services\Appointments\AppointmentSchedulingService;
use App\Services\Attendance\AttendanceComplianceService;
use App\Services\Banking\BankReconciliationService;
use App\Services\Crm\ContactOverviewService;
use App\Services\Customs\CustomsComplianceService;
use App\Services\Intrastat\IntrastatDeclarationService;
use App\Services\Inventory\InventoryValuationService;
use App\Services\Invoicing\InvoiceService;
use App\Services\Sales\SalesPriceEngine;

/**
 * Canonical bounded-context registry linking legacy `App\*` domain classes
 * to their modular `Modules\*` counterparts across all 18 overlapping domains.
 */
final class ModuleConsolidationRegistry
{
    /**
     * @return array<string, array{canonical_namespace: string, legacy_classes: list<class-string>}>
     */
    public static function mappings(): array
    {
        return [
            'accounting' => [
                'canonical_namespace' => 'Modules\\GeneralLedger',
                'legacy_classes' => [
                    AccountingController::class,
                    LegacyLedgerBridge::class,
                ],
            ],
            'appointments' => [
                'canonical_namespace' => 'Modules\\Appointments',
                'legacy_classes' => [
                    AppointmentController::class,
                    AppointmentSchedulingService::class,
                ],
            ],
            'automotive' => [
                'canonical_namespace' => 'Modules\\Automotive',
                'legacy_classes' => [
                    AutomotiveController::class,
                ],
            ],
            'banking' => [
                'canonical_namespace' => 'Modules\\Treasury',
                'legacy_classes' => [
                    TreasuryController::class,
                    BankReconciliationService::class,
                ],
            ],
            'construction' => [
                'canonical_namespace' => 'Modules\\Construction',
                'legacy_classes' => [
                    ConstructionController::class,
                ],
            ],
            'crm' => [
                'canonical_namespace' => 'Modules\\CRM',
                'legacy_classes' => [
                    CrmController::class,
                    ContactOverviewService::class,
                ],
            ],
            'customs' => [
                'canonical_namespace' => 'Modules\\Customs',
                'legacy_classes' => [
                    CustomsTaricCodeController::class,
                    CustomsComplianceService::class,
                ],
            ],
            'ecommerce' => [
                'canonical_namespace' => 'Modules\\Ecommerce',
                'legacy_classes' => [
                    EcommerceChannelController::class,
                ],
            ],
            'field_service' => [
                'canonical_namespace' => 'Modules\\EnterpriseServiceManagement',
                'legacy_classes' => [
                    FieldServiceController::class,
                    WorkOrderController::class,
                ],
            ],
            'fleet_rental' => [
                'canonical_namespace' => 'Modules\\Fleet',
                'legacy_classes' => [
                    FleetRentalController::class,
                ],
            ],
            'hr' => [
                'canonical_namespace' => 'Modules\\HumanResources',
                'legacy_classes' => [
                    HrController::class,
                    TimeAttendanceController::class,
                ],
            ],
            'intrastat' => [
                'canonical_namespace' => 'Modules\\Tax',
                'legacy_classes' => [
                    IntrastatDeclarationService::class,
                ],
            ],
            'inventory' => [
                'canonical_namespace' => 'Modules\\Inventory',
                'legacy_classes' => [
                    InventoryController::class,
                    InventoryValuationService::class,
                ],
            ],
            'invoicing' => [
                'canonical_namespace' => 'Modules\\Invoicing',
                'legacy_classes' => [
                    InvoiceController::class,
                    InvoiceService::class,
                ],
            ],
            'payroll' => [
                'canonical_namespace' => 'Modules\\Payroll',
                'legacy_classes' => [
                    AttendanceComplianceService::class,
                ],
            ],
            'professionals' => [
                'canonical_namespace' => 'Modules\\ProfessionalServices',
                'legacy_classes' => [
                    ProfessionalsController::class,
                ],
            ],
            'retail' => [
                'canonical_namespace' => 'Modules\\Retail',
                'legacy_classes' => [
                    PosSaleController::class,
                    PosSessionController::class,
                    PosTerminalController::class,
                ],
            ],
            'sales' => [
                'canonical_namespace' => 'Modules\\Sales',
                'legacy_classes' => [
                    SalesOrderController::class,
                    QuoteController::class,
                    SalesPriceEngine::class,
                ],
            ],
        ];
    }

    public static function canonicalNamespaceFor(string $domain): ?string
    {
        return self::mappings()[$domain]['canonical_namespace'] ?? null;
    }
}
