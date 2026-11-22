<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Query of the pull endpoint of the reference data: the last sequence number the MES Core holds (`after_seq`), the
 * bootstrap instant for the first call and the page size.
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
            'after_seq' => ['nullable', 'integer', 'min:0'],
            'updated_since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The sequence number of the last change the MES Core already holds, null on the first call.
     */
    public function afterSeq(): ?int
    {
        $afterSeq = $this->validated('after_seq');

        return $afterSeq === null ? null : (int) $afterSeq;
    }

    /**
     * The page size, capped at the configured maximum.
     */
    public function pageLimit(): int
    {
        return min((int) ($this->validated('limit') ?? config('manufacturing_mes.sync.default_limit')), (int) config('manufacturing_mes.sync.max_limit'));
    }
}
