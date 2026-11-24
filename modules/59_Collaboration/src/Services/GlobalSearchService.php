<?php

declare(strict_types=1);

namespace Modules\Collaboration\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Searches the registered record types of the active company for a text. Only the types whose module the member may
 * read are searched, every query is limited to the tenant, and the match is a case-insensitive "contains" built with
 * the query builder, so it works on every supported database.
 */
final class GlobalSearchService
{
    private const string EscapeCharacter = '!';

    public function __construct(private readonly CollaborationRegistry $registry) {}

    /**
     * @param  list<string>|null  $types  restrict the search to these record type keys
     * @return list<array{type: string, type_label: string, id: string, title: string, subtitle: string|null, path: string}>
     */
    public function search(?User $user, string $tenantId, string $term, ?array $types = null, int $perType = 5): array
    {
        $results = [];

        foreach ($this->registry->readableBy($user, $tenantId) as $key => $type) {
            if ($types !== null && ! in_array($key, $types, true)) {
                continue;
            }

            foreach ($this->matches($type, $tenantId, $term, $perType) as $record) {
                $results[] = [
                    'type' => $type->key,
                    'type_label' => __($type->labelKey),
                    'id' => (string) $record->getKey(),
                    'title' => $type->titleOf($record),
                    'subtitle' => $type->subtitleOf($record),
                    'path' => $type->pathOf($record),
                ];
            }
        }

        return $results;
    }

    /**
     * @return iterable<int, Model>
     */
    private function matches(RecordType $type, string $tenantId, string $term, int $limit): iterable
    {
        /** @var Builder<Model> $query */
        $query = $type->modelClass::query();
        $model = $query->getModel();
        $pattern = '%'.$this->escapeLike(mb_strtolower($term)).'%';
        $escape = self::EscapeCharacter;

        $query->where($model->qualifyColumn('tenant_id'), $tenantId)
            ->where(function (Builder $group) use ($type, $model, $pattern, $escape): void {
                $grammar = $group->getQuery()->getGrammar();

                foreach ($type->searchColumns as $column) {
                    $wrapped = $grammar->wrap($model->qualifyColumn($column));
                    $group->orWhereRaw("lower({$wrapped}) like ? escape '{$escape}'", [$pattern]);
                }
            });

        return $query->orderBy($model->qualifyColumn($type->searchColumns[0]))->limit($limit)->get();
    }

    private function escapeLike(string $text): string
    {
        $escape = self::EscapeCharacter;

        return str_replace([$escape, '%', '_'], [$escape.$escape, $escape.'%', $escape.'_'], $text);
    }
}
