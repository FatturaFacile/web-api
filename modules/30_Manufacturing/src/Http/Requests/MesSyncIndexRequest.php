<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Query of the pull endpoint of the reference data: the cursor of the previous page, the bootstrap instant for the
 * first call and the page size.
 */
class MesSyncIndexRequest extends MesApiRequest
{
    /**
     * Validation rules.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string', 'max:255'],
            'updated_since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The page size, capped at the configured maximum.
     */
    public function pageLimit(): int
    {
        return min((int) ($this->validated('limit') ?? config('manufacturing_mes.sync.default_limit')), (int) config('manufacturing_mes.sync.max_limit'));
    }
}
