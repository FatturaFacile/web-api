<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings\Access;

use App\Services\Tenancy\CustomRoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A CSV file of people to add, checked only (`dry_run`) or imported, with the role of the rows that name none.
 */
class ImportUsersRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.(int) config('access.import_max_kilobytes')],
            'dry_run' => ['sometimes', 'boolean'],
            'default_role' => ['sometimes', 'nullable', 'string', Rule::in(app(CustomRoleService::class)->baseRoles())],
        ];
    }

    public function dryRun(): bool
    {
        return $this->boolean('dry_run');
    }

    public function defaultRole(): string
    {
        return (string) ($this->input('default_role') ?: 'member');
    }
}
