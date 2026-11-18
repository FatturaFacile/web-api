<?php

declare(strict_types=1);

use App\Mail\PortalMagicLinkMail;
use App\Mail\PortalOtpMail;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PortalAccessToken;
use App\Models\PortalActivityLog;
use App\Models\PortalSetting;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Portal\PortalTokenService;
use App\Services\Tenancy\TenantContext;
use App\Support\Portal\CarrierTrackingUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * A signed-in portal session for the given contact, bypassing the e-mail step.
 */
function portalSessionFor(Contact $contact): string
{
    app(TenantContext::class)->setTenant(Tenant::withoutGlobalScopes()->findOrFail($contact->tenant_id));

    return app(PortalTokenService::class)->createToken($contact, PortalTokenService::SESSION_TOKEN_TYPE, expiresAt: now()->addHours(8))['token'];
}

function portalLoginToken(Contact $contact, ?DateTimeInterface $expiresAt = null): string
{
    app(TenantContext::class)->setTenant(Tenant::withoutGlobalScopes()->findOrFail($contact->tenant_id));

    return app(PortalTokenService::class)->createToken($contact, PortalTokenService::LOGIN_TOKEN_TYPE, expiresAt: $expiresAt ?? now()->addMinutes(15), isSingleUse: true)['token'];
}

