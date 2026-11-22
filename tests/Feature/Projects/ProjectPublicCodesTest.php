<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Invoicing\InvoiceService;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Projects\Models\Project;
use Modules\SDI\Services\FatturaPaSchemaValidator;

require_once __DIR__.'/ProjectsSupport.php';

uses(RefreshDatabase::class);

const WBS_CUP = 'J81B19000780001';
const WBS_CIG = '7654321ABC';

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * CUP and CIG on the master data of a project, and their propagation to the purchase orders of the project.
 */
describe('purchase orders', function (): void {
    beforeEach(fn () => projectsSetUp($this));

    test('a project stores CUP, CIG and the FatturaPA block that carries them, and refuses malformed codes', function (): void {
        $created = $this->postJson('/api/v1/projects', [
            'contact_id' => $this->contact->id, 'project_number' => 'PRJ-PA-1', 'name' => 'Public works', 'project_type' => 'construction',
            'billing_method' => 'milestone_based', 'planned_start_date' => '2027-01-04', 'planned_end_date' => '2027-06-30',
            'project_manager_user_id' => $this->manager->id, 'contract_value' => 50000,
            'cup_code' => WBS_CUP, 'cig_code' => WBS_CIG, 'sdi_reference_block' => 'convention',
        ])->assertCreated()
            ->assertJsonPath('data.cup_code', WBS_CUP)->assertJsonPath('data.cig_code', WBS_CIG)->assertJsonPath('data.sdi_reference_block', 'convention');

        $this->postJson('/api/v1/projects', [
            'contact_id' => $this->contact->id, 'project_number' => 'PRJ-PA-2', 'name' => 'Bad codes', 'project_type' => 'construction',
            'billing_method' => 'milestone_based', 'planned_start_date' => '2027-01-04', 'planned_end_date' => '2027-06-30',
            'project_manager_user_id' => $this->manager->id, 'contract_value' => 1, 'cup_code' => 'lowercase', 'cig_code' => 'TOO-LONG-CIG-CODE',
        ])->assertUnprocessable()->assertJsonValidationErrors(['cup_code', 'cig_code']);

        $this->putJson('/api/v1/projects/'.$created->json('data.id'), ['cig_code' => null])->assertOk()->assertJsonPath('data.cig_code', null)->assertJsonPath('data.cup_code', WBS_CUP);
    });

    test('a purchase order opened on a project takes its CUP and CIG unless it has its own', function (): void {
        $project = projectsProject($this, ['cup_code' => WBS_CUP, 'cig_code' => WBS_CIG]);

        $inherited = PurchaseOrder::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id]);
        $own = PurchaseOrder::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'cup_code' => 'ZZZZZZZZZZZZZZZ']);
        $unrelated = PurchaseOrder::factory()->create(['tenant_id' => $this->tenant->id]);

        expect($inherited->refresh()->cup_code)->toBe(WBS_CUP)->and($inherited->cig_code)->toBe(WBS_CIG)
            ->and($own->refresh()->cup_code)->toBe('ZZZZZZZZZZZZZZZ')->and($own->cig_code)->toBeNull()
            ->and($unrelated->refresh()->cup_code)->toBeNull();

        // An order that is moved onto the project later inherits the codes as well.
        $unrelated->update(['project_id' => $project->id]);

        expect($unrelated->refresh()->cig_code)->toBe(WBS_CIG);
    });

    test('changing the codes of a project updates its open purchase orders but not cancelled or closed ones', function (): void {
        $project = projectsProject($this, ['cup_code' => WBS_CUP, 'cig_code' => WBS_CIG]);
        $draft = PurchaseOrder::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id]);
        $approved = PurchaseOrder::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'status' => 'approved']);
        $cancelled = PurchaseOrder::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'status' => 'cancelled']);
        $closed = PurchaseOrder::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'status' => 'closed']);
        $other = PurchaseOrder::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => projectsProject($this, ['cup_code' => 'A11B22000330001'])->id]);

        $this->putJson("/api/v1/projects/{$project->id}", ['cup_code' => 'B22C33000440002', 'cig_code' => '9988776655'])->assertOk();

        expect($draft->refresh()->cup_code)->toBe('B22C33000440002')->and($draft->cig_code)->toBe('9988776655')
            ->and($approved->refresh()->cup_code)->toBe('B22C33000440002')
            ->and($cancelled->refresh()->cup_code)->toBe(WBS_CUP)
            ->and($closed->refresh()->cup_code)->toBe(WBS_CUP)
            ->and($other->refresh()->cup_code)->toBe('A11B22000330001');

        // Removing the codes from the project clears them on the open orders too.
        $this->putJson("/api/v1/projects/{$project->id}", ['cup_code' => null, 'cig_code' => null])->assertOk();

        expect($draft->refresh()->cup_code)->toBeNull()->and($draft->cig_code)->toBeNull();
    });
});

/**
 * The FatturaPA of an invoice that belongs to a project carries the CUP and CIG in DatiContratto / DatiConvenzione.
 */
