<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;
use App\Http\Responses\StoredFileHeaders;
use Illuminate\Support\Facades\Route;

test('api responses carry the hardening headers and a deny-all content security policy', function (): void {
    $response = $this->getJson('/api/v1/does-not-exist');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Content-Security-Policy', SecurityHeaders::CONTENT_SECURITY_POLICY)
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

    expect(SecurityHeaders::CONTENT_SECURITY_POLICY)->toContain("default-src 'none'")->toContain("frame-ancestors 'none'");
});

test('file responses get the policy and the hsts header too', function (): void {
    Route::get('/_test/file', fn () => response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']));

    $this->get('/_test/file')
        ->assertHeader('Content-Security-Policy', SecurityHeaders::CONTENT_SECURITY_POLICY)
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('the interactive api documentation keeps working without the deny-all policy', function (): void {
    Route::get('/api/documentation', fn () => response('<html><script>ui()</script></html>'));

    $this->get('/api/documentation')->assertHeaderMissing('Content-Security-Policy')->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('stored files are shown inline only for pdf and raster images, with a safe file name', function (): void {
    expect(StoredFileHeaders::for('application/pdf', 'a.pdf', true))->toMatchArray(['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename=a.pdf'])
        ->and(StoredFileHeaders::for('image/webp', 'a.webp', true)['Content-Disposition'])->toStartWith('inline;')
        ->and(StoredFileHeaders::for('image/svg+xml', 'a.svg', true))->toMatchArray(['Content-Type' => 'application/octet-stream'])
        ->and(StoredFileHeaders::for('image/svg+xml', 'a.svg', true)['Content-Disposition'])->toStartWith('attachment;')
        ->and(StoredFileHeaders::for('text/html; charset=utf-8', 'a.html', true)['Content-Type'])->toBe('application/octet-stream')
        ->and(StoredFileHeaders::for('application/pdf', 'a.pdf', false)['Content-Disposition'])->toStartWith('attachment;')
        ->and(StoredFileHeaders::for('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'a.docx', false)['Content-Type'])->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document')
        ->and(StoredFileHeaders::for("bad\r\nvalue", 'x', false)['Content-Type'])->toBe('application/octet-stream')
        ->and(StoredFileHeaders::for('application/pdf', "fattura \"1\"\r\n.pdf", true)['Content-Disposition'])->not->toContain("\r")->not->toContain("\n")
        ->and(StoredFileHeaders::for('application/pdf', 'ricevuta è.pdf', true)['Content-Disposition'])->toContain("filename*=utf-8''ricevuta%20%C3%A8.pdf");
});

test('sessions expire after eight hours by default and api tokens after twelve', function (): void {
    expect(file_get_contents(config_path('session.php')))->toContain("'lifetime' => (int) env('SESSION_LIFETIME', 480),")
        ->and(file_get_contents(config_path('sanctum.php')))->toContain("env('SANCTUM_EXPIRATION', 720)")
        ->and(file_get_contents(base_path('.env.example')))->toContain("SESSION_LIFETIME=480\n");
});
