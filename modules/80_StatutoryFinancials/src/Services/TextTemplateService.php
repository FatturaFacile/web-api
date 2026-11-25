<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use Modules\StatutoryFinancials\Models\TextTemplate;

/**
 * Reads the seeded wording and fills its {{placeholders}}. Every text of the reports comes from here, never from code.
 */
final class TextTemplateService
{
    /** @var array<string, TextTemplate|null> */
    private array $cache = [];

    public function find(string $key): ?TextTemplate
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $rows = TextTemplate::query()->where('template_key', $key)->get();
        $row = $rows->firstWhere(static fn (TextTemplate $template): bool => $template->tenant_id !== null) ?? $rows->first();

        return $this->cache[$key] = $row;
    }

    /**
     * @param  array<string, scalar|null>  $params
     * @return array{title: string, body: string}
     */
    public function render(string $key, string $locale, array $params = []): array
    {
        $template = $this->find($key);

        if ($template === null) {
            return ['title' => $key, 'body' => ''];
        }

        return ['title' => $this->fill($template->title($locale), $params), 'body' => $this->fill($template->body($locale), $params)];
    }

    public function title(string $key, string $locale): string
    {
        return $this->render($key, $locale)['title'];
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public function fill(string $text, array $params): string
    {
        foreach ($params as $name => $value) {
            $text = str_replace('{{'.$name.'}}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * All templates of a kind for a family, as lookups for the client.
     *
     * @return list<TextTemplate>
     */
    public function ofKind(string $kind, ?string $family = null): array
    {
        $query = TextTemplate::query()->where('kind', $kind)->orderBy('sort_order');

        if ($family !== null) {
            $query->whereIn('family', [$family, 'ALL']);
        }

        return $query->get()->all();
    }
}
