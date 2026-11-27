<?php

declare(strict_types=1);

namespace Modules\Observability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoints that take no input (tenant-facing status summary, pg_stat_statements report).
 */
final class NoInputRequest extends FormRequest
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
}
