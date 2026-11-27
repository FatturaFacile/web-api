<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The webhook body is authenticated by its signature (WebhookSignatureVerifier), not by a user, and its shape differs per provider:
 * it is read raw and mapped by the parser; the rules only keep the container sane.
 */
final class EbillWebhookRequest extends FormRequest
{
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
            'events' => ['sometimes', 'array', 'max:200'],
        ];
    }
}
