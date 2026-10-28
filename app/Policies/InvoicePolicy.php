<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->belongsToSameTenant($user, $invoice);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Invoice $invoice): bool
    {
        if (! $this->belongsToSameTenant($user, $invoice)) {
            return false;
        }

        // Invoices transmitted or currently sending to SDI cannot be mutated via API
        if ($invoice->sdiMetadata && in_array($invoice->sdiMetadata->sdi_status, ['sending', 'delivered', 'accepted'], true)) {
            return false;
        }

        // Paid invoices cannot be modified
        return $invoice->payment_status !== 'paid';
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        if (! $this->belongsToSameTenant($user, $invoice)) {
            return false;
        }

        // Invoices transmitted or currently sending to SDI cannot be deleted
        if ($invoice->sdiMetadata && in_array($invoice->sdiMetadata->sdi_status, ['sending', 'delivered', 'accepted'], true)) {
            return false;
        }

        // Invoices with payments cannot be deleted
        return $invoice->payment_status === 'unpaid' && (float) $invoice->total_paid <= 0;
    }

    public function recordPayment(User $user, Invoice $invoice): bool
    {
        return $this->belongsToSameTenant($user, $invoice);
    }

    public function download(User $user, Invoice $invoice): bool
    {
        return $this->belongsToSameTenant($user, $invoice);
    }

    public function sendSdi(User $user, Invoice $invoice): bool
    {
        return $this->belongsToSameTenant($user, $invoice);
    }

    public function generateQrBill(User $user, Invoice $invoice): bool
    {
        return $this->belongsToSameTenant($user, $invoice);
    }

    private function belongsToSameTenant(User $user, Invoice $invoice): bool
    {
        if (! empty($user->tenant_id) && $user->tenant_id === $invoice->tenant_id) {
            return true;
        }

        return $user->tenants()->where('tenants.id', $invoice->tenant_id)->exists();
    }
}
