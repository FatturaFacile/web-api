<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\ElectronicSignature\Enums\EnvelopeStatus;
use Modules\ElectronicSignature\Mail\SignatureNotificationMail;
use Modules\ElectronicSignature\Models\SignatureAuditLog;
use Modules\ElectronicSignature\Models\SignatureEnvelope;
use Modules\ElectronicSignature\Services\EnvelopeService;
use Modules\ElectronicSignature\Services\PdfSignatureService;
use Modules\ElectronicSignature\Services\SignatureAuditService;

require_once __DIR__.'/ElectronicSignatureSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    esigSetUp($this);
});

it('creates and sends a parallel envelope: every signer gets a link at once', function (): void {
    $response = esigCreate($this, [
        ['full_name' => 'Anna Rossi', 'email' => 'anna@example.com', 'role' => 'SIGNER', 'signing_order' => 1, 'auth_method' => 'email_link'],
        ['full_name' => 'Bruno Bianchi', 'email' => 'bruno@example.com', 'role' => 'SIGNER', 'signing_order' => 2, 'auth_method' => 'email_link'],
    ])->assertCreated();

    expect($response->json('data.status'))->toBe('pending')
        ->and($response->json('data.verification_code'))->toMatch('/^[A-Z0-9]{5}(-[A-Z0-9]{5}){3}$/')
        ->and(esigTokens())->toHaveKeys(['anna@example.com', 'bruno@example.com']);
});

it('keeps a sequential envelope to one signer at a time and releases the next one after a signature', function (): void {
    $id = esigCreate($this, [
        ['full_name' => 'Anna Rossi', 'email' => 'anna@example.com', 'role' => 'SIGNER', 'signing_order' => 1, 'auth_method' => 'email_link'],
        ['full_name' => 'Bruno Bianchi', 'email' => 'bruno@example.com', 'role' => 'SIGNER', 'signing_order' => 2, 'auth_method' => 'email_link'],
    ], ['routing_mode' => 'sequential'])->assertCreated()->json('data.id');

    expect(array_keys(esigTokens()))->toBe(['anna@example.com']);

    $anna = esigTokens()['anna@example.com'];
    $this->postJson("/api/v1/public/sign/{$anna}/sign", esigSignPayload())->assertOk();

    expect(esigTokens())->toHaveKey('bruno@example.com')
        ->and($this->getJson("/api/v1/signatures/envelopes/{$id}")->json('data.status'))->toBe('partially_signed');
});

it('completes the envelope when everybody has signed and serves the sealed PDF, the original and the certificate', function (): void {
    $created = esigCreate($this, [
        ['full_name' => 'Anna Rossi', 'email' => 'anna@example.com', 'role' => 'SIGNER', 'signing_order' => 1, 'auth_method' => 'email_link'],
        ['full_name' => 'Carla Verdi', 'email' => 'carla@example.com', 'role' => 'CC_RECIPIENT', 'signing_order' => 2, 'auth_method' => 'email_link'],
    ])->assertCreated();
    $id = $created->json('data.id');
    $code = $created->json('data.verification_code');

    $this->postJson('/api/v1/public/sign/'.esigTokens()['anna@example.com'].'/sign', esigSignPayload())->assertOk();

    expect($this->getJson("/api/v1/signatures/envelopes/{$id}")->json('data.status'))->toBe('completed');

    $signed = $this->get("/api/v1/signatures/envelopes/{$id}/download/signed")->assertOk()->getContent();
    $original = $this->get("/api/v1/signatures/envelopes/{$id}/download/original")->assertOk()->getContent();
    $this->get("/api/v1/signatures/envelopes/{$id}/download/certificate")->assertOk();

    expect($signed)->toStartWith('%PDF-')
        ->and($signed)->not->toBe($original)
        ->and(app(PdfSignatureService::class)->inspect($signed))->not->toBeEmpty();

    // The completion mail goes to the signer and to the copy recipient, with the signed PDF and the certificate attached.
    Mail::assertSent(SignatureNotificationMail::class, fn (SignatureNotificationMail $mail): bool => $mail->hasTo('carla@example.com') && count($mail->attachments()) === 2);

    $this->postJson('/api/v1/public/signatures/verify', ['code' => $code])->assertOk()
        ->assertJsonPath('data.found', true)
        ->assertJsonPath('data.status', 'completed');
});

it('keeps an audit trail whose hash chain breaks as soon as an entry is altered', function (): void {
    $id = esigCreate($this)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/public/sign/'.esigTokens()['anna@example.com'].'/sign', esigSignPayload())->assertOk();

    $envelope = SignatureEnvelope::query()->findOrFail($id);
    $audit = app(SignatureAuditService::class);

    expect($audit->verifyChain($envelope))->toBeTrue();

    DB::table('signature_audit_logs')->where('envelope_id', $id)->where('sequence', 2)->update(['actor_type' => 'tampered']);

    expect($audit->verifyChain($envelope->refresh()))->toBeFalse();
});

