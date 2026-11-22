<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Query of the manifest endpoint the MES Core reconciles its replicas with: the cursor of the previous page and the page
 * size.
 */
class MesSyncManifestRequest extends MesApiRequest
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
            'limit' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The page size, capped at the configured maximum.
     */
    public function pageLimit(): int
    {
        return min((int) ($this->validated('limit') ?? config('manufacturing_mes.sync.max_limit')), (int) config('manufacturing_mes.sync.manifest_max_limit'));
    }
}
