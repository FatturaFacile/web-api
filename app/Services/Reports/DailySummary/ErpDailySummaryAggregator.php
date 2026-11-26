<?php

declare(strict_types=1);

namespace App\Services\Reports\DailySummary;

use App\Models\Alert;
use App\Models\Appointment;
use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\NotificationPreference;
use App\Models\PassiveInvoice;
use App\Models\Payment;
use App\Models\PaymentReminder;
use App\Models\Product;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\TenantReportSetting;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Modules\CRM\Models\Activity as CrmActivity;
use Modules\CRM\Models\Opportunity as CrmOpportunity;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockQuant;

/**
 * Modular multi-tenant ERP aggregation engine for scheduled daily summary reports.
 */
final class ErpDailySummaryAggregator
{
    public function __construct(
        private readonly ErpInsightsGenerator $insightsGenerator,
        private readonly ReportLocaleHelper $localeHelper,
    ) {}

    /**
     * Build the full personalized summary payload for a tenant and user.
     *
     * @param  list<string>|null  $requestedModules
     * @return array<string, mixed>
     */
    public function aggregate(
        Tenant $tenant,
        User $user,
        string $locale,
        ?TenantReportSetting $settings = null,
        ?array $requestedModules = null,
        bool $useCache = true,
    ): array {
        $timezone = $settings?->timezone ?: TenantReportSetting::defaultTimezoneForTenant($tenant);
        $localNow = Carbon::now($timezone);
        $currency = $tenant->default_currency ?: 'EUR';

        $tenantAllowed = $settings?->effectiveEnabledModules() ?? NotificationPreference::MODULES;
        $selectedModules = $requestedModules !== null
            ? array_values(array_intersect($tenantAllowed, $requestedModules))
            : $tenantAllowed;

        $tenantData = $this->aggregateTenantSections($tenant, $localNow, $locale, $currency, $useCache);

        // Personalize calendar & user-assigned activities.
        if (in_array('calendar', $selectedModules, true)) {
            $tenantData['calendar']['assigned_activities_count'] = $this->countUserAssignedActivities($tenant, $user);
        }

        $sections = [];
        foreach ($selectedModules as $module) {
            if (isset($tenantData[$module])) {
                $sections[$module] = $tenantData[$module];
            }
        }

        return [
            'generated_at' => $localNow->toIso8601String(),
            'report_date' => $localNow->toDateString(),
            'timezone' => $timezone,
            'currency' => $currency,
            'locale' => $locale,
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'legal_name' => $tenant->legal_name,
                'country' => $tenant->country,
            ],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'enabled_modules' => $selectedModules,
            'sections' => $sections,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function aggregateTenantSections(
        Tenant $tenant,
        Carbon $localNow,
        string $locale,
        string $currency,
        bool $useCache,
    ): array {
        $cacheKey = sprintf('erp_daily_summary:%s:%s:%s', $tenant->id, $localNow->toDateString(), $locale);

        $resolver = function () use ($tenant, $localNow, $locale, $currency): array {
            $sections = [
                'dashboard' => $this->aggregateDashboard($tenant, $localNow),
                'billing' => $this->aggregateBilling($tenant, $localNow),
                'deadlines' => $this->aggregateDeadlines($tenant, $localNow),
                'crm' => $this->aggregateCrm($tenant, $localNow),
                'calendar' => $this->aggregateCalendar($tenant, $localNow),
                'inventory' => $this->aggregateInventory($tenant, $localNow),
                'support' => $this->aggregateSupport($tenant, $localNow),
                'news' => $this->aggregateNews($tenant, $localNow, $locale),
            ];

            $sections['insights'] = [
                'currency' => $currency,
                'items' => $this->insightsGenerator->generate($tenant, $localNow, $locale, $sections),
            ];

            return $sections;
        };

        if (! $useCache) {
            return $resolver();
        }

        return Cache::remember($cacheKey, now()->addMinutes(5), $resolver);
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregateDashboard(Tenant $tenant, Carbon $localNow): array
    {
        $today = $localNow->toDateString();
        $monthStart = $localNow->copy()->startOfMonth()->toDateString();
        $yearStart = $localNow->copy()->startOfYear()->toDateString();
        $next7 = $localNow->copy()->addDays(7)->toDateString();

        $revenueToday = (float) Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotIn('invoice_type', ['proforma', 'credit_note'])
            ->whereDate('date', $today)
            ->sum('total_gross');

        $revenueMtd = (float) Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotIn('invoice_type', ['proforma', 'credit_note'])
            ->whereBetween('date', [$monthStart, $today])
            ->sum('total_gross');

        $revenueYtd = (float) Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotIn('invoice_type', ['proforma', 'credit_note'])
            ->whereBetween('date', [$yearStart, $today])
            ->sum('total_gross');

        $issuedDocs = Invoice::query()->where('tenant_id', $tenant->id)->whereBetween('date', [$monthStart, $today])->count()
            + Quote::query()->where('tenant_id', $tenant->id)->whereBetween('date', [$monthStart, $today])->count()
            + SalesOrder::query()->where('tenant_id', $tenant->id)->whereBetween('date', [$monthStart, $today])->count()
            + DeliveryNote::query()->where('tenant_id', $tenant->id)->whereBetween('date', [$monthStart, $today])->count();

        $activeCustomers = Contact::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('type', ['customer', 'client', 'both'])
            ->count();

        $activeSuppliers = Contact::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('type', ['supplier', 'both'])
            ->count();

        $upcomingDeadlines = Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('payment_status', ['unpaid', 'partially_paid', 'overdue'])
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$today, $next7])
            ->count()
            + PassiveInvoice::query()
                ->where('tenant_id', $tenant->id)
                ->where('payment_status', '!=', 'paid')
                ->whereNotNull('due_date')
                ->whereBetween('due_date', [$today, $next7])
                ->count();

        $openActivities = Appointment::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('cancelled_at')
            ->where('ends_at', '>=', $localNow->copy()->startOfDay())
            ->count()
            + WorkOrder::query()
                ->where('tenant_id', $tenant->id)
                ->whereNotIn('status', ['completed', 'cancelled', 'invoiced'])
                ->count();

        if (Schema::hasTable('crm_activities')) {
            $openActivities += CrmActivity::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', '!=', 'completed')
                ->count();
        }

        return [
            'revenue_today' => round($revenueToday, 2),
            'revenue_mtd' => round($revenueMtd, 2),
            'revenue_ytd' => round($revenueYtd, 2),
            'issued_documents_count' => $issuedDocs,
            'active_customers_count' => $activeCustomers,
            'active_suppliers_count' => $activeSuppliers,
            'upcoming_deadlines_count' => $upcomingDeadlines,
            'open_activities_count' => $openActivities,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregateBilling(Tenant $tenant, Carbon $localNow): array
    {
        $today = $localNow->toDateString();
        $monthStart = $localNow->copy()->startOfMonth()->toDateString();
        $next7 = $localNow->copy()->addDays(7)->toDateString();

        $issued = Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotIn('invoice_type', ['proforma', 'credit_note'])
            ->whereBetween('date', [$monthStart, $today])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_gross), 0) as gross, COALESCE(SUM(total_net), 0) as net, COALESCE(SUM(total_tax), 0) as tax')
            ->toBase()
            ->first();

        $paid = Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->where('payment_status', 'paid')
            ->whereBetween('date', [$monthStart, $today])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_gross), 0) as total')
            ->toBase()
            ->first();

        $unpaid = Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('payment_status', ['unpaid', 'partially_paid'])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_gross - total_paid), 0) as total')
            ->toBase()
            ->first();

        $overdue = Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('payment_status', ['unpaid', 'partially_paid', 'overdue'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_gross - total_paid), 0) as total')
            ->toBase()
            ->first();

        $dueSoon = Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('payment_status', ['unpaid', 'partially_paid'])
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$today, $next7])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_gross - total_paid), 0) as total')
            ->toBase()
            ->first();

        $creditNotes = Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->where('invoice_type', 'credit_note')
            ->whereBetween('date', [$monthStart, $today])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_gross), 0) as total')
            ->toBase()
            ->first();

        $paymentsReceived = Payment::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('paid_at', [$monthStart, $today])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total')
            ->toBase()
            ->first();

        return [
            'issued_invoices_count' => (int) ($issued->cnt ?? 0),
            'issued_invoices_total' => round((float) ($issued->gross ?? 0), 2),
            'paid_invoices_count' => (int) ($paid->cnt ?? 0),
            'paid_invoices_total' => round((float) ($paid->total ?? 0), 2),
            'unpaid_invoices_count' => (int) ($unpaid->cnt ?? 0),
            'unpaid_invoices_total' => round((float) ($unpaid->total ?? 0), 2),
            'overdue_invoices_count' => (int) ($overdue->cnt ?? 0),
            'overdue_invoices_total' => round((float) ($overdue->total ?? 0), 2),
            'due_soon_invoices_count' => (int) ($dueSoon->cnt ?? 0),
            'due_soon_invoices_total' => round((float) ($dueSoon->total ?? 0), 2),
            'credit_notes_count' => (int) ($creditNotes->cnt ?? 0),
            'credit_notes_total' => round((float) ($creditNotes->total ?? 0), 2),
            'total_taxable_amount' => round((float) ($issued->net ?? 0), 2),
            'total_vat_amount' => round((float) ($issued->tax ?? 0), 2),
            'payments_received_count' => (int) ($paymentsReceived->cnt ?? 0),
            'payments_received_total' => round((float) ($paymentsReceived->total ?? 0), 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregateDeadlines(Tenant $tenant, Carbon $localNow): array
    {
        $today = $localNow->toDateString();
        $next7 = $localNow->copy()->addDays(7)->toDateString();
        $next30 = $localNow->copy()->addDays(30)->toDateString();

        $overdueRows = Invoice::query()
            ->with('contact:id,company_name,first_name,last_name')
            ->where('tenant_id', $tenant->id)
            ->whereIn('payment_status', ['unpaid', 'partially_paid', 'overdue'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today)
            ->orderBy('due_date')
            ->get();

        $due7Rows = Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('payment_status', ['unpaid', 'partially_paid'])
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$today, $next7])
            ->get();

        $due30Rows = Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('payment_status', ['unpaid', 'partially_paid'])
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$today, $next30])
            ->get();

        $supplierDueRows = PassiveInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->where('payment_status', '!=', 'paid')
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $next30)
            ->get();

        $recentRemindedIds = PaymentReminder::query()
            ->where('tenant_id', $tenant->id)
            ->where('sent_at', '>=', $localNow->copy()->subDays(7))
            ->pluck('invoice_id')
            ->all();

        $remindersToSend = $overdueRows->filter(
            static fn (Invoice $invoice): bool => ! in_array($invoice->id, $recentRemindedIds, true)
        );

        return [
            'overdue_customer_invoices_count' => $overdueRows->count(),
            'overdue_customer_invoices_total' => round((float) $overdueRows->sum(static fn (Invoice $inv): float => max(0.0, (float) $inv->total_gross - (float) $inv->total_paid)), 2),
            'overdue_items' => $overdueRows->take(5)->map(static function (Invoice $inv): array {
                $contact = $inv->contact;
                $customerName = $contact !== null
                    ? ($contact->company_name ?: trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')))
                    : '';

                return [
                    'invoice_number' => $inv->invoice_number,
                    'customer' => $customerName,
                    'due_date' => $inv->due_date?->toDateString(),
                    'amount_due' => round(max(0.0, (float) $inv->total_gross - (float) $inv->total_paid), 2),
                ];
            })->values()->all(),
            'due_7_days_count' => $due7Rows->count(),
            'due_7_days_total' => round((float) $due7Rows->sum(static fn (Invoice $inv): float => max(0.0, (float) $inv->total_gross - (float) $inv->total_paid)), 2),
            'due_30_days_count' => $due30Rows->count(),
            'due_30_days_total' => round((float) $due30Rows->sum(static fn (Invoice $inv): float => max(0.0, (float) $inv->total_gross - (float) $inv->total_paid)), 2),
            'supplier_payments_due_count' => $supplierDueRows->count(),
            'supplier_payments_due_total' => round((float) $supplierDueRows->sum(static fn (PassiveInvoice $inv): float => max(0.0, (float) $inv->total_amount - (float) $inv->total_paid)), 2),
            'reminders_to_send_count' => $remindersToSend->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregateCrm(Tenant $tenant, Carbon $localNow): array
    {
        $newCustomers = Contact::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('type', ['customer', 'client', 'both'])
            ->where('created_at', '>=', $localNow->copy()->subDays(30))
            ->count();

        $activeCustomerIdsRecentlyBilled = Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->where('date', '>=', $localNow->copy()->subDays(90)->toDateString())
            ->distinct()
            ->pluck('contact_id');

        $inactiveCustomers = Contact::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('type', ['customer', 'client', 'both'])
            ->where('created_at', '<', $localNow->copy()->subDays(90))
            ->whereNotIn('id', $activeCustomerIdsRecentlyBilled)
            ->count();

        $openOppCount = 0;
        $openOppValue = 0.0;
        $closingSoonCount = 0;

        if (Schema::hasTable('crm_opportunities')) {
            $openOpp = CrmOpportunity::query()
                ->where('tenant_id', $tenant->id)
                ->whereNull('actual_close_date')
                ->whereNotIn('status', ['won', 'lost', 'closed'])
                ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total')
                ->toBase()
                ->first();

            $openOppCount = (int) ($openOpp->cnt ?? 0);
            $openOppValue = round((float) ($openOpp->total ?? 0), 2);

            $closingSoonCount = CrmOpportunity::query()
                ->where('tenant_id', $tenant->id)
                ->whereNull('actual_close_date')
                ->whereNotIn('status', ['won', 'lost', 'closed'])
                ->whereNotNull('expected_close_date')
                ->whereBetween('expected_close_date', [$localNow->toDateString(), $localNow->copy()->addDays(14)->toDateString()])
                ->count();
        }

        $plannedActivities = 0;
        $pendingFollowups = 0;

        if (Schema::hasTable('crm_activities')) {
            $plannedActivities = CrmActivity::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', '!=', 'completed')
                ->count();

            $pendingFollowups = CrmActivity::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', '!=', 'completed')
                ->where(static function ($query) use ($localNow): void {
                    $query->where('type', 'follow_up')
                        ->orWhere(static function ($q) use ($localNow): void {
                            $q->whereNotNull('due_date')->where('due_date', '<=', $localNow->copy()->addDays(3));
                        });
                })
                ->count();
        }

        return [
            'new_customers_count' => $newCustomers,
            'inactive_customers_count' => $inactiveCustomers,
            'open_opportunities_count' => $openOppCount,
            'open_opportunities_value' => $openOppValue,
            'closing_soon_opportunities_count' => $closingSoonCount,
            'planned_activities_count' => $plannedActivities,
            'pending_followups_count' => $pendingFollowups,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregateCalendar(Tenant $tenant, Carbon $localNow): array
    {
        $dayStart = $localNow->copy()->startOfDay();
        $dayEnd = $localNow->copy()->endOfDay();
        $weekStart = $localNow->copy()->startOfWeek();
        $weekEnd = $localNow->copy()->endOfWeek();

        $todayAppointments = Appointment::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('cancelled_at')
            ->whereBetween('starts_at', [$dayStart, $dayEnd])
            ->orderBy('starts_at')
            ->get(['id', 'title', 'starts_at', 'ends_at', 'address']);

        $weekCount = Appointment::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('cancelled_at')
            ->whereBetween('starts_at', [$weekStart, $weekEnd])
            ->count();

        $upcomingCount = Appointment::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('cancelled_at')
            ->where('starts_at', '>', $dayEnd)
            ->count();

        $tasksDueOrOverdue = WorkOrder::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotIn('status', ['completed', 'cancelled', 'invoiced'])
            ->whereDate('date', '<=', $localNow->toDateString())
            ->count();

        $appointmentsList = [];
        foreach ($todayAppointments->take(5) as $appointment) {
            $startsAt = $appointment->getAttribute('starts_at');
            $appointmentsList[] = [
                'title' => (string) $appointment->getAttribute('title'),
                'starts_at' => $startsAt instanceof Carbon ? $startsAt->toIso8601String() : ($startsAt !== null ? (string) $startsAt : null),
                'address' => $appointment->getAttribute('address'),
            ];
        }

        return [
            'today_appointments_count' => $todayAppointments->count(),
            'today_appointments' => $appointmentsList,
            'week_appointments_count' => $weekCount,
            'upcoming_meetings_count' => $upcomingCount,
            'assigned_activities_count' => 0,
            'tasks_due_or_overdue_count' => $tasksDueOrOverdue,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregateInventory(Tenant $tenant, Carbon $localNow): array
    {
        $products = Product::query()
            ->where('tenant_id', $tenant->id)
            ->where('track_inventory', true)
            ->where('is_active', true)
            ->get(['id', 'name', 'sku', 'min_stock', 'cost_price']);

        $stockByProduct = [];
        if (Schema::hasTable('stock_quants') && Schema::hasTable('stock_items')) {
            $quantRows = StockQuant::query()
                ->join('stock_items', 'stock_quants.stock_item_id', '=', 'stock_items.id')
                ->where('stock_quants.tenant_id', $tenant->id)
                ->selectRaw('stock_items.product_id as product_id, COALESCE(SUM(stock_quants.quantity_on_hand), 0) as qty')
                ->groupBy('stock_items.product_id')
                ->toBase()
                ->get();

            foreach ($quantRows as $row) {
                if ($row->product_id !== null) {
                    $stockByProduct[(string) $row->product_id] = (float) $row->qty;
                }
            }
        }

        $belowMin = 0;
        $outOfStock = 0;
        $totalValuation = 0.0;

        foreach ($products as $product) {
            $qty = $stockByProduct[(string) $product->id] ?? 0.0;
            $minStock = (float) ($product->min_stock ?? 0);

            if ($qty <= 0.0) {
                $outOfStock++;
            }
            if ($minStock > 0.0 && $qty < $minStock) {
                $belowMin++;
            }
            if ($qty > 0.0) {
                $totalValuation += $qty * (float) ($product->cost_price ?? 0);
            }
        }

        $expiringCount = 0;
        if (Schema::hasTable('stock_lots')) {
            $expiringCount = StockLot::query()
                ->where('tenant_id', $tenant->id)
                ->where('is_quarantined', false)
                ->whereNotNull('expiration_date')
                ->whereBetween('expiration_date', [$localNow->toDateString(), $localNow->copy()->addDays(30)->toDateString()])
                ->count();
        }

        $recentMovements = StockMovement::query()
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $localNow->copy()->subDays(7))
            ->count();

        return [
            'below_min_stock_count' => $belowMin,
            'out_of_stock_count' => $outOfStock,
            'expiring_products_count' => $expiringCount,
            'recent_movements_count' => $recentMovements,
            'total_inventory_valuation' => round($totalValuation, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregateSupport(Tenant $tenant, Carbon $localNow): array
    {
        $openCount = Ticket::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'open')
            ->count();

        $pendingCount = Ticket::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['pending', 'waiting', 'in_progress'])
            ->count();

        $urgentCount = Ticket::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('priority', ['urgent', 'high', 'critical'])
            ->whereNotIn('status', ['closed', 'resolved'])
            ->count();

        $closedCount = Ticket::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['closed', 'resolved'])
            ->where('updated_at', '>=', $localNow->copy()->subDays(30))
            ->count();

        $expiringSlaCount = Ticket::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotIn('status', ['closed', 'resolved'])
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<=', $localNow->copy()->addHours(24))
            ->count();

        return [
            'open_tickets_count' => $openCount,
            'pending_tickets_count' => $pendingCount,
            'urgent_tickets_count' => $urgentCount,
            'closed_tickets_count' => $closedCount,
            'expiring_sla_tickets_count' => $expiringSlaCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregateNews(Tenant $tenant, Carbon $localNow, string $locale): array
    {
        $alerts = Alert::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where(static function ($q) use ($localNow): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $localNow);
            })
            ->where(static function ($q) use ($localNow): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $localNow);
            })
            ->latest()
            ->take(3)
            ->get(['title', 'message', 'severity']);

        $items = $alerts->map(static fn (Alert $alert): array => [
            'category' => 'admin_notice',
            'severity' => $alert->severity,
            'title' => $alert->title,
            'summary' => (string) ($alert->message ?? ''),
        ])->values()->all();

        $items[] = [
            'category' => 'feature_release',
            'severity' => 'info',
            'title' => $this->localeHelper->trans('SCHEDULED_REPORT.NEWS.DEFAULT_UPDATE_TITLE', $locale, [], $tenant->id),
            'summary' => $this->localeHelper->trans('SCHEDULED_REPORT.NEWS.DEFAULT_UPDATE_DESC', $locale, [], $tenant->id),
        ];

        $items[] = [
            'category' => 'security',
            'severity' => 'info',
            'title' => $this->localeHelper->trans('SCHEDULED_REPORT.NEWS.SECURITY_ADVISORY_TITLE', $locale, [], $tenant->id),
            'summary' => $this->localeHelper->trans('SCHEDULED_REPORT.NEWS.SECURITY_ADVISORY_DESC', $locale, [], $tenant->id),
        ];

        return [
            'items' => $items,
        ];
    }

    private function countUserAssignedActivities(Tenant $tenant, User $user): int
    {
        $count = Appointment::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('cancelled_at')
            ->forTechnician((string) $user->id)
            ->where('ends_at', '>=', now()->startOfDay())
            ->count();

        $count += WorkOrder::query()
            ->where('tenant_id', $tenant->id)
            ->where('technician_user_id', $user->id)
            ->whereNotIn('status', ['completed', 'cancelled', 'invoiced'])
            ->count();

        if (Schema::hasTable('crm_activities')) {
            $count += CrmActivity::query()
                ->where('tenant_id', $tenant->id)
                ->where('assigned_user_id', $user->id)
                ->where('status', '!=', 'completed')
                ->count();
        }

        return $count;
    }
}
