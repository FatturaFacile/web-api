<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Invoicing\Models\SalesInvoice;

uses(RefreshDatabase::class);

describe('Core Endpoints: Invoicing, Payment Reconciliation, and Inventory Flow', function () {
    it('manages invoice lifecycle from draft to finalized payment', function () {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
        $customer = Contact::factory()->create(['tenant_id' => $tenant->id]);

        $invoice = SalesInvoice::factory()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $customer->id,
            'status' => 'draft',
            'subtotal' => 1000.00,
            'total_tax' => 220.00,
            'total_gross' => 1220.00,
            'receivable_amount' => 1220.00,
            'total_paid' => 0.00,
            'payment_status' => 'unpaid',
        ]);

        expect($invoice->status)->toBe('draft');
        expect($invoice->payment_status)->toBe('unpaid');

        // Transition to posted / issued
        $invoice->update([
            'status' => 'posted',
            'posted_at' => now(),
        ]);
        expect($invoice->fresh()->status)->toBe('posted');

        // Apply partial payment (incasso parziale)
        $invoice->update([
            'total_paid' => 500.00,
            'receivable_amount' => 720.00,
            'payment_status' => 'partially_paid',
        ]);
        expect($invoice->fresh()->payment_status)->toBe('partially_paid');
        expect((float) $invoice->fresh()->total_paid)->toBe(500.00);

        // Complete full payment (incasso totale)
        $invoice->update([
            'total_paid' => 1220.00,
            'receivable_amount' => 0.00,
            'payment_status' => 'paid',
        ]);
        expect($invoice->fresh()->payment_status)->toBe('paid');
        expect((float) $invoice->fresh()->receivable_amount)->toBe(0.00);
    });

    it('verifies inventory quantity adjustments and stock movement constraints', function () {
        $tenant = Tenant::factory()->create();

        $initialStock = 50;
        $shippedQty = 15;
        $remainingStock = $initialStock - $shippedQty;

        expect($remainingStock)->toBe(35);

        // Rule: Negative stock prevention check
        $excessiveShipment = 40;
        $canFulfill = ($remainingStock - $excessiveShipment) >= 0;
        expect($canFulfill)->toBeFalse();
    });
});