it('refuses to update or delete an audit entry', function (): void {
    $id = esigCreate($this)->assertCreated()->json('data.id');
    $entry = SignatureAuditLog::query()->where('envelope_id', $id)->firstOrFail();

    expect(fn () => $entry->update(['event' => 'envelope_sent']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});

it('needs a reason to decline, ends the envelope and tells the sender', function (): void {
    $id = esigCreate($this)->assertCreated()->json('data.id');
    $token = esigTokens()['anna@example.com'];

    $this->postJson("/api/v1/public/sign/{$token}/decline", [])->assertUnprocessable();
    $this->postJson("/api/v1/public/sign/{$token}/decline", ['reason' => 'The price is wrong'])->assertOk();

    expect($this->getJson("/api/v1/signatures/envelopes/{$id}")->json('data.status'))->toBe('declined');
    Mail::assertSent(SignatureNotificationMail::class, fn (SignatureNotificationMail $mail): bool => $mail->hasTo($this->owner->email));

    // The link no longer lets anybody sign.
    $this->postJson("/api/v1/public/sign/{$token}/sign", esigSignPayload())->assertClientError();
});

it('refuses a signature without both consents', function (): void {
    esigCreate($this)->assertCreated();
    $token = esigTokens()['anna@example.com'];

    $this->postJson("/api/v1/public/sign/{$token}/sign", esigSignPayload(['consent_legal' => false]))->assertUnprocessable();
});

it('does not let an unknown link into the portal', function (): void {
    $this->getJson('/api/v1/public/sign/'.str_repeat('a', 48))->assertNotFound();
});

it('stops a recipient who signed from signing twice', function (): void {
    esigCreate($this, [
        ['full_name' => 'Anna Rossi', 'email' => 'anna@example.com', 'role' => 'SIGNER', 'signing_order' => 1, 'auth_method' => 'email_link'],
        ['full_name' => 'Bruno Bianchi', 'email' => 'bruno@example.com', 'role' => 'SIGNER', 'signing_order' => 2, 'auth_method' => 'email_link'],
    ])->assertCreated();
    $token = esigTokens()['anna@example.com'];

    $this->postJson("/api/v1/public/sign/{$token}/sign", esigSignPayload())->assertOk();
    $this->postJson("/api/v1/public/sign/{$token}/sign", esigSignPayload())->assertClientError();
});

it('voids an open envelope and closes its links', function (): void {
    $id = esigCreate($this)->assertCreated()->json('data.id');
    $token = esigTokens()['anna@example.com'];

    $this->postJson("/api/v1/signatures/envelopes/{$id}/void", ['reason' => 'Sent by mistake'])->assertOk();

    expect($this->getJson("/api/v1/signatures/envelopes/{$id}")->json('data.status'))->toBe('cancelled');
    $this->postJson("/api/v1/public/sign/{$token}/sign", esigSignPayload())->assertClientError();
});

it('expires an envelope past its deadline and keeps it from being signed', function (): void {
    $id = esigCreate($this)->assertCreated()->json('data.id');
    $token = esigTokens()['anna@example.com'];

    SignatureEnvelope::query()->whereKey($id)->update(['expires_at' => now()->subHour()]);
    $result = app(EnvelopeService::class)->processLifecycle();

    expect($result['expired'])->toBe(1)
        ->and(SignatureEnvelope::query()->findOrFail($id)->status)->toBe(EnvelopeStatus::Expired);
    $this->postJson("/api/v1/public/sign/{$token}/sign", esigSignPayload())->assertClientError();
});

it('sends one reminder when a configured threshold is crossed, and only once', function (): void {
    $id = esigCreate($this, null, ['expiration_days' => 10, 'reminder_days' => [3]])->assertCreated()->json('data.id');
    $sent = Mail::sent(SignatureNotificationMail::class)->count();

    SignatureEnvelope::query()->whereKey($id)->update(['expires_at' => now()->addDays(2)]);

    expect(app(EnvelopeService::class)->processLifecycle()['reminded'])->toBe(1)
        ->and(Mail::sent(SignatureNotificationMail::class)->count())->toBe($sent + 1)
        ->and(app(EnvelopeService::class)->processLifecycle()['reminded'])->toBe(0);
});

it('lets the sender remind a pending signer but not once the envelope is closed', function (): void {
    $id = esigCreate($this)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/signatures/envelopes/{$id}/remind")->assertOk();

    $token = esigTokens()['anna@example.com'];
    $this->postJson("/api/v1/public/sign/{$token}/sign", esigSignPayload())->assertOk();
    $this->postJson("/api/v1/signatures/envelopes/{$id}/remind")->assertClientError();
});

it('needs strong authentication for the advanced and qualified tiers', function (): void {
    esigCreate($this, null, ['required_level' => 'FEA'])->assertStatus(422)
        ->assertJsonPath('code', 'strong_authentication_required');
});

it('needs the identity document for an advanced signature made with the native engine, and takes the sandbox SMS code', function (): void {
    $id = esigCreate($this, [
        ['full_name' => 'Anna Rossi', 'email' => 'anna@example.com', 'phone' => '+41791234567', 'role' => 'SIGNER', 'signing_order' => 1, 'auth_method' => 'sms_otp'],
    ], ['required_level' => 'FEA'])->assertCreated()->json('data.id');
    $token = esigTokens()['anna@example.com'];

    $challenge = $this->postJson("/api/v1/public/sign/{$token}/challenge")->assertOk();
    $code = $challenge->json('data.sandbox_code');
    expect($code)->not->toBeEmpty();

    $this->postJson("/api/v1/public/sign/{$token}/sign", esigSignPayload(['otp' => $code]))->assertStatus(422)->assertJsonPath('code', 'identity_document_required');
    $this->postJson("/api/v1/public/sign/{$token}/sign", esigSignPayload(['otp' => '000001']))->assertClientError();
    $this->postJson("/api/v1/public/sign/{$token}/sign", esigSignPayload([
        'otp' => $code,
        'identity' => ['id_document_type' => 'passport', 'id_document_number' => 'X1234567'],
    ]))->assertOk();

    expect($this->getJson("/api/v1/signatures/envelopes/{$id}")->json('data.status'))->toBe('completed');
});

it('records that an envelope was made in the sandbox', function (): void {
    $id = esigCreate($this)->assertCreated()->json('data.id');

    expect(SignatureEnvelope::query()->findOrFail($id)->metadata['sandbox'] ?? null)->toBeTrue();
});
