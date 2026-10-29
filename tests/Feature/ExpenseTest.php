<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Expense;
use App\Models\LookupItem;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\ComprehensiveLookupMasterSeeder;
use Database\Seeders\ExpenseLookupSeeder;
use Database\Seeders\ServiceReportLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ComprehensiveLookupMasterSeeder::class);
    $this->seed(ExpenseLookupSeeder::class);
    $this->seed(ServiceReportLookupSeeder::class);

    Storage::fake(config('filesystems.private_disk', 'local'));

    $this->tenantA = Tenant::create([
        'name' => 'Acme Electricians SRL',
        'legal_name' => 'Acme Electricians SRL',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenantA);

    $this->userA = User::factory()->create([
        'email' => 'artisan@acme.it',
    ]);
    $this->userA->tenants()->attach($this->tenantA->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    $this->supplierA = Contact::create([
        'tenant_id' => $this->tenantA->id,
        'company_name' => 'Bricofer Hardware SRL',
        'vat_number' => 'IT01234567890',
        'type' => 'supplier',
        'country' => 'IT',
        'email' => 'sales@bricofer.it',
    ]);

    // Foreign Tenant B
    $this->tenantB = Tenant::create([
        'name' => 'Competitor Plumbers Sagl',
        'legal_name' => 'Competitor Plumbers Sagl',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);

    $this->userB = User::factory()->create([
        'email' => 'rival@competitor.ch',
    ]);
    $this->userB->tenants()->attach($this->tenantB->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
});

test('artisan can register an expense in <30 seconds with receipt photo upload', function (): void {
    $fuelCategory = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'expense_category'))
        ->where('code', 'fuel')
        ->firstOrFail();

    $receiptPhoto = UploadedFile::fake()->image('fuel_receipt.jpg', 800, 1200);

    $response = $this->actingAs($this->userA)
        ->postJson('/api/v1/expenses', [
            'category_id' => $fuelCategory->id,
            'merchant_name' => 'Eni Station Tangenziale',
            'amount_gross' => 85.50,
            'tax_rate' => 22.0,
            'date' => '2026-10-28',
            'payment_method' => 'company_card',
            'receipt' => $receiptPhoto,
            'notes' => 'Diesel refuel for company van #4',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.merchant_name', 'Eni Station Tangenziale')
        ->assertJsonPath('data.amount_gross', 85.5)
        ->assertJsonPath('data.currency', 'EUR')
        ->assertJsonPath('data.payment_method', 'company_card');

    $expenseId = $response->json('data.id');
    $expense = Expense::with('expenseAttachments')->findOrFail($expenseId);

    expect($expense->expense_number)->toStartWith('EXP-')
        ->and($expense->tenant_id)->toBe($this->tenantA->id)
        ->and((float) $expense->amount_gross)->toBe(85.5)
        ->and((float) $expense->tax_rate)->toBe(22.0)
        ->and((float) $expense->amount_net)->toBe(70.08)
        ->and((float) $expense->tax_amount)->toBe(15.42)
        ->and($expense->expenseAttachments)->toHaveCount(1);

    $disk = config('filesystems.private_disk', 'local');
    Storage::disk($disk)->assertExists($expense->expenseAttachments->first()->path);
});

test('expense amounts calculate net, tax and gross accurately from either input', function (): void {
    $materialsCategory = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'expense_category'))
        ->where('code', 'materials')
        ->firstOrFail();

    // Net provided -> calculates gross
    $responseNet = $this->actingAs($this->userA)
        ->postJson('/api/v1/expenses', [
            'category_id' => $materialsCategory->id,
            'amount_net' => 100.00,
            'tax_rate' => 22.0,
            'date' => '2026-10-28',
        ]);
    $responseNet->assertStatus(201)
        ->assertJsonPath('data.amount_net', 100)
        ->assertJsonPath('data.tax_amount', 22)
        ->assertJsonPath('data.amount_gross', 122);

    // Gross provided -> calculates net backwards
    $responseGross = $this->actingAs($this->userA)
        ->postJson('/api/v1/expenses', [
            'category_id' => $materialsCategory->id,
            'amount_gross' => 122.00,
            'tax_rate' => 22.0,
            'date' => '2026-10-28',
        ]);
    $responseGross->assertStatus(201)
        ->assertJsonPath('data.amount_net', 100)
        ->assertJsonPath('data.tax_amount', 22)
        ->assertJsonPath('data.amount_gross', 122);
});

