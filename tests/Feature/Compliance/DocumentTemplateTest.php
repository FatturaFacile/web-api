<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\DocumentTemplate;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

function templatePng(): string
{
    return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
}

function renderedLayout(string $tenantId): string
{
    $branding = DocumentTemplate::brandingFor($tenantId, 'invoice');

    return view('documents.layout', ['type' => 'invoice', 'number' => 'FT-1', 'tenant' => Tenant::find($tenantId), 'tenant_address_lines' => [], 'contact_name' => '', 'address_lines' => [], 'branding' => $branding])->render();
}

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    actingAsMember($this->tenant);
});

test('a type override wins field by field over the default row', function (): void {
    $this->putJson('/api/v1/settings/document-templates/default', ['primary_color' => '#112233', 'footer_text' => 'Generale'])->assertOk();
    $this->putJson('/api/v1/settings/document-templates/invoice', ['footer_text' => 'Solo fatture'])->assertOk();

    expect(DocumentTemplate::brandingFor($this->tenant->id, 'invoice'))->toMatchArray(['primary_color' => '#112233', 'footer_text' => 'Solo fatture'])
        ->and(DocumentTemplate::brandingFor($this->tenant->id, 'quote')['footer_text'])->toBe('Generale');

    $this->deleteJson('/api/v1/settings/document-templates/invoice')->assertOk();
    expect(DocumentTemplate::brandingFor($this->tenant->id, 'invoice')['footer_text'])->toBe('Generale');
});

test('the layout shows colours, logo and footer', function (): void {
    $this->putJson('/api/v1/settings/document-templates/default', ['primary_color' => '#112233', 'secondary_color' => '#445566', 'footer_text' => 'Grazie', 'logo_base64' => templatePng()])->assertOk();

    expect(renderedLayout($this->tenant->id))->toContain('color: #112233')->toContain('background: #445566')->toContain('Grazie')->toContain('data:image/png;base64,');
});

test('user text is not compiled: Blade, PHP and markup stay inert', function (): void {
    $this->putJson('/api/v1/settings/document-templates/default', ['footer_text' => "{{ 7*7 }} @php echo 'x'; @endphp <script>alert(1)</script> <b>ok</b>"])->assertOk();

    $html = renderedLayout($this->tenant->id);

    expect($html)->not->toContain('<script>')->not->toContain('<b>ok</b>')->not->toContain('>49<')->toContain('{{ 7*7 }}');
});

test('bad colours, SVG and fake logos are refused', function (array $payload, string $field): void {
    $this->putJson('/api/v1/settings/document-templates/default', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'css injection' => [['primary_color' => 'red;}body{display:none'], 'primary_color'],
    'svg' => [['logo_base64' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>')], 'logo_base64'],
    'not an image' => [['logo_base64' => base64_encode('<?php echo 1;')], 'logo_base64'],
]);

test('unknown document types are refused', function (): void {
    $this->putJson('/api/v1/settings/document-templates/anything', [])->assertUnprocessable();
});

test('a real PDF renders with the branding', function (): void {
    $this->putJson('/api/v1/settings/document-templates/default', ['primary_color' => '#112233', 'footer_text' => 'Grazie', 'logo_base64' => templatePng()])->assertOk();

    $contact = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'customer', 'company_name' => 'Cliente']);
    $invoice = new Invoice(['contact_id' => $contact->id, 'invoice_type' => 'invoice', 'invoice_number' => 'FT-1', 'date' => '2027-01-15', 'due_date' => '2027-02-15', 'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122, 'total_paid' => 0, 'payment_status' => 'unpaid']);
    $invoice->tenant_id = $this->tenant->id;
    $invoice->save();

    expect($this->get("/api/v1/documents/invoice/{$invoice->id}/pdf")->assertOk()->headers->get('Content-Type'))->toContain('pdf');
});

test('templates are isolated per tenant and managed by owners and admins', function (): void {
    $this->putJson('/api/v1/settings/document-templates/default', ['footer_text' => 'Alfa'])->assertOk();

    actingAsMember(complianceTenant('Beta'));
    expect($this->getJson('/api/v1/settings/document-templates')->json('data'))->toBe([]);

    actingAsMember($this->tenant, 'accountant');
    $this->putJson('/api/v1/settings/document-templates/default', ['footer_text' => 'Nope'])->assertForbidden();
});
