<?php

declare(strict_types=1);

use App\Http\Requests\Banking\ImportBankStatementRequest;
use App\Http\Requests\Concerns\ValidatesUploadContentLength;
use App\Http\Requests\Documents\StoreAttachmentRequest;
use App\Http\Requests\Purchases\UploadPassiveInvoiceRequest;
use App\Http\Requests\Sales\ImportPaymentsRequest;
use App\Http\Requests\System\RestoreUploadRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

$createUploadRequest = function (): FormRequest {
    return new class extends FormRequest
    {
        use ValidatesUploadContentLength;

        public int $maxContentLengthBytes = 5 * 1024 * 1024; // 5 MB

        public function triggerValidation(): void
        {
            $this->prepareForValidation();
        }
    };
};

$createDefaultUploadRequest = function (): FormRequest {
    return new class extends FormRequest
    {
        use ValidatesUploadContentLength;

        public function triggerValidation(): void
        {
            $this->prepareForValidation();
        }
    };
};

test('validates upload content length aborts with 413 when CONTENT_LENGTH server param exceeds limit', function () use ($createUploadRequest): void {
    $request = $createUploadRequest();
    $request->server->set('CONTENT_LENGTH', (string) (6 * 1024 * 1024));

    expect(fn () => $request->triggerValidation())
        ->toThrow(HttpException::class, 'Uploaded payload exceeds the maximum allowed content length.');
});

test('validates upload content length aborts with 413 when Content-Length header exceeds limit', function () use ($createUploadRequest): void {
    $request = $createUploadRequest();
    $request->headers->set('Content-Length', (string) (6 * 1024 * 1024));

    expect(fn () => $request->triggerValidation())
        ->toThrow(HttpException::class, 'Uploaded payload exceeds the maximum allowed content length.');
});

test('validates upload content length passes when payload size is within limits', function () use ($createUploadRequest): void {
    $request = $createUploadRequest();
    $request->server->set('CONTENT_LENGTH', (string) (2 * 1024 * 1024));

    $request->triggerValidation();
    expect(true)->toBeTrue();
});

test('validates upload content length defaults to 20MB when property is not overridden', function () use ($createDefaultUploadRequest): void {
    $request = $createDefaultUploadRequest();

    $request->server->set('CONTENT_LENGTH', (string) (15 * 1024 * 1024));
    $request->triggerValidation();

    $request->server->set('CONTENT_LENGTH', (string) (21 * 1024 * 1024));
    expect(fn () => $request->triggerValidation())
        ->toThrow(HttpException::class, 'Uploaded payload exceeds the maximum allowed content length.');
});

test('all 5 upload form requests define expected boundaries and file rules', function (): void {
    $validate = fn (FormRequest $req) => (fn () => $this->prepareForValidation())->call($req);

    $bankRequest = new ImportBankStatementRequest;
    $bankRequest->server->set('CONTENT_LENGTH', (string) (11 * 1024 * 1024));
    expect(fn () => $validate($bankRequest))->toThrow(HttpException::class);

    $docRequest = new StoreAttachmentRequest;
    $docRequest->server->set('CONTENT_LENGTH', (string) (26 * 1024 * 1024));
    expect(fn () => $validate($docRequest))->toThrow(HttpException::class);

    $passiveRequest = new UploadPassiveInvoiceRequest;
    $passiveRequest->server->set('CONTENT_LENGTH', (string) (11 * 1024 * 1024));
    expect(fn () => $validate($passiveRequest))->toThrow(HttpException::class);

    $paymentRequest = new ImportPaymentsRequest;
    $paymentRequest->server->set('CONTENT_LENGTH', (string) (11 * 1024 * 1024));
    expect(fn () => $validate($paymentRequest))->toThrow(HttpException::class);

    $restoreRequest = new RestoreUploadRequest;
    $restoreRequest->server->set('CONTENT_LENGTH', (string) (26 * 1024 * 1024));
    expect(fn () => $validate($restoreRequest))->toThrow(HttpException::class);

    // Verify rules define 'file' and 'max:'
    expect($bankRequest->rules()['file'])->toContain('file', 'max:10240')
        ->and($docRequest->rules()['file'])->toContain('file', 'max:25600')
        ->and($passiveRequest->rules()['file'])->toContain('file', 'max:10240')
        ->and($paymentRequest->rules()['file'])->toContain('file', 'max:10240')
        ->and($restoreRequest->rules()['file'])->toContain('file', 'max:25600');
});

test('rules reject files exceeding max size or invalid extensions with 422', function (): void {
    $bankRules = (new ImportBankStatementRequest)->rules();
    $v1 = Validator::make(
        ['file' => UploadedFile::fake()->create('statement.pdf', 100)],
        ['file' => $bankRules['file']]
    );
    expect($v1->fails())->toBeTrue();

    $restoreRules = (new RestoreUploadRequest)->rules();
    $v2 = Validator::make(
        ['file' => UploadedFile::fake()->create('backup.tar.gz', 100)],
        ['file' => $restoreRules['file']]
    );
    expect($v2->fails())->toBeTrue();
});
