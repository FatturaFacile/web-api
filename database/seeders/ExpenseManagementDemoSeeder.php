<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\ExpenseManagement\Domain\Enums\PaymentMethod;
use Modules\ExpenseManagement\Models\CorporateCardAccount;
use Modules\ExpenseManagement\Models\CorporateCardTransaction;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Services\CardReconciliationService;
use Modules\ExpenseManagement\Services\ExpenseLineService;
use Modules\ExpenseManagement\Services\MileageService;
use Modules\ExpenseManagement\Services\PerDiemService;
use Modules\ExpenseManagement\Services\ReportService;

/**
 * Demo expense data for the first company of the installation: a draft report with itemised lines (one cash meal, which the
 * Italian rules flag), a mileage claim, a per-diem allowance and card statement lines. Idempotent by report title.
 * Run it after ExpenseManagementReferenceSeeder; it does nothing when there is no company or no user.
 */
final class ExpenseManagementDemoSeeder extends Seeder
{
    private const string Title = 'Demo — client visit';

    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('created_at')->first();
        $user = $tenant?->users()->first();

        if ($tenant === null || $user === null) {
            return;
        }

        app(TenantContext::class)->runWithTenant($tenant, function () use ($tenant, $user): void {
            if (ExpenseReport::query()->where('title', self::Title)->exists()) {
                return;
            }

            $reports = app(ReportService::class);
            $lines = app(ExpenseLineService::class);
            $start = now()->subDays(10)->startOfDay();

            $report = $reports->create(['title' => self::Title, 'period_start' => $start->toDateString(), 'period_end' => $start->copy()->addDays(2)->toDateString()], (string) $tenant->id, (string) $user->id);
            $currency = $report->currency;

            $lines->save($report, null, [
                'expense_date' => $start->toDateString(), 'category_id' => $lines->categoryByCode('meals')->id, 'merchant_name' => 'Trattoria da Mario',
                'original_amount' => '38.50', 'original_currency' => $currency, 'vat_rate' => '10', 'payment_method' => PaymentMethod::EmployeeOutOfPocket->value, 'is_traceable_payment' => false,
            ]);
            $lines->save($report, null, [
                'expense_date' => $start->copy()->addDay()->toDateString(), 'category_id' => $lines->categoryByCode('lodging')->id, 'merchant_name' => 'Hotel Centrale', 'merchant_tax_id' => 'IT01234567890',
                'original_amount' => '132.00', 'original_currency' => $currency, 'vat_rate' => '10', 'payment_method' => PaymentMethod::EmployeeOutOfPocket->value,
            ]);
            $cardLine = $lines->save($report, null, [
                'expense_date' => $start->copy()->addDay()->toDateString(), 'category_id' => $lines->categoryByCode('local_transport')->id, 'merchant_name' => 'Taxi Milano',
                'original_amount' => '24.00', 'original_currency' => $currency, 'payment_method' => PaymentMethod::EmployeeOutOfPocket->value,
            ]);

            app(MileageService::class)->add($report, [
                'expense_date' => $start->toDateString(), 'origin_address' => 'Via Roma 1, Torino', 'destination_address' => 'Via Dante 10, Milano', 'distance_km' => '142', 'is_round_trip' => true,
                'vehicle_class_code' => $report->jurisdiction === 'CH' ? 'TCS-COMPACT' : 'VW-GOLF-TDI', 'calculation_source' => $report->jurisdiction === 'CH' ? 'CH_TCS' : null,
            ]);
            app(PerDiemService::class)->add($report, [
                'country_code' => $report->jurisdiction, 'destination_type' => 'domestic', 'allowance_method' => 'flat_rate',
                'trip_start_at' => $start->copy()->setTime(7, 0)->toDateTimeString(), 'trip_end_at' => $start->copy()->addDay()->setTime(20, 0)->toDateTimeString(), 'allowance_rate_per_day' => '50.00',
            ]);
            $reports->recalculate($report);

            $account = CorporateCardAccount::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'card_last4' => '4242', 'holder_name' => (string) $user->name],
                ['issuer' => 'Demo Bank', 'currency' => $currency, 'is_active' => true],
            );
            $transactions = [
                ['external_id' => 'DEMO-1', 'merchant_name' => 'Taxi Milano', 'transaction_date' => $start->copy()->addDay()->toDateString(), 'amount' => '24.00'],
                ['external_id' => 'DEMO-2', 'merchant_name' => 'Cloud Subscription', 'transaction_date' => $start->toDateString(), 'amount' => '49.90'],
                ['external_id' => 'DEMO-3', 'merchant_name' => 'Airport Parking', 'transaction_date' => $start->copy()->addDays(2)->toDateString(), 'amount' => '36.00'],
            ];
            $cards = app(CardReconciliationService::class);
            $imported = $cards->import($account, $transactions, (string) $tenant->id);
            $cards->match(CorporateCardTransaction::query()->findOrFail($imported[0]->id), [['expense_line_id' => (string) $cardLine->id]], (string) $user->id);
        });
    }
}
