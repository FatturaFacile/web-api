<?php

declare(strict_types=1);

use Database\Factories\ChildcareActivityEnrolmentFactory;
use Database\Factories\ChildcareActivityFactory;
use Database\Factories\ChildcareAttendanceCorrectionFactory;
use Database\Factories\ChildcareAttendanceFactory;
use Database\Factories\ChildcareChildDocumentFactory;
use Database\Factories\ChildcareChildFeeFactory;
use Database\Factories\ChildcareDocumentRequirementFactory;
use Database\Factories\ChildcareFeePlanFactory;
use Database\Factories\ChildcareGroupFactory;
use Database\Factories\ChildcareMessageFactory;
use Database\Factories\ChildcareMonthlyBillFactory;
use Database\Factories\ChildcareNoteFactory;
use Database\Factories\ChildcareReferencePersonFactory;
use Database\Factories\ChildcareStaffShiftFactory;
use Database\Factories\ChildcareSubsidyRuleFactory;
use Database\Factories\ChildcareWeeklyPlanFactory;
use Database\Factories\CustomsAssessmentDecisionFactory;
use Database\Factories\CustomsConsolidationFactory;
use Database\Factories\CustomsCountryFactory;
use Database\Factories\CustomsDeclarationItemFactory;
use Database\Factories\CustomsDocumentTypeFactory;
use Database\Factories\CustomsDossierChargeFactory;
use Database\Factories\CustomsDossierDeliveryFactory;
use Database\Factories\CustomsDossierDocumentFactory;
use Database\Factories\CustomsGatewayMessageFactory;
use Database\Factories\CustomsLedgerPostingFactory;
use Database\Factories\CustomsQuoteFactory;
use Database\Factories\CustomsQuoteLineFactory;
use Database\Factories\CustomsRetrievalRunFactory;
use Database\Factories\CustomsServiceDiscountFactory;
use Database\Factories\CustomsSettingsFactory;
use Database\Factories\CustomsShipmentRequestFactory;
use Database\Factories\CustomsStatisticalKeyFactory;
use Database\Factories\CustomsTariffGridRateFactory;
use Database\Factories\CustomsTariffNumberFactory;
use Database\Factories\HospitalityDiningTableFactory;
use Database\Factories\HospitalityKitchenTicketFactory;
use Database\Factories\HospitalityOrderFactory;
use Database\Factories\HospitalityOrderItemFactory;
use Database\Factories\HospitalityOrderPaymentFactory;
use Database\Factories\HospitalityRoomFactory;
use Database\Factories\RecurringServiceFactory;
use Database\Factories\TradesBidPositionFactory;
use Database\Factories\TradesBillingFactory;
use Database\Factories\TradesMeasurementFactory;
use Database\Factories\TradesPriceItemFactory;
use Database\Factories\TradesSiteCostFactory;
use Database\Factories\TradesWorkCycleFactory;
use Database\Factories\TradesWorkCycleStepFactory;
use Database\Factories\VehicleSaleFactory;
use Database\Factories\WorkshopFolderLineFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Childcare\Models\Activity;
use Modules\Childcare\Models\ActivityEnrolment;
use Modules\Childcare\Models\Attendance;
use Modules\Childcare\Models\AttendanceCorrection;
use Modules\Childcare\Models\ChildDocument;
use Modules\Childcare\Models\ChildFee;
use Modules\Childcare\Models\DocumentRequirement;
use Modules\Childcare\Models\FeePlan;
use Modules\Childcare\Models\Group;
use Modules\Childcare\Models\Message;
use Modules\Childcare\Models\MonthlyBill;
use Modules\Childcare\Models\Note;
use Modules\Childcare\Models\ReferencePerson;
use Modules\Childcare\Models\StaffShift;
use Modules\Childcare\Models\SubsidyRule;
use Modules\Childcare\Models\WeeklyPlan;
use Modules\ConstructionTrades\Models\BidPosition;
use Modules\ConstructionTrades\Models\Billing;
use Modules\ConstructionTrades\Models\Measurement;
use Modules\ConstructionTrades\Models\PriceItem;
use Modules\ConstructionTrades\Models\SiteCost;
use Modules\ConstructionTrades\Models\WorkCycle;
use Modules\ConstructionTrades\Models\WorkCycleStep;
use Modules\Customs\Models\CustomsAssessmentDecision;
use Modules\Customs\Models\CustomsConsolidation;
use Modules\Customs\Models\CustomsCountry;
use Modules\Customs\Models\CustomsDeclarationItem;
use Modules\Customs\Models\CustomsDocumentType;
use Modules\Customs\Models\CustomsDossierCharge;
use Modules\Customs\Models\CustomsDossierDelivery;
use Modules\Customs\Models\CustomsDossierDocument;
use Modules\Customs\Models\CustomsGatewayMessage;
use Modules\Customs\Models\CustomsLedgerPosting;
use Modules\Customs\Models\CustomsQuote;
use Modules\Customs\Models\CustomsQuoteLine;
use Modules\Customs\Models\CustomsRetrievalRun;
use Modules\Customs\Models\CustomsServiceDiscount;
use Modules\Customs\Models\CustomsSettings;
use Modules\Customs\Models\CustomsShipmentRequest;
use Modules\Customs\Models\CustomsStatisticalKey;
use Modules\Customs\Models\CustomsTariffGridRate;
use Modules\Customs\Models\CustomsTariffNumber;
use Modules\Hospitality\Models\DiningTable;
use Modules\Hospitality\Models\KitchenTicket;
use Modules\Hospitality\Models\Order;
use Modules\Hospitality\Models\OrderItem;
use Modules\Hospitality\Models\OrderPayment;
use Modules\Hospitality\Models\Room;
use Modules\Workshop\Models\RecurringService;
use Modules\Workshop\Models\VehicleSale;
use Modules\Workshop\Models\WorkshopFolderLine;

