<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * An optional reason for a lifecycle action (pause, close).
 */
#[OA\Schema(schema: 'ProjectsReasonRequest', properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 1000, nullable: true)])]
final class ReasonRequest extends FormRequest
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
        return ['reason' => ['nullable', 'string', 'max:1000']];
    }
}
