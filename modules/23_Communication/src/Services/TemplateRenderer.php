<?php

declare(strict_types=1);

namespace Modules\Communication\Services;

use Illuminate\Validation\ValidationException;
use Modules\Communication\Models\CommunicationTemplate;

/**
 * Chooses the template of a message for a language and fills in its placeholders. Templates are plain text with
 * `{{ name }}` placeholders and nothing else: no code, no markup is interpreted, so a template written by a user of the
 * company can only ever print the variables it is given. Values never carry line breaks into a subject.
 */
final class TemplateRenderer
{
    /**
     * A placeholder: a lower-case name, with dots for nesting by convention (`contact.name`).
     */
    public const string Placeholder = '/\{\{\s*([a-z][a-z0-9_.]*)\s*\}\}/';

    /**
     * Longest value a variable may have once rendered.
     */
    private const int MaxValueLength = 1000;

    /**
     * Names of the placeholders in a text, without repeats.
     *
     * @return list<string>
     */
    public function placeholders(string $text): array
    {
        preg_match_all(self::Placeholder, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Whether the text holds braces that are not well-formed placeholders (which would print as they are and look like
     * a mistake to the reader).
     */
    public function hasStrayBraces(string $text): bool
    {
        return str_contains(preg_replace(self::Placeholder, '', $text) ?? $text, '{{') || str_contains(preg_replace(self::Placeholder, '', $text) ?? $text, '}}');
    }

    /**
     * The active template for a code and channel in the best available language: the language asked for with its
     * region (`it_CH`), then without it (`it`), then English.
     */
    public function resolve(string $code, string $channel, ?string $locale): ?CommunicationTemplate
    {
        $candidates = array_values(array_unique(array_filter([$locale, $locale === null ? null : explode('_', $locale)[0], 'en'])));
        $templates = CommunicationTemplate::query()->where('code', $code)->where('channel', $channel)->where('is_active', true)->whereIn('locale', $candidates)->get()->keyBy('locale');

        foreach ($candidates as $candidate) {
            if ($templates->has($candidate)) {
                return $templates->get($candidate);
            }
        }

        return null;
    }

    /**
     * The subject and body of a template with the variables filled in.
     *
     * @param  array<string, scalar|null>  $variables
     * @return array{subject: ?string, body: string}
     *
     * @throws ValidationException when a placeholder has no variable
     */
    public function render(CommunicationTemplate $template, array $variables): array
    {
        $needed = array_values(array_unique([...$this->placeholders((string) $template->subject), ...$this->placeholders($template->body)]));
        $missing = array_values(array_filter($needed, static fn (string $name): bool => ! array_key_exists($name, $variables)));

        if ($missing !== []) {
            throw ValidationException::withMessages(['variables' => 'Missing variables: '.implode(', ', $missing).'.']);
        }

        $fill = static fn (string $text, bool $singleLine): string => (string) preg_replace_callback(
            self::Placeholder,
            static function (array $match) use ($variables, $singleLine): string {
                $value = mb_substr((string) $variables[$match[1]], 0, self::MaxValueLength);

                return $singleLine ? trim((string) preg_replace('/[\r\n]+/', ' ', $value)) : $value;
            },
            $text
        );

        return [
            'subject' => $template->subject === null ? null : $fill($template->subject, true),
            'body' => $fill($template->body, false),
        ];
    }
}