uses(RefreshDatabase::class);

$industryVerticalModels = [
    [CustomsAssessmentDecision::class, CustomsAssessmentDecisionFactory::class],
    [CustomsConsolidation::class, CustomsConsolidationFactory::class],
    [CustomsCountry::class, CustomsCountryFactory::class],
    [CustomsDeclarationItem::class, CustomsDeclarationItemFactory::class],
    [CustomsDocumentType::class, CustomsDocumentTypeFactory::class],
    [CustomsDossierCharge::class, CustomsDossierChargeFactory::class],
    [CustomsDossierDelivery::class, CustomsDossierDeliveryFactory::class],
    [CustomsDossierDocument::class, CustomsDossierDocumentFactory::class],
    [CustomsGatewayMessage::class, CustomsGatewayMessageFactory::class],
    [CustomsLedgerPosting::class, CustomsLedgerPostingFactory::class],
    [CustomsQuote::class, CustomsQuoteFactory::class],
    [CustomsQuoteLine::class, CustomsQuoteLineFactory::class],
    [CustomsRetrievalRun::class, CustomsRetrievalRunFactory::class],
    [CustomsServiceDiscount::class, CustomsServiceDiscountFactory::class],
    [CustomsSettings::class, CustomsSettingsFactory::class],
    [CustomsShipmentRequest::class, CustomsShipmentRequestFactory::class],
    [CustomsStatisticalKey::class, CustomsStatisticalKeyFactory::class],
    [CustomsTariffGridRate::class, CustomsTariffGridRateFactory::class],
    [CustomsTariffNumber::class, CustomsTariffNumberFactory::class],
    [RecurringService::class, RecurringServiceFactory::class],
    [VehicleSale::class, VehicleSaleFactory::class],
    [WorkshopFolderLine::class, WorkshopFolderLineFactory::class],
    [BidPosition::class, TradesBidPositionFactory::class],
    [Billing::class, TradesBillingFactory::class],
    [Measurement::class, TradesMeasurementFactory::class],
    [PriceItem::class, TradesPriceItemFactory::class],
    [SiteCost::class, TradesSiteCostFactory::class],
    [WorkCycle::class, TradesWorkCycleFactory::class],
    [WorkCycleStep::class, TradesWorkCycleStepFactory::class],
    [Activity::class, ChildcareActivityFactory::class],
    [ActivityEnrolment::class, ChildcareActivityEnrolmentFactory::class],
    [Attendance::class, ChildcareAttendanceFactory::class],
    [AttendanceCorrection::class, ChildcareAttendanceCorrectionFactory::class],
    [ChildDocument::class, ChildcareChildDocumentFactory::class],
    [ChildFee::class, ChildcareChildFeeFactory::class],
    [DocumentRequirement::class, ChildcareDocumentRequirementFactory::class],
    [FeePlan::class, ChildcareFeePlanFactory::class],
    [Group::class, ChildcareGroupFactory::class],
    [Message::class, ChildcareMessageFactory::class],
    [MonthlyBill::class, ChildcareMonthlyBillFactory::class],
    [Note::class, ChildcareNoteFactory::class],
    [ReferencePerson::class, ChildcareReferencePersonFactory::class],
    [StaffShift::class, ChildcareStaffShiftFactory::class],
    [SubsidyRule::class, ChildcareSubsidyRuleFactory::class],
    [WeeklyPlan::class, ChildcareWeeklyPlanFactory::class],
    [DiningTable::class, HospitalityDiningTableFactory::class],
    [KitchenTicket::class, HospitalityKitchenTicketFactory::class],
    [Order::class, HospitalityOrderFactory::class],
    [OrderItem::class, HospitalityOrderItemFactory::class],
    [OrderPayment::class, HospitalityOrderPaymentFactory::class],
    [Room::class, HospitalityRoomFactory::class],
];

test('every model of the group has a working factory', function (string $model, string $factory): void {
    $record = $factory::new()->create();

    expect($record)->toBeInstanceOf($model)->and($record->exists)->toBeTrue();
})->with($industryVerticalModels);

test('every wired model resolves its own factory', function (string $model, string $factory): void {
    expect($model::factory())->toBeInstanceOf($factory);
})->with($industryVerticalModels);
