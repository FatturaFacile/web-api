<?php

declare(strict_types=1);

use App\Models\Tenant;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\ElectronicSignature\Mail\SignatureNotificationMail;
use Modules\ElectronicSignature\Services\SimpleDocumentRenderer;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

/**
 * Builders of the electronic signature tests (Issue #211): a company with an owner signed in, a fake disk and mailer, a
 * small PDF to sign and the helpers that read the signing links out of the mails the module sends.
 */
function esigSetUp(object $test): void
{
    Mail::fake();
    Storage::fake((string) config('electronic_signature.disk', 'local'));

    $test->tenant = Tenant::create(['name' => 'Firme Srl', 'legal_name' => 'Firme Srl', 'country' => 'CH', 'default_currency' => 'CHF']);
    $test->otherTenant = Tenant::create(['name' => 'Altra Srl', 'legal_name' => 'Altra Srl', 'country' => 'CH', 'default_currency' => 'CHF']);
    $test->owner = treasuryActor($test, 'owner');
}

function esigPdf(string $title = 'Service agreement'): string
{
    return app(SimpleDocumentRenderer::class)->render($title, [['Number', 'A-1'], ['Date', '2027-01-15']], [], ['The parties agree to the terms of this document.'], 'Test footer');
}

/**
 * @param  list<array<string, mixed>>|null  $recipients
 * @param  array<string, mixed>  $overrides
 */
function esigCreate(object $test, ?array $recipients = null, array $overrides = []): TestResponse
{
    $payload = array_merge([
        'signable_type' => 'upload',
        'file' => UploadedFile::fake()->createWithContent('agreement.pdf', esigPdf()),
        'title' => 'Service agreement',
        'required_level' => 'FES',
        'jurisdiction' => 'eIDAS',
        'routing_mode' => 'parallel',
        'send' => true,
        'recipients' => $recipients ?? [
            ['full_name' => 'Anna Rossi', 'email' => 'anna@example.com', 'role' => 'SIGNER', 'signing_order' => 1, 'auth_method' => 'email_link'],
        ],
    ], $overrides);

    return $test->post('/api/v1/signatures/envelopes', $payload, ['Accept' => 'application/json']);
}

/**
 * The signing links (last path segment) of the request and reminder mails sent so far, keyed by recipient e-mail address.
 *
 * @return array<string, string>
 */
function esigTokens(): array
{
    $tokens = [];

    foreach (Mail::sent(SignatureNotificationMail::class) as $mail) {
        $url = Closure::bind(fn (): ?string => $this->actionUrl, $mail, SignatureNotificationMail::class)();

        if ($url === null || ! str_contains($url, '/sign/')) {
            continue;
        }

        foreach ($mail->to as $recipient) {
            $tokens[(string) $recipient['address']] = basename(parse_url($url, PHP_URL_PATH) ?: '');
        }
    }

    return $tokens;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function esigSignPayload(array $overrides = []): array
{
    return array_merge([
        'consent_terms' => true,
        'consent_legal' => true,
        'signature_method' => 'click',
    ], $overrides);
}
