<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * A rejection must say why, so the author knows what to correct.
 */
#[OA\Schema(schema: 'ProjectsRejectionRequest', required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 1000)])]
final class RejectionRequest extends FormRequest
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
        return ['reason' => ['required', 'string', 'min:3', 'max:1000']];
    }
}