test('expense links to project, ticket and service report and enforces tenant ownership', function (): void {
    $tollsCategory = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'expense_category'))
        ->where('code', 'tolls')
        ->firstOrFail();

    $client = Contact::create([
        'tenant_id' => $this->tenantA->id,
        'company_name' => 'Condominio Milano',
        'type' => 'company',
        'country' => 'IT',
    ]);

    $ticket = Ticket::create([
        'tenant_id' => $this->tenantA->id,
        'client_id' => $client->id,
        'subject' => 'Intervento guasto quadro elettrico',
        'priority' => 'high',
        'status' => 'open',
    ]);

    $srStatus = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'service_report_status'))->first();

    $serviceReport = ServiceReport::create([
        'tenant_id' => $this->tenantA->id,
        'contact_id' => $client->id,
        'status_id' => $srStatus?->id ?? LookupItem::first()?->id,
        'report_number' => 'SR-2026-0001',
        'report_date' => '2026-10-28',
        'technician_id' => $this->userA->id,
        'currency' => 'EUR',
        'total_gross' => 150.0,
    ]);

    $projectId = (string) Str::uuid();

    $response = $this->actingAs($this->userA)
        ->postJson('/api/v1/expenses', [
            'category_id' => $tollsCategory->id,
            'merchant_name' => 'Autostrade per l\'Italia',
            'amount_gross' => 14.80,
            'tax_rate' => 22.0,
            'contact_id' => $this->supplierA->id,
            'project_id' => $projectId,
            'ticket_id' => $ticket->id,
            'service_report_id' => $serviceReport->id,
            'is_billable' => true,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.project_id', $projectId)
        ->assertJsonPath('data.ticket_id', $ticket->id)
        ->assertJsonPath('data.service_report_id', $serviceReport->id)
        ->assertJsonPath('data.is_billable', true);

    // Cross-tenant ticket reject verification
    app(TenantContext::class)->setTenant($this->tenantB);
    $foreignClient = Contact::create([
        'tenant_id' => $this->tenantB->id,
        'company_name' => 'Foreign Client',
        'country' => 'CH',
    ]);
    $foreignTicket = Ticket::create([
        'tenant_id' => $this->tenantB->id,
        'client_id' => $foreignClient->id,
        'subject' => 'Swiss ticket',
        'status' => 'open',
    ]);
    app(TenantContext::class)->setTenant($this->tenantA);

    $crossTenantResponse = $this->actingAs($this->userA)
        ->postJson('/api/v1/expenses', [
            'category_id' => $tollsCategory->id,
            'amount_gross' => 20.00,
            'ticket_id' => $foreignTicket->id,
        ]);
    $crossTenantResponse->assertStatus(422)
        ->assertJsonValidationErrors(['ticket_id']);
});

test('can upload and download multiple receipts including PDF vouchers', function (): void {
    $category = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'expense_category'))->firstOrFail();

    $expense = Expense::create([
        'tenant_id' => $this->tenantA->id,
        'expense_number' => 'EXP-2026-0099',
        'category_id' => $category->id,
        'date' => '2026-10-28',
        'amount_gross' => 45.0,
        'currency' => 'EUR',
    ]);

    $pdfFile = UploadedFile::fake()->create('invoice_voucher.pdf', 300, 'application/pdf');

    $uploadRes = $this->actingAs($this->userA)
        ->postJson("/api/v1/expenses/{$expense->id}/attachments", [
            'file' => $pdfFile,
            'is_receipt' => true,
        ]);

    $uploadRes->assertStatus(201);
    $attachmentId = $uploadRes->json('data.0.id');

    // Download attachment
    $downloadRes = $this->actingAs($this->userA)
        ->get("/api/v1/expenses/{$expense->id}/attachments/{$attachmentId}/download");

    $downloadRes->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('updating and deleting expense works and is blocked once exported to accountant', function (): void {
    $category = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'expense_category'))->firstOrFail();

    $expense = Expense::create([
        'tenant_id' => $this->tenantA->id,
        'expense_number' => 'EXP-2026-0100',
        'category_id' => $category->id,
        'date' => '2026-10-20',
        'amount_gross' => 50.0,
        'currency' => 'EUR',
        'merchant_name' => 'Original Hardware',
    ]);

    // Update before export succeeds
    $updateRes = $this->actingAs($this->userA)
        ->putJson("/api/v1/expenses/{$expense->id}", [
            'merchant_name' => 'Corrected Hardware Store',
            'amount_gross' => 60.0,
        ]);
    $updateRes->assertOk()
        ->assertJsonPath('data.merchant_name', 'Corrected Hardware Store')
        ->assertJsonPath('data.amount_gross', 60);

    // Export to accountant
    $expense->update(['accounting_exported_at' => now()]);
    $expense->refresh();

    // Mutation is now forbidden by policy to protect fiscal audit trail
    expect(Gate::forUser($this->userA)->allows('update', $expense))->toBeFalse()
        ->and(Gate::forUser($this->userA)->allows('delete', $expense))->toBeFalse();

    $this->actingAs($this->userA)
        ->putJson("/api/v1/expenses/{$expense->id}", ['merchant_name' => 'Tampered'])
        ->assertStatus(403);

    $this->actingAs($this->userA)
        ->deleteJson("/api/v1/expenses/{$expense->id}")
        ->assertStatus(403);
});

