<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Jobs\SendPaymentReminderEmail;
use App\Models\Invoice;
use App\Models\PaymentReminder;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Tenancy\TenantPreferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SendPaymentReminder
{
    public function __construct(
        private readonly DocumentEventRecorder $events,
        private readonly TenantPreferences $preferences,
    ) {}

    /**
     * Escalate (level 1 -> max) and queue a reminder for an overdue invoice.
     * Automatic runs respect grace days and the interval between reminders; `$force` (manual send) skips both.
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

        $reminder = DB::transaction(function () use ($invoice, $level, $recipient): PaymentReminder {
            $reminder = $invoice->reminders()->create([
                'tenant_id' => $invoice->tenant_id,
                'level' => $level,
                'recipient' => $recipient,
                'amount_due' => round((float) $invoice->total_gross - (float) $invoice->total_paid, 2),
                'status' => 'queued',
            ]);

            $this->events->record($invoice, 'reminder_queued', ['level' => $level, 'reminder_id' => $reminder->id]);

            return $reminder;
        });

        SendPaymentReminderEmail::dispatch($reminder->id);

        return $reminder;
    }

    private function reject(bool $force, string $message): ?PaymentReminder
    {
        if ($force) {
            throw ValidationException::withMessages(['invoice' => $message]);
        }

        return null;
    }
}
