<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Posting a revaluation needs the Idempotency-Key header (8 to 100 characters): the same key replays the posting.
 */
final class PostRevaluationRequest extends FormRequest
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
        return [];
    }

    public function idempotencyKey(): ?string
    {
        $key = trim((string) $this->header('Idempotency-Key'));

        return $key !== '' && strlen($key) >= 8 && strlen($key) <= 100 ? $key : null;
    }
}