beforeEach(function (): void {
    $this->travelTo('2026-05-15 10:00:00');
    Storage::fake('local');
    Mail::fake();
    Cache::flush(); // the sign-in routes are rate limited per IP; every test starts with a clean counter

    $this->tenant = Tenant::create(['name' => 'Solar Installers Sagl', 'legal_name' => 'Solar Installers Sagl', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->otherTenant = Tenant::create(['name' => 'Other Company', 'legal_name' => 'Other Company', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->setting = PortalSetting::create([
        'tenant_id' => $this->tenant->id,
        'portal_slug' => 'solar',
        'logo_url' => 'https://cdn.example.test/logo.png',
        'primary_color' => '#0a7cff',
        'secondary_color' => '#112233',
    ]);

    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Villa Paradiso', 'country' => 'CH', 'email' => 'client@villaparadiso.ch']);
    $this->stranger = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Casa Rossa', 'country' => 'CH', 'email' => 'other@casarossa.ch']);

    $this->makeInvoice = fn (Contact $contact, string $number, string $status = 'unpaid'): Invoice => Invoice::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $contact->id,
        'invoice_type' => 'standard',
        'invoice_number' => $number,
        'date' => '2026-05-01',
        'due_date' => '2026-05-10',
        'currency' => 'CHF',
        'total_net' => 800.00,
        'total_tax' => 64.80,
        'total_gross' => 864.80,
        'payment_status' => $status,
    ]);
});

describe('white-label branding', function (): void {
    it('serves the theme of a portal by slug without any login', function (): void {
        $this->getJson('/api/v1/portal/branding?slug=solar')
            ->assertOk()
            ->assertJsonPath('data.primary_color', '#0a7cff')
            ->assertJsonPath('data.company_name', 'Solar Installers Sagl')
            ->assertJsonMissingPath('data.require_otp');
    });

    it('resolves the portal from the custom domain of the request', function (): void {
        $this->setting->update(['custom_domain' => 'clienti.solar.example']);

        $this->getJson('https://clienti.solar.example/api/v1/portal/branding')
            ->assertOk()
            ->assertJsonPath('data.portal_slug', 'solar');
    });

    it('answers 404 for an unknown slug or domain', function (): void {
        $this->getJson('/api/v1/portal/branding?slug=nobody')->assertNotFound();
        $this->getJson('/api/v1/portal/branding')->assertNotFound();
    });
});

describe('magic link sign-in', function (): void {
    it('mails a link to a known customer and answers the same for an unknown address', function (): void {
        $this->postJson('/api/v1/portal/auth/request-link?slug=solar', ['email' => 'CLIENT@villaparadiso.ch'])->assertStatus(202);
        $this->postJson('/api/v1/portal/auth/request-link?slug=solar', ['email' => 'nobody@nowhere.ch'])->assertStatus(202);

        Mail::assertQueued(PortalMagicLinkMail::class, 1);
        expect(PortalAccessToken::withoutGlobalScopes()->where('token_type', 'portal_login')->first())
            ->is_single_use->toBeTrue()
            ->expires_at->toEqual(now()->addMinutes(15));
    });

    it('never mails a customer of another tenant', function (): void {
        $this->postJson('/api/v1/portal/auth/request-link?slug=solar', ['email' => 'client@other.example'])->assertStatus(202);

        Mail::assertNothingQueued();
    });

    it('exchanges the link for a session, records consent and burns the link', function (): void {
        $link = portalLoginToken($this->customer);

        $response = $this->postJson('/api/v1/portal/auth/verify', ['token' => $link, 'accept_privacy' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'authenticated')
            ->assertJsonPath('data.abilities.0', 'portal:customer');

        expect($this->customer->fresh()->portal_consent_at)->not->toBeNull();
        expect(PortalActivityLog::withoutGlobalScopes()->where('action', 'portal_login')->count())->toBe(1);

        $this->withToken($response->json('data.token'))->getJson('/api/v1/portal/me')->assertOk();
        $this->postJson('/api/v1/portal/auth/verify', ['token' => $link])->assertStatus(410);
    });

    it('rejects an expired link', function (): void {
        $link = portalLoginToken($this->customer, now()->addMinutes(15));
        $this->travel(16)->minutes();

        $this->postJson('/api/v1/portal/auth/verify', ['token' => $link])->assertStatus(410);
    });

    it('does not accept a session or a dashboard token as a magic link', function (): void {
        $session = portalSessionFor($this->customer);
        $dashboard = app(PortalTokenService::class)->createToken($this->customer)['token'];

        $this->postJson('/api/v1/portal/auth/verify', ['token' => $session])->assertNotFound();
        $this->postJson('/api/v1/portal/auth/verify', ['token' => $dashboard])->assertNotFound();
    });

    it('does not accept a magic link or a legacy token as a session', function (): void {
        $this->withToken(portalLoginToken($this->customer))->getJson('/api/v1/portal/me')->assertUnauthorized();
        $this->withToken(app(PortalTokenService::class)->createToken($this->customer)['token'])->getJson('/api/v1/portal/me')->assertUnauthorized();
        $this->getJson('/api/v1/portal/me')->assertUnauthorized();
    });

    it('revokes the session on logout', function (): void {
        $session = portalSessionFor($this->customer);

        $this->withToken($session)->postJson('/api/v1/portal/auth/logout')->assertOk();
        $this->withToken($session)->getJson('/api/v1/portal/me')->assertUnauthorized();
    });

    it('asks for an e-mailed code when the tenant policy requires it', function (): void {
        $this->setting->update(['require_otp' => true]);
        $link = portalLoginToken($this->customer);
        $code = null;

        $this->postJson('/api/v1/portal/auth/verify', ['token' => $link])->assertOk()->assertJsonPath('data.status', 'otp_required');
        Mail::assertQueued(PortalOtpMail::class, function (PortalOtpMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        $this->postJson('/api/v1/portal/auth/verify', ['token' => $link, 'otp' => $code === '000000' ? '111111' : '000000'])->assertStatus(422);
        $this->postJson('/api/v1/portal/auth/verify', ['token' => $link, 'otp' => $code])->assertOk()->assertJsonPath('data.status', 'authenticated');
    });

    it('locks the link after too many wrong codes', function (): void {
        $this->setting->update(['require_otp' => true]);
        $link = portalLoginToken($this->customer);
        $this->postJson('/api/v1/portal/auth/verify', ['token' => $link])->assertOk();

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/portal/auth/verify', ['token' => $link, 'otp' => '123456'])->assertStatus(422);
        }

        $this->postJson('/api/v1/portal/auth/verify', ['token' => $link, 'otp' => '123456'])->assertStatus(429);
        $this->postJson('/api/v1/portal/auth/verify', ['token' => $link])->assertStatus(410);
    });
});

describe('customer isolation', function (): void {
    it('lists only the invoices of the signed-in customer', function (): void {
        ($this->makeInvoice)($this->customer, 'INV-OWN');
        ($this->makeInvoice)($this->stranger, 'INV-STRANGER');

        $numbers = collect($this->withToken(portalSessionFor($this->customer))->getJson('/api/v1/portal/invoices')->assertOk()->json('data'))->pluck('invoice_number');

        expect($numbers->all())->toBe(['INV-OWN']);
    });

    it('answers 404 for the documents of another customer', function (): void {
        $foreign = ($this->makeInvoice)($this->stranger, 'INV-STRANGER');
        $quote = Quote::create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->stranger->id, 'quote_number' => 'QT-1', 'date' => '2026-05-10', 'valid_until' => '2026-06-10', 'currency' => 'CHF', 'total_net' => 10, 'total_tax' => 0, 'total_gross' => 10, 'status' => 'sent']);
        $session = portalSessionFor($this->customer);

        $this->withToken($session)->getJson("/api/v1/portal/invoices/{$foreign->id}")->assertNotFound();
        $this->withToken($session)->getJson("/api/v1/portal/invoices/{$foreign->id}/pdf")->assertNotFound();
        $this->withToken($session)->getJson("/api/v1/portal/invoices/{$foreign->id}/xml")->assertNotFound();
        $this->withToken($session)->postJson("/api/v1/portal/invoices/{$foreign->id}/checkout")->assertNotFound();
        $this->withToken($session)->postJson("/api/v1/portal/quotes/{$quote->id}/approve", ['signer_name' => 'Intruder'])->assertNotFound();
        expect($quote->fresh()->status)->toBe('sent');
    });

    it('answers 404 for a customer of another tenant even with a valid id', function (): void {
        app(TenantContext::class)->setTenant($this->otherTenant);
        $foreignContact = Contact::create(['tenant_id' => $this->otherTenant->id, 'company_name' => 'Elsewhere', 'country' => 'IT', 'email' => 'x@elsewhere.it']);
        $foreign = Invoice::create(['tenant_id' => $this->otherTenant->id, 'contact_id' => $foreignContact->id, 'invoice_type' => 'standard', 'invoice_number' => 'INV-X', 'date' => '2026-05-01', 'due_date' => '2026-05-10', 'currency' => 'EUR', 'total_net' => 1, 'total_tax' => 0, 'total_gross' => 1, 'payment_status' => 'unpaid']);

        $this->withToken(portalSessionFor($this->customer))->getJson("/api/v1/portal/invoices/{$foreign->id}")->assertNotFound();
    });

    it('does not let a portal session reach the back-office API', function (): void {
        $this->withToken(portalSessionFor($this->customer))->getJson('/api/v1/auth/profile')->assertUnauthorized();
    });
});

describe('documents', function (): void {
    it('shows the dashboard with the open balance and overdue invoices', function (): void {
        ($this->makeInvoice)($this->customer, 'INV-1');
        ($this->makeInvoice)($this->customer, 'INV-2', 'paid');

        $this->withToken(portalSessionFor($this->customer))->getJson('/api/v1/portal/me')
            ->assertOk()
            ->assertJsonPath('data.open_invoices', 1)
            ->assertJsonPath('data.overdue_invoices', 1)
            ->assertJsonPath('data.open_balance.0.currency', 'CHF')
            ->assertJsonPath('data.open_balance.0.amount', '864.80');
    });

    it('filters invoices by payment status', function (): void {
        ($this->makeInvoice)($this->customer, 'INV-1');
        ($this->makeInvoice)($this->customer, 'INV-2', 'paid');

        $rows = $this->withToken(portalSessionFor($this->customer))->getJson('/api/v1/portal/invoices?payment_status=paid')->json('data');

        expect(collect($rows)->pluck('invoice_number')->all())->toBe(['INV-2']);
    });

    it('downloads the invoice PDF and logs the download', function (): void {
        $invoice = ($this->makeInvoice)($this->customer, 'INV-1');

        $this->withToken(portalSessionFor($this->customer))->get("/api/v1/portal/invoices/{$invoice->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        expect(PortalActivityLog::withoutGlobalScopes()->where('action', 'invoice_downloaded')->where('subject_id', $invoice->id)->exists())->toBeTrue();
    });

    it('answers 404 for the XML of an invoice that was never sent through SDI', function (): void {
        $invoice = ($this->makeInvoice)($this->customer, 'INV-1');

        $this->withToken(portalSessionFor($this->customer))->get("/api/v1/portal/invoices/{$invoice->id}/xml")->assertNotFound();
    });

    it('downloads the original FatturaPA XML when the invoice has one', function (): void {
        $invoice = ($this->makeInvoice)($this->customer, 'INV-1');
        $invoice->sdiMetadata()->create(['transmission_format' => 'FPR12', 'sdi_status' => 'delivered', 'file_name' => 'IT01234567890_00001.xml', 'xml_content' => '<p:FatturaElettronica/>']);

        $this->withToken(portalSessionFor($this->customer))->get("/api/v1/portal/invoices/{$invoice->id}/xml")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee('<p:FatturaElettronica/>', false);
    });

    it('approves a quote and keeps the audit trail', function (): void {
        $quote = Quote::create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->customer->id, 'quote_number' => 'QT-1', 'date' => '2026-05-10', 'valid_until' => '2026-06-10', 'currency' => 'CHF', 'total_net' => 10, 'total_tax' => 0, 'total_gross' => 10, 'status' => 'sent']);
        $session = portalSessionFor($this->customer);

        $this->withToken($session)->withHeader('User-Agent', 'PortalTest/1.0')->postJson("/api/v1/portal/quotes/{$quote->id}/approve", ['signer_name' => 'Mario Rossi'])->assertOk();

        expect($quote->fresh())->status->toBe('accepted')->signed_by->toBe('Mario Rossi');
        $log = PortalActivityLog::withoutGlobalScopes()->where('action', 'quote_approved')->firstOrFail();
        expect($log->user_agent)->toBe('PortalTest/1.0')->and($log->ip_address)->not->toBeEmpty();

        $this->withToken($session)->postJson("/api/v1/portal/quotes/{$quote->id}/approve", ['signer_name' => 'Mario Rossi'])->assertStatus(422);
        $this->withToken($session)->postJson("/api/v1/portal/quotes/{$quote->id}/reject")->assertStatus(422);
    });

    it('rejects a quote', function (): void {
        $quote = Quote::create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->customer->id, 'quote_number' => 'QT-2', 'date' => '2026-05-10', 'valid_until' => '2026-06-10', 'currency' => 'CHF', 'total_net' => 10, 'total_tax' => 0, 'total_gross' => 10, 'status' => 'sent']);

        $this->withToken(portalSessionFor($this->customer))->postJson("/api/v1/portal/quotes/{$quote->id}/reject", ['reason' => 'Too expensive'])->assertOk();

        expect($quote->fresh()->status)->toBe('rejected');
    });

    it('keeps the magic-link routes of the legacy portal working next to the session routes', function (): void {
        $quote = Quote::create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->customer->id, 'quote_number' => 'QT-3', 'date' => '2026-05-10', 'valid_until' => '2026-06-10', 'currency' => 'CHF', 'total_net' => 10, 'total_tax' => 0, 'total_gross' => 10, 'status' => 'sent']);
        $legacy = app(PortalTokenService::class)->createToken($this->customer)['token'];

        $this->getJson("/api/v1/portal/quotes/{$legacy}")->assertOk()->assertJsonPath('data.id', $quote->id);
    });
});