test('accountant export generates CSV with statutory columns and stamps exported timestamp', function (): void {
    $toolsCategory = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'expense_category'))
        ->where('code', 'tools')
        ->firstOrFail();

    Expense::create([
        'tenant_id' => $this->tenantA->id,
        'expense_number' => 'EXP-2026-0201',
        'category_id' => $toolsCategory->id,
        'date' => '2026-10-15',
        'amount_net' => 200.0,
        'tax_rate' => 22.0,
        'tax_amount' => 44.0,
        'amount_gross' => 244.0,
        'currency' => 'EUR',
        'merchant_name' => 'Hilti Store',
    ]);

    $exportRes = $this->actingAs($this->userA)
        ->get('/api/v1/expenses/export?format=csv&mark_as_exported=1');

    $exportRes->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $csv = $exportRes->getContent();
    expect($csv)->toContain('Expense Number')
        ->and($csv)->toContain('EXP-2026-0201')
        ->and($csv)->toContain('Hilti Store')
        ->and($csv)->toContain('244.00');

    $exportedExpense = Expense::where('expense_number', 'EXP-2026-0201')->firstOrFail();
    expect($exportedExpense->isExported())->toBeTrue();
});

test('reporting summary provides monthly, category, project and supplier aggregations', function (): void {
    $fuel = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'expense_category'))
        ->where('code', 'fuel')
        ->firstOrFail();
    $parking = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'expense_category'))
        ->where('code', 'parking')
        ->firstOrFail();

    $projId = (string) Str::uuid();

    Expense::create([
        'tenant_id' => $this->tenantA->id,
        'expense_number' => 'EXP-2026-0301',
        'category_id' => $fuel->id,
        'date' => '2026-10-01',
        'amount_gross' => 70.0,
        'currency' => 'EUR',
        'merchant_name' => 'Shell',
        'project_id' => $projId,
    ]);

    Expense::create([
        'tenant_id' => $this->tenantA->id,
        'expense_number' => 'EXP-2026-0302',
        'category_id' => $parking->id,
        'date' => '2026-10-02',
        'amount_gross' => 15.0,
        'currency' => 'EUR',
        'merchant_name' => 'City Parking',
    ]);

    $summaryRes = $this->actingAs($this->userA)
        ->getJson('/api/v1/expenses/reports/summary?year=2026');

    $summaryRes->assertOk()
        ->assertJsonPath('data.total_expenses', 85)
        ->assertJsonPath('data.total_count', 2);

    $categories = collect($summaryRes->json('data.by_category'));
    expect($categories->where('code', 'fuel')->first()['total_gross'])->toBe(70)
        ->and($categories->where('code', 'parking')->first()['total_gross'])->toBe(15);
});

test('multi-tenant isolation prevents cross-tenant access and parameter injection', function (): void {
    $category = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'expense_category'))->firstOrFail();

    $expenseA = Expense::create([
        'tenant_id' => $this->tenantA->id,
        'expense_number' => 'EXP-2026-0401',
        'category_id' => $category->id,
        'date' => '2026-10-28',
        'amount_gross' => 100.0,
        'currency' => 'EUR',
    ]);

    // User B cannot access Tenant A's expense (returns 404 or 403)
    $foreignShow = $this->actingAs($this->userB)
        ->getJson("/api/v1/expenses/{$expenseA->id}");
    expect(in_array($foreignShow->status(), [403, 404], true))->toBeTrue();

    // User B cannot update Tenant A's expense
    $foreignUpdate = $this->actingAs($this->userB)
        ->putJson("/api/v1/expenses/{$expenseA->id}", ['merchant_name' => 'Hacked']);
    expect(in_array($foreignUpdate->status(), [403, 404], true))->toBeTrue();

    // User B cannot delete Tenant A's expense
    $foreignDelete = $this->actingAs($this->userB)
        ->deleteJson("/api/v1/expenses/{$expenseA->id}");
    expect(in_array($foreignDelete->status(), [403, 404], true))->toBeTrue();

    // User A cannot inject Tenant B's tenant_id into store payload
    $createWithInjection = $this->actingAs($this->userA)
        ->postJson('/api/v1/expenses', [
            'tenant_id' => $this->tenantB->id,
            'category_id' => $category->id,
            'amount_gross' => 50.0,
        ]);
    $createdExpense = Expense::findOrFail($createWithInjection->json('data.id'));
    expect($createdExpense->tenant_id)->toBe($this->tenantA->id);
});
