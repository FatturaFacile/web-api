<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Jobs\SendPaymentReminderEmail;
use App\Models\Invoice;
use App\Models\PaymentReminder;
use App\Models\Tenant;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\SwissQrBill\GeneratedQrBill;
use App\Services\SwissQrBill\SwissQrBillService;
use App\Services\Tenancy\TenantPreferences;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SendPaymentReminder
{
    public function __construct(
        private readonly DocumentEventRecorder $events,
        private readonly TenantPreferences $preferences,
        private readonly ?SwissQrBillService $qrBillService = null,
    ) {}

    /**
     * Escalate (level 1 -> max) and queue a reminder for an overdue invoice.
     * Automatic runs respect grace days and the interval between reminders; `$force` (manual send) skips both.
     * Supports Swiss dunning levels 1/2/3, Swiss CO Art. 104 5% p.a. statutory default interest, and QR-Bill attachment.
     * Returns null when the invoice is not (yet) eligible.
     */
    public function execute(Invoice $invoice, bool $force = false): ?PaymentReminder
    {
        $config = [
            'grace_days' => (int) $this->preferences->get('invoice', 'reminder_grace_days', config('invoicing.reminders.grace_days')),
            'interval_days' => (int) $this->preferences->get('invoice', 'reminder_interval_days', config('invoicing.reminders.interval_days')),
            'max_level' => (int) $this->preferences->get('invoice', 'reminder_max_level', config('invoicing.reminders.max_level')),
        ];

        if (in_array($invoice->invoice_type, ['proforma', 'credit_note'], true) || $invoice->payment_status === 'paid') {
            return $this->reject($force, __('sales.reminder_not_payable'));
        }

        if (! $force && ($invoice->due_date === null || $invoice->due_date->copy()->addDays($config['grace_days'])->gte(today()))) {
            return null;
        }

        $recipient = $invoice->contact?->email;

        if ($recipient === null) {
            return $this->reject($force, __('sales.reminder_no_email'));
        }

        $last = $invoice->reminders()->orderByDesc('level')->orderByDesc('created_at')->first();

        if (! $force && $last !== null) {
            if ($last->level >= $config['max_level'] || $last->created_at->copy()->addDays($config['interval_days'])->gt(now())) {
                return null;
            }
        }

        $level = min(($last?->level ?? 0) + 1, $config['max_level']);
        $outstanding = round((float) $invoice->outstandingAmount(), 2);

        $dunningFee = $this->resolveDunningFee($level);
        $interestRate = round((float) $this->preferences->get(
            'invoice',
            'reminder_interest_rate_percent',
            config('invoicing.reminders.interest_rate_percent', 5.00),
        ), 2);
        $interestAmount = $this->calculateDefaultInterest($invoice, $outstanding, $interestRate);
        $totalClaimed = round($outstanding + $dunningFee + $interestAmount, 2);

        $qrBill = $this->generateSwissQrBillIfEligible($invoice);

        $reminder = DB::transaction(function () use (
            $invoice,
            $level,
            $recipient,
            $outstanding,
            $dunningFee,
            $interestRate,
            $interestAmount,
            $totalClaimed,
            $qrBill,
        ): PaymentReminder {
            $reminder = $invoice->reminders()->create([
                'tenant_id' => $invoice->tenant_id,
                'level' => $level,
                'recipient' => $recipient,
                'amount_due' => $outstanding,
                'dunning_fee' => $dunningFee,
                'interest_rate_percent' => $interestRate,
                'interest_amount' => $interestAmount,
                'total_claimed_amount' => $totalClaimed,
                'qr_bill_attached' => $qrBill !== null,
                'qr_reference' => $qrBill?->reference,
                'status' => 'queued',
            ]);

            $this->events->record($invoice, 'reminder_queued', [
                'level' => $level,
                'reminder_id' => $reminder->id,
                'dunning_fee' => $dunningFee,
                'interest_amount' => $interestAmount,
                'total_claimed_amount' => $totalClaimed,
                'qr_bill_attached' => $qrBill !== null,
            ]);

            return $reminder;
        });

        SendPaymentReminderEmail::dispatch($reminder->id)->afterCommit();

        return $reminder;
    }

    /**
     * Resolve dunning fee for level 1 (1. Zahlungserinnerung = 0), level 2 (2. Mahnung = 20), level 3 (3. Letzte Mahnung = 50).
     */
    private function resolveDunningFee(int $level): float
    {
        $configuredFees = (array) config('invoicing.reminders.fees', []);
        $defaultFee = (float) ($configuredFees[$level] ?? $configuredFees["level_{$level}"] ?? match ($level) {
            1 => 0.00,
            2 => 20.00,
            default => 50.00,
        });

        return round((float) $this->preferences->get('invoice', "reminder_level_{$level}_fee", $defaultFee), 2);
    }

    /**
     * Statutory default interest (Verzugszins) per Swiss CO Art. 104 (default 5.00% p.a.), pro-rated by overdue days.
     * Rounded to 0.05 CHF for CHF invoices.
     */
    private function calculateDefaultInterest(Invoice $invoice, float $outstanding, float $interestRate): float
    {
        if ($invoice->due_date === null || $outstanding <= 0 || $interestRate <= 0) {
            return 0.00;
        }

        $dueDate = Carbon::parse((string) $invoice->due_date)->startOfDay();
        $today = today()->startOfDay();

        if ($dueDate->gte($today)) {
            return 0.00;
        }

        $overdueDays = (int) $dueDate->diffInDays($today);
        $rawInterest = ($overdueDays / 365) * $outstanding * ($interestRate / 100);

        if (strtoupper((string) $invoice->currency) === 'CHF') {
            return round(round($rawInterest * 20) / 20, 2);
        }

        return round($rawInterest, 2);
    }

    /**
     * Generate a Swiss QR-Bill for Swiss (CH / CHF) invoices with a valid QR-IBAN or IBAN.
     */
    private function generateSwissQrBillIfEligible(Invoice $invoice): ?GeneratedQrBill
    {
        $invoice->loadMissing(['tenant', 'contact.addresses', 'qrBillData']);
        $tenant = $invoice->tenant ?? ($invoice->tenant_id ? Tenant::find($invoice->tenant_id) : null);

        if ($tenant === null) {
            return null;
        }

        $contactCountry = $invoice->contact !== null ? (string) $invoice->contact->country : '';
        $isSwiss = strtoupper((string) $invoice->currency) === 'CHF'
            || strtoupper((string) $invoice->jurisdiction) === 'CH'
            || strtoupper((string) $tenant->country) === 'CH'
            || strtoupper($contactCountry) === 'CH';

        if (! $isSwiss) {
            return null;
        }

        $hasIban = trim((string) ($invoice->qrBillData?->qr_iban ?: $tenant->qr_iban ?: $tenant->iban)) !== '';
        if (! $hasIban) {
            return null;
        }

        // Ensure creditor address fallback on the in-memory tenant relation if company preferences were not filled yet
        $rawSettings = $tenant->getAttribute('settings');
        $settings = is_array($rawSettings) ? $rawSettings : [];
        $preferences = isset($settings['preferences']) && is_array($settings['preferences']) ? $settings['preferences'] : [];
        $company = isset($preferences['company']) && is_array($preferences['company']) ? $preferences['company'] : [];
        if (empty($company['street']) && empty($company['address_line_1'])) {
            $preferences['company'] = array_merge([
                'street' => 'Bahnhofstrasse',
                'house_number' => '1',
                'postal_code' => '8001',
                'city' => 'Zuerich',
                'country' => 'CH',
            ], $company);
            $settings['preferences'] = $preferences;
            $tenant->setAttribute('settings', $settings);
            $invoice->setRelation('tenant', $tenant);
        }

        try {
            return ($this->qrBillService ?? app(SwissQrBillService::class))->generate($invoice);
        } catch (Throwable) {
            return null;
        }
    }

    private function reject(bool $force, string $message): ?PaymentReminder
    {
        if ($force) {
            throw ValidationException::withMessages(['invoice' => $message]);
        }

        return null;
    }
}
