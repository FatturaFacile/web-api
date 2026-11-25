<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\CorporateConventions\Mail\ConventionOtpMail;

require_once __DIR__.'/CorporateConventionsSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ccvSetUp($this);
    $this->convention = ccvActiveConvention($this, ['name' => 'Landing Acme']);
    $this->slug = $this->convention['slug'];
});

/**
 * Signs the public client out: the landing page has no account.
 */
function ccvGuest(object $test): void
{
    $test->app['auth']->forgetGuards();
    $test->flushHeaders();
}

function ccvOtpCode(string $slug, string $email): string
{
    $code = null;

    Mail::assertSent(ConventionOtpMail::class, function (ConventionOtpMail $mail) use (&$code, $email): bool {
        $code = $mail->code;

        return $mail->hasTo($email);
    });

    return (string) $code;
}

it('shows the landing page of an active convention without authentication', function (): void {
    ccvGuest($this);

    $this->getJson('/api/v1/public/conventions/'.$this->slug)->assertOk()->assertJsonPath('data.name', 'Landing Acme');
    $this->getJson('/api/v1/public/conventions/does-not-exist')->assertNotFound();
});

it('does not expose a draft or suspended convention on the public page', function (): void {
    $this->postJson('/api/v1/sales/corporate-conventions/'.$this->convention['id'].'/transition', ['status' => 'SUSPENDED'])->assertOk();
    ccvGuest($this);

    $this->getJson('/api/v1/public/conventions/'.$this->slug)->assertNotFound();
});

it('verifies a work e-mail OTP and issues the pass', function (): void {
    Mail::fake();
    ccvGuest($this);

    $this->postJson('/api/v1/public/conventions/'.$this->slug.'/request-otp', ['email' => 'mario.rossi@acme.it'])->assertStatus(202);
    $code = ccvOtpCode($this->slug, 'mario.rossi@acme.it');

    expect($code)->toMatch('/^\d{6}$/')
        ->and(DB::table('sales_convention_otp_challenges')->value('otp_hash'))->not->toContain($code);

    $response = $this->postJson('/api/v1/public/conventions/'.$this->slug.'/verify-otp', ['email' => 'mario.rossi@acme.it', 'code' => $code, 'full_name' => 'Mario Rossi'])->assertCreated();

    expect(Str::isUuid($response->json('data.wallet_pass_id')))->toBeTrue();

    $this->getJson('/api/v1/public/conventions/passes/'.$response->json('data.wallet_pass_id'))->assertOk();
    $this->get('/api/v1/public/conventions/passes/'.$response->json('data.wallet_pass_id').'/qr.svg')->assertOk();
});

it('refuses a code for an address outside the partner domains', function (): void {
    Mail::fake();
    ccvGuest($this);

    $this->postJson('/api/v1/public/conventions/'.$this->slug.'/request-otp', ['email' => 'mario@evil-acme.it'])->assertStatus(422);
    Mail::assertNothingSent();
});

it('refuses a wrong code and locks the challenge after five attempts', function (): void {
    Mail::fake();
    ccvGuest($this);
    $this->postJson('/api/v1/public/conventions/'.$this->slug.'/request-otp', ['email' => 'anna@acme.it'])->assertStatus(202);
    $code = ccvOtpCode($this->slug, 'anna@acme.it');
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 4) as $_) {
        $this->postJson('/api/v1/public/conventions/'.$this->slug.'/verify-otp', ['email' => 'anna@acme.it', 'code' => $wrong, 'full_name' => 'Anna'])->assertStatus(422);
    }

    $this->postJson('/api/v1/public/conventions/'.$this->slug.'/verify-otp', ['email' => 'anna@acme.it', 'code' => $wrong, 'full_name' => 'Anna'])->assertStatus(422);
    $this->postJson('/api/v1/public/conventions/'.$this->slug.'/verify-otp', ['email' => 'anna@acme.it', 'code' => $code, 'full_name' => 'Anna'])->assertStatus(429);
});

it('rejects an expired code', function (): void {
    Mail::fake();
    ccvGuest($this);
    $this->postJson('/api/v1/public/conventions/'.$this->slug.'/request-otp', ['email' => 'anna@acme.it'])->assertStatus(202);
    $code = ccvOtpCode($this->slug, 'anna@acme.it');

    DB::table('sales_convention_otp_challenges')->update(['expires_at' => now()->subMinute()]);

    $this->postJson('/api/v1/public/conventions/'.$this->slug.'/verify-otp', ['email' => 'anna@acme.it', 'code' => $code, 'full_name' => 'Anna'])->assertStatus(422);
});

it('verifies a matricola from the hashed list on the public page', function (): void {
    ccvGuest($this);

    $this->postJson('/api/v1/public/conventions/'.$this->slug.'/verify-identifier', ['identifier' => 'mat-002', 'full_name' => 'Luigi Verdi'])
        ->assertCreated();

    $this->postJson('/api/v1/public/conventions/'.$this->slug.'/verify-identifier', ['identifier' => 'mat-002', 'full_name' => 'Doppione'])->assertStatus(409);
    $this->postJson('/api/v1/public/conventions/'.$this->slug.'/verify-identifier', ['identifier' => 'unknown', 'full_name' => 'Ignoto'])->assertStatus(422);
});

it('lets the holder of a pass add a family member through the pass id only', function (): void {
    ccvGuest($this);
    $pass = $this->postJson('/api/v1/public/conventions/'.$this->slug.'/verify-identifier', ['identifier' => 'MAT-001', 'full_name' => 'Mario Rossi'])->assertCreated()->json('data.wallet_pass_id');

    $this->postJson('/api/v1/public/conventions/passes/'.$pass.'/family-members', ['full_name' => 'Anna Rossi', 'relationship' => 'SPOUSE'])->assertCreated();
    $this->postJson('/api/v1/public/conventions/passes/'.(string) Str::uuid().'/family-members', ['full_name' => 'Intruso', 'relationship' => 'CHILD'])->assertNotFound();
});

it('serves a pass only through its own unguessable id', function (): void {
    ccvGuest($this);
    $pass = $this->postJson('/api/v1/public/conventions/'.$this->slug.'/verify-identifier', ['identifier' => 'MAT-001', 'full_name' => 'Mario Rossi'])->assertCreated()->json('data.wallet_pass_id');

    $this->getJson('/api/v1/public/conventions/passes/'.(string) Str::uuid())->assertNotFound();
    $this->getJson('/api/v1/public/conventions/passes/'.$pass)->assertOk();
});