describe('payment checkout', function (): void {
    it('opens a payment page for the open balance and reuses it', function (): void {
        $invoice = ($this->makeInvoice)($this->customer, 'INV-1');
        $session = portalSessionFor($this->customer);

        $first = $this->withToken($session)->postJson("/api/v1/portal/invoices/{$invoice->id}/checkout")
            ->assertCreated()
            ->assertJsonPath('data.amount', '864.80')
            ->assertJsonPath('data.currency', 'CHF');
        $second = $this->withToken($session)->postJson("/api/v1/portal/invoices/{$invoice->id}/checkout")->assertCreated();

        expect($second->json('data.url'))->toBe($first->json('data.url'))->not->toBeEmpty();
    });

    it('refuses to charge an invoice that is already paid', function (): void {
        $invoice = ($this->makeInvoice)($this->customer, 'INV-1', 'paid');
        $invoice->forceFill(['total_paid' => 864.80])->save();

        $this->withToken(portalSessionFor($this->customer))->postJson("/api/v1/portal/invoices/{$invoice->id}/checkout")->assertStatus(422);
    });
});

describe('support requests', function (): void {
    it('opens a ticket linked to an invoice with an attachment', function (): void {
        $invoice = ($this->makeInvoice)($this->customer, 'INV-1');

        $this->withToken(portalSessionFor($this->customer))->post('/api/v1/portal/tickets', [
            'kind' => 'invoice_dispute',
            'subject' => 'Wrong amount',
            'description' => 'The VAT line looks wrong.',
            'invoice_id' => $invoice->id,
            'attachments' => [UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.subject', 'Wrong amount');

        $list = $this->withToken(portalSessionFor($this->customer))->getJson('/api/v1/portal/tickets')->assertOk()->json('data');
        expect($list)->toHaveCount(1);
    });

    it('refuses to link a document of another customer', function (): void {
        $foreign = ($this->makeInvoice)($this->stranger, 'INV-STRANGER');

        $this->withToken(portalSessionFor($this->customer))->postJson('/api/v1/portal/tickets', [
            'kind' => 'invoice_dispute',
            'subject' => 'Not mine',
            'description' => 'Trying to link a foreign invoice.',
            'invoice_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrors('invoice_id');
    });

    it('rejects an executable attachment', function (): void {
        $this->withToken(portalSessionFor($this->customer))->post('/api/v1/portal/tickets', [
            'kind' => 'inquiry',
            'subject' => 'Hello',
            'description' => 'Hello there.',
            'attachments' => [UploadedFile::fake()->create('virus.exe', 20, 'application/octet-stream')],
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('attachments.0');
    });

    it('lists only my own tickets', function (): void {
        $this->withToken(portalSessionFor($this->stranger))->postJson('/api/v1/portal/tickets', ['kind' => 'inquiry', 'subject' => 'Theirs', 'description' => 'Not for me.'])->assertCreated();

        $this->withToken(portalSessionFor($this->customer))->getJson('/api/v1/portal/tickets')->assertOk()->assertJsonCount(0, 'data');
    });
});

it('builds a carrier tracking link only for carriers it knows', function (): void {
    expect(CarrierTrackingUrl::for('DHL Express', '1Z 999'))->toBe('https://www.dhl.com/global-en/home/tracking.html?tracking-id=1Z%20999')
        ->and(CarrierTrackingUrl::for('Local courier', '123'))->toBeNull()
        ->and(CarrierTrackingUrl::for('DHL', null))->toBeNull();
});

describe('portal settings API', function (): void {
    it('lets staff save the branding and rejects a slug already taken by another tenant', function (): void {
        app(TenantContext::class)->runWithTenant($this->otherTenant, fn () => PortalSetting::create(['tenant_id' => $this->otherTenant->id, 'portal_slug' => 'taken']));
        $user = User::create(['name' => 'Staff', 'email' => 'staff@solar.ch', 'password' => bcrypt('secret-pass-123'), 'locale' => 'en']);
        $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/crm/portal-settings', ['portal_slug' => 'taken'], ['X-Tenant-Id' => $this->tenant->id])->assertStatus(422)->assertJsonValidationErrors('portal_slug');
        $this->putJson('/api/v1/crm/portal-settings', ['portal_slug' => 'solar-ch', 'primary_color' => '#112233', 'require_otp' => true], ['X-Tenant-Id' => $this->tenant->id])->assertOk()->assertJsonPath('data.require_otp', true);
        $this->putJson('/api/v1/crm/portal-settings', ['primary_color' => 'red'], ['X-Tenant-Id' => $this->tenant->id])->assertStatus(422);
    });
});
