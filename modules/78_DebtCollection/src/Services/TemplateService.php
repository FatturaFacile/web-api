<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use Modules\DebtCollection\Exceptions\DebtCollectionException;
use Modules\DebtCollection\Models\Template;

/**
 * Notice texts and document captions. Everything a recipient reads lives in the database (seeded as global rows, one per
 * code, language and jurisdiction; a company overrides any of them with its own row), never in code.
 *
 * Placeholders are written {{name}}; an unknown placeholder is left empty.
 */
final class TemplateService
{
    public const array Locales = ['it', 'en', 'de', 'fr'];

    /**
     * The template for a code and language: the company row before the global one, the jurisdiction-specific text
     * before the generic one, and the Italian text when the language has none.
     */
    public function resolve(string $code, string $locale, ?string $jurisdiction = null): Template
    {
        $locale = in_array($locale, self::Locales, true) ? $locale : 'it';

        foreach ([$locale, 'it'] as $language) {
            $rows = Template::query()->where('code', $code)->where('locale', $language)->get();

            $best = $rows
                ->filter(static fn (Template $row): bool => $row->jurisdiction === null || $row->jurisdiction === $jurisdiction)
                ->sortByDesc(static fn (Template $row): int => ($row->tenant_id === null ? 0 : 2) + ($row->jurisdiction === null ? 0 : 1))
                ->first();

            if ($best !== null) {
                return $best;
            }
        }

        throw new DebtCollectionException("No text template [{$code}] is configured.", 'template_missing');
    }

    /**
     * @param  array<string, string>  $values
     * @return array{subject: string, body: string}
     */
    public function render(string $code, string $locale, ?string $jurisdiction, array $values): array
    {
        $template = $this->resolve($code, $locale, $jurisdiction);

        return ['subject' => $this->fill((string) $template->subject, $values), 'body' => $this->fill((string) $template->body, $values)];
    }

    /**
     * The captions of the generated documents (column headings, section titles), in the language asked.
     *
     * @return array<string, string>
     */
    public function labels(string $locale, string $code = 'labels'): array
    {
        $decoded = json_decode((string) $this->resolve($code, $locale)->body, true);

        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    /**
     * @param  array<string, string>  $values
     */
    public function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', static fn (array $match): string => $values[$match[1]] ?? '', $text);
    }
}
