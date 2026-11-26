<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The entries a backup restore moved to the audit archive, optionally for one restore and with the verification of its chain.
 */
final class ListArchivedAuditEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('audit-ledger.search');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'restore_id' => ['nullable', 'uuid'],
            'cursor' => ['nullable', 'string', 'regex:/^\d{1,12}$/'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'verify' => ['nullable', 'boolean'],
        ];
    }
}