describe('electronic invoices', function (): void {
    beforeEach(function (): void {
        $this->tenant = Tenant::create([
            'name' => 'Rossi', 'legal_name' => 'Rossi', 'vat_number' => '00811720580', 'fiscal_code' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR',
            'settings' => ['street' => 'Via Verdi 1', 'postal_code' => '20121', 'city' => 'Milano', 'province' => 'MI'],
        ]);
        $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Comune di Bianchi', 'country' => 'IT', 'vat_number' => '01234567897', 'sdi_code' => 'ABC1234']);
        $this->customer->addresses()->create(['tenant_id' => $this->tenant->id, 'type' => 'billing', 'street' => 'Via Dante 2', 'postal_code' => '00100', 'city' => 'Roma', 'country' => 'IT']);
        $this->manager = User::create(['name' => 'PM', 'email' => Str::uuid().'@example.test', 'password' => 'secret123']);

        app(TenantContext::class)->setTenant($this->tenant);
        fiscalCalendar($this->tenant);
    });

    /**
     * @param  array<string, mixed>  $attributes
     */
    function wbsProjectInvoice(object $test, array $attributes = []): Invoice
    {
        $project = Project::factory()->create([
            'tenant_id' => $test->tenant->id, 'company_id' => $test->tenant->id, 'contact_id' => $test->customer->id,
            'project_manager_user_id' => $test->manager->id, 'project_number' => 'PRJ-PA-1', 'status' => 'active',
            ...$attributes,
        ]);

        $invoice = app(InvoiceService::class)->createInvoice([
            'contact_id' => $test->customer->id, 'invoice_type' => 'standard', 'date' => '2027-03-01', 'currency' => 'EUR', 'jurisdiction' => 'IT',
            'items' => [['description' => 'Works', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 22]],
        ]);
        DB::table('invoices')->where('id', $invoice->id)->update(['project_id' => $project->id]);

        return $invoice->fresh();
    }

    test('the project CUP and CIG are written in DatiContratto and the XML stays valid', function (): void {
        $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml(wbsProjectInvoice($this, ['cup_code' => WBS_CUP, 'cig_code' => WBS_CIG]));

        expect(app(FatturaPaSchemaValidator::class)->errors($xml))->toBe([])
            ->and($xml)->toContain('<DatiContratto>')
            ->and($xml)->toContain('<IdDocumento>PRJ-PA-1</IdDocumento>')
            ->and($xml)->toContain('<CodiceCUP>'.WBS_CUP.'</CodiceCUP>')
            ->and($xml)->toContain('<CodiceCIG>'.WBS_CIG.'</CodiceCIG>')
            ->and($xml)->not->toContain('<DatiConvenzione>');
    });

    test('a project billed under a framework agreement uses DatiConvenzione', function (): void {
        $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml(wbsProjectInvoice($this, ['cup_code' => WBS_CUP, 'cig_code' => null, 'sdi_reference_block' => 'convention']));

        expect(app(FatturaPaSchemaValidator::class)->errors($xml))->toBe([])
            ->and($xml)->toContain('<DatiConvenzione>')
            ->and($xml)->toContain('<CodiceCUP>'.WBS_CUP.'</CodiceCUP>')
            ->and($xml)->not->toContain('<CodiceCIG>')
            ->and($xml)->not->toContain('<DatiContratto>');
    });

    test('a project without codes and an invoice without a project add no block', function (): void {
        $withoutCodes = app(FatturaPaBuilderService::class)->buildFatturaPaXml(wbsProjectInvoice($this));
        $noProject = app(FatturaPaBuilderService::class)->buildFatturaPaXml(app(InvoiceService::class)->createInvoice([
            'contact_id' => $this->customer->id, 'invoice_type' => 'standard', 'date' => '2027-03-02', 'currency' => 'EUR', 'jurisdiction' => 'IT',
            'items' => [['description' => 'Works', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22]],
        ]));

        expect($withoutCodes)->not->toContain('<DatiContratto>')->and($noProject)->not->toContain('<DatiContratto>');
    });

    test('a contract block entered by hand on the invoice is kept and not duplicated', function (): void {
        $invoice = wbsProjectInvoice($this, ['cup_code' => WBS_CUP, 'cig_code' => WBS_CIG]);
        DB::table('invoices')->where('id', $invoice->id)->update(['sdi_extra' => json_encode(['FatturaElettronicaBody' => ['DatiGenerali' => ['DatiContratto' => ['IdDocumento' => 'MANUAL-1', 'CodiceCIG' => 'MANUALCIG1']]]])]);

        $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml($invoice->fresh());

        expect(substr_count($xml, '<DatiContratto>'))->toBe(1)
            ->and($xml)->toContain('<IdDocumento>MANUAL-1</IdDocumento>')
            ->and($xml)->not->toContain('<CodiceCUP>'.WBS_CUP.'</CodiceCUP>');
    });
});
