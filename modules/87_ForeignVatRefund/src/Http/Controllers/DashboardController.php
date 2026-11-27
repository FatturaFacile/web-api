<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\ForeignVatRefund\Domain\Enums\ClaimStatus;
use Modules\ForeignVatRefund\Domain\Enums\ExpenseCategory;
use Modules\ForeignVatRefund\Domain\Enums\LedgerStatus;
use Modules\ForeignVatRefund\Domain\Enums\PeriodType;
use Modules\ForeignVatRefund\Domain\Enums\RegimeType;
use Modules\ForeignVatRefund\Domain\Enums\SourceType;
use Modules\ForeignVatRefund\Domain\Support\ExpenseCategoryGuesser;
use Modules\ForeignVatRefund\Domain\Support\Jurisdictions;
use Modules\ForeignVatRefund\Http\Controllers\Concerns\InteractsWithForeignVat;
use Modules\ForeignVatRefund\Http\Requests\PreviewSplitRequest;
use Modules\ForeignVatRefund\Http\Requests\SaveSettingsRequest;
use Modules\ForeignVatRefund\Models\ForeignVatLedgerEntry;
use Modules\ForeignVatRefund\Services\ForeignVatAccounts;
use Modules\ForeignVatRefund\Services\ForeignVatInterceptor;
use Modules\ForeignVatRefund\Services\RefundRuleResolver;
use Modules\ForeignVatRefund\Services\ThresholdMonitor;
use OpenApi\Attributes as OA;

/**
 * Dashboard (KPI cards, threshold and deadline monitor), reference data, split preview and company settings.
 */
final class DashboardController extends Controller
{
    use ApiResponse;
    use InteractsWithForeignVat;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/foreign-vat-refund/dashboard', operationId: 'foreignVatRefundDashboard', summary: 'KPI cards and the threshold / deadline monitor per refund country', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Dashboard')])]
    public function dashboard(ThresholdMonitor $monitor): JsonResponse
    {
        Gate::authorize('viewAny', ForeignVatLedgerEntry::class);

        return $this->successResponse($monitor->dashboard($this->tenantId()), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/foreign-vat-refund/reference', operationId: 'foreignVatRefundReference', summary: 'Codes the screens build their selects from (categories, regimes, statuses, countries)', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Reference data')])]
    public function reference(RefundRuleResolver $rules): JsonResponse
    {
        Gate::authorize('viewAny', ForeignVatLedgerEntry::class);
        $home = $rules->homeCountry($this->tenantId());
        $countries = [];

        foreach (Jurisdictions::CURRENCIES as $code => $currency) {
            $countries[] = ['code' => $code, 'currency_code' => $currency, 'eu_member_state' => Jurisdictions::isEuMemberState($code)];
        }

        return $this->successResponse([
            'home_country' => $home,
            'refund_countries' => $rules->refundCountries($this->tenantId(), $home),
            'countries' => $countries,
            'expense_categories' => ExpenseCategory::codes(),
            'regimes' => array_map(static fn (RegimeType $case): string => $case->value, RegimeType::cases()),
            'ledger_statuses' => array_map(static fn (LedgerStatus $case): string => $case->value, LedgerStatus::cases()),
            'claim_statuses' => array_map(static fn (ClaimStatus $case): string => $case->value, ClaimStatus::cases()),
            'period_types' => array_map(static fn (PeriodType $case): string => $case->value, PeriodType::cases()),
            'source_types' => array_map(static fn (SourceType $case): string => $case->value, SourceType::cases()),
        ], $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/foreign-vat-refund/preview-split', operationId: 'foreignVatRefundPreviewSplit', summary: 'Recoverable and non-recoverable split of an amount of foreign VAT', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Split and the rule applied')])]
    public function previewSplit(PreviewSplitRequest $request, ForeignVatInterceptor $interceptor): JsonResponse
    {
        Gate::authorize('viewAny', ForeignVatLedgerEntry::class);
        $data = $request->validated();

        // Without a code the category is proposed from the description of the document line, as the interception does.
        $category = isset($data['expense_category_code']) ? (int) $data['expense_category_code'] : ExpenseCategoryGuesser::guess($data['description'] ?? null);

        return $this->successResponse($interceptor->preview($this->tenantId(), (string) $data['country'], $category, (string) $data['vat_amount']), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/foreign-vat-refund/settings', operationId: 'foreignVatRefundShowSettings', summary: 'Interception switch and the ledger accounts of the module', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Settings')])]
    public function showSettings(ForeignVatAccounts $accounts): JsonResponse
    {
        Gate::authorize('viewAny', ForeignVatLedgerEntry::class);

        return $this->successResponse($this->settingsPayload($accounts), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/foreign-vat-refund/settings', operationId: 'foreignVatRefundSaveSettings', summary: 'Save the interception switch and the ledger accounts of the module', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Saved settings')])]
    public function updateSettings(SaveSettingsRequest $request, ForeignVatAccounts $accounts): JsonResponse
    {
        Gate::authorize('create', ForeignVatLedgerEntry::class);
        $accounts->settings($this->tenantId())->update($request->validated());

        return $this->successResponse($this->settingsPayload($accounts), $this->message('saved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(ForeignVatAccounts $accounts): array
    {
        $setting = $accounts->settings($this->tenantId())->refresh();

        return [
            'auto_intercept' => $setting->auto_intercept,
            'receivable_account_id' => $setting->receivable_account_id,
            'non_recoverable_expense_account_id' => $setting->non_recoverable_expense_account_id,
        ];
    }
}
