<?php

declare(strict_types=1);

namespace App\Http\Requests\Banking;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Http\Requests\Concerns\ValidatesUploadContentLength;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload of one ISO 20022 file (camt.053, camt.054 or pain.002); the message type is detected from its content.
 * `payment_batch_id` pins a pain.002 report to a batch, otherwise the batch is found through the message it answers.
 */
class UploadIso20022Request extends FormRequest
{
    use TenantScopedRules;
    use ValidatesUploadContentLength;

    protected function maxContentLengthBytes(): int
    {
        return 10 * 1024 * 1024;
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'extensions:xml'],
            'auto_reconcile' => ['sometimes', 'boolean'],
            'payment_batch_id' => ['sometimes', 'uuid', $this->tenantExists('payment_batches')],
        ];
    }
}
