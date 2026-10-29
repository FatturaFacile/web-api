<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Product;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');
});

function demoTenant(): Tenant
{
    return Tenant::where('vat_number', 'IT01234567890')->firstOrFail();
}

it('creates a demo company the owner can sign in to', function (): void {
    $this->seed(DemoSeeder::class);

    $user = User::where('email', DemoSeeder::EMAIL)->firstOrFail();
    $tenant = demoTenant();

    expect(Hash::check('password', $user->password))->toBeTrue()
        ->and($user->tenants()->first()?->id)->toBe($tenant->id)
        ->and($user->tenants()->first()?->pivot->role)->toBe('owner');
});

it('fills the demo company with consistent documents', function (): void {
    $this->seed(DemoSeeder::class);
    $tenantId = demoTenant()->id;

    $invoices = Invoice::withoutGlobalScopes()->where('tenant_id', $tenantId)->with('items')->get();

    expect(Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe(10)
        ->and(Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe(10)
        ->and(StockMovement::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe(7)
        ->and(Quote::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe(5)
        ->and(SalesOrder::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe(2)
        ->and(PassiveInvoice::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe(5)
        ->and($invoices)->toHaveCount(15)
        ->and($invoices->pluck('payment_status')->unique()->sort()->values()->all())->toBe(['paid', 'partially_paid', 'unpaid']);

    foreach ($invoices as $invoice) {
        expect(round((float) $invoice->items->sum('line_total'), 2))->toEqual(round((float) $invoice->total_net, 2))
            ->and((float) $invoice->total_paid)->toBeLessThanOrEqual((float) $invoice->total_gross);
    }

    // Gapless numbering per fiscal year, as the API would assign it.
    $invoices->groupBy('fiscal_year')->each(function ($yearInvoices): void {
        expect($yearInvoices->pluck('sequential_number')->sort()->values()->all())->toBe(range(1, $yearInvoices->count()));
    });
});

it('does not create the demo company twice', function (): void {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(Tenant::where('vat_number', 'IT01234567890')->count())->toBe(1);
});
