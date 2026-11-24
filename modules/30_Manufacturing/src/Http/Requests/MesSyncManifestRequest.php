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
     * The page size, capped at the configured maximum. Without a limit the whole page the cap allows is served, because
     * the MES Core reads a single manifest page.
     */
    public function pageLimit(): int
    {
        $max = (int) config('manufacturing_mes.sync.manifest_max_limit');

        return min((int) ($this->validated('limit') ?? $max), $max);
    }
}
